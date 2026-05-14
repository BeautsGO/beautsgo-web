<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ApiClient;
use app\service\SeoService;
use think\App;
use think\Request;
use think\exception\HttpResponseException;

/**
 * 控制器基类
 *   - 注入 ApiClient / SeoService
 *   - 解析当前语言
 *   - 提供 render() 把 SEO 元数据自动塞进模板变量
 */
abstract class BaseController
{
    /** @var App */
    protected $app;
    /** @var Request */
    protected $request;
    /** @var ApiClient */
    protected $api;
    /** @var SeoService */
    protected $seo;
    /** @var string  zh-Hans / zh-Hant / en / ja / th */
    protected $lang;

    /**
     * 浏览轨迹埋点元数据 —— 控制器可调 trackPage() 覆盖默认值
     *   字段对齐 beauts_app/utils/common.js anchorPoint() 签名
     *   {type:1, name:'page_name', with_id:0, fun_type:'浏览', action_name:'进入XX页'}
     * @var array
     */
    protected $tracking = [
        'type'        => 1,
        'name'        => '',
        'with_id'     => 0,
        'fun_type'    => '浏览',
        'action_name' => '',
    ];

    /**
     * 设置当前页埋点元数据(由各 controller 调用)
     */
    protected function trackPage(string $pageName, int $withId = 0, string $funType = '浏览', string $action = ''): void
    {
        $this->tracking = [
            'type'        => 1,
            'name'        => $pageName,
            'with_id'     => $withId,
            'fun_type'    => $funType,
            'action_name' => $action ?: ('进入' . $pageName),
        ];
    }

    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $app->request;
        $this->api     = new ApiClient();
        $this->lang    = $this->resolveLang();
        $this->seo     = new SeoService($this->lang);

        // UTM / share 归因:URL 上有就写 Cookie(Last-Click,30 天)
        $this->captureAttribution();

        // 静默登录(对齐 App.vue:73 appLogin):无 token 即调 /login/appLogin
        // 拿游客身份 → 让埋点/收藏/聊天等业务接口能带 token
        $this->ensureSilentLogin();
    }

    /**
     * 静默登录 — 对齐 beauts_app App.vue 的 appLogin()。
     * 用户首次访问、没 beauts_token cookie 时,POST /login/appLogin 拿游客 token。
     * device_id 用持久化 cookie(没有则生成 UUID),让同浏览器多次访问视为同一游客。
     */
    private function ensureSilentLogin(): void
    {
        // 已登录就不动
        if (!empty($_COOKIE['beauts_token'])) return;

        // 不在文档型请求里跑(AJAX/资源不需要)— 简单按 sec-fetch-dest 区分
        $dest = (string) $this->request->header('sec-fetch-dest', 'document');
        if ($dest !== 'document' && $dest !== '') return;

        // device_id 持久化 cookie(1 年)
        $deviceId = (string) ($_COOKIE['bg_device_id'] ?? '');
        if ($deviceId === '') {
            $deviceId = $this->generateDeviceId();
            \think\facade\Cookie::set('bg_device_id', $deviceId, [
                'expire' => 86400 * 365, 'path' => '/', 'samesite' => 'Lax',
                'secure' => $this->request->isSsl(), 'httponly' => false,
            ]);
            $_COOKIE['bg_device_id'] = $deviceId;
        }

        // utm/share 5 件套(从 cookie 取,login.vue:1011 同源逻辑)
        $attribution = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'shareId', 'shareType'] as $k) {
            $v = (string) ($_COOKIE[$k] ?? '');
            if ($v !== '') $attribution[$k] = $v;
        }

        // fire-and-forget;失败不阻塞页面渲染(2 秒短超时)
        try {
            (new \app\service\AuthService())->silentLogin($deviceId, $attribution);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[silentLogin] ' . $e->getMessage());
        }
    }

    private function generateDeviceId(): string
    {
        // 32 hex,与 web 端 UUID 等价的标识(不需要严格 UUID 格式,后端只当字符串)
        try { return bin2hex(random_bytes(16)); }
        catch (\Throwable $e) { return md5(uniqid('bg', true) . microtime(true)); }
    }

    /**
     * 把 ?shareId & shareType & utm_source/medium/campaign 写到 Cookie。
     * Last-Click 归因:后到的覆盖旧的(与 beauts_app/App.vue:38-48 一致)。
     * AuthService::call() 调登录/留电接口时会从 cookie 读出来塞 payload。
     */
    private function captureAttribution(): void
    {
        $keys = ['shareId', 'shareType', 'utm_source', 'utm_medium', 'utm_campaign'];
        $ttl  = 86400 * 30;  // 30 天
        foreach ($keys as $k) {
            $v = $this->request->param($k, '');
            if ($v === '' || $v === null) continue;
            $v = (string) $v;
            if (in_array($k, ['shareId', 'shareType'], true)) {
                if (!preg_match('/^\d+$/', $v)) continue;
            } else {
                if (!preg_match('/^[A-Za-z0-9_\-\.]{1,64}$/', $v)) continue;
            }
            \think\facade\Cookie::set($k, $v, [
                'expire'   => $ttl,
                'path'     => '/',
                'samesite' => 'Lax',
                'secure'   => $this->request->isSsl(),
                'httponly' => false,  // 让前端 JS 也能读(埋点要用)
            ]);
            // 当次请求内立即可读
            $_COOKIE[$k] = $v;
        }
    }

    /**
     * 默认 view 数据 —— 所有页面（含错误页）都必须有的字段
     */
    private function getDefaultViewData(): array
    {
        // 翻译表 —— 三层 fallback 链:
        //   1) 后端 /api/getLanguageConfig 动态(权威,缓存 10min)
        //   2) config/lang.<current> 静态
        //   3) config/lang.zh-Hans 兜底
        $remote   = $this->fetchRemoteLang($this->lang);
        $primary  = (array) (config('lang.' . $this->lang) ?: []);
        $fallback = (array) (config('lang.zh-Hans') ?: []);
        $tt = array_merge($fallback, $primary, $remote);

        // page_name 兜底:如未 trackPage 则用 SEO title 截断
        $tracking = $this->tracking;
        if (empty($tracking['name'])) {
            $tracking['name'] = mb_substr((string) $this->seo->toArray()['title'] ?? '', 0, 60);
        }
        if (empty($tracking['action_name'])) {
            $tracking['action_name'] = '进入' . ($tracking['name'] ?: '页面');
        }

        return [
            'seo'             => $this->seo->toArray(),
            'assets'          => config('seo.assets'),
            'lang'            => $this->lang,
            'lang_seg'        => (config('seo.lang_path_map')[$this->lang] ?? 'cn'),
            'breadcrumb_i18n' => $this->seo->getBreadcrumbI18n(),
            'tt'              => $tt,
            'tracking'        => $tracking,
            'apiBaseUrl'      => rtrim((string) config('api.base_url'), '/'),
            'tabbar_active'   => $this->resolveTabbarActive(),
        ];
    }

    /**
     * 判断底部 tabbar 当前应该高亮哪一项
     *   home / hospital / doctor / me / point
     */
    private function resolveTabbarActive(): string
    {
        $path = '/' . trim((string) $this->request->pathinfo(), '/');
        // 去掉语言前缀 (cn/zh/en/ja/th)
        $path = preg_replace('#^/(cn|zh|en|ja|th)(?=/|$)#', '', $path);
        $path = $path === '' ? '/' : $path;

        if ($path === '/' || $path === '') return 'home';
        if (preg_match('#^/hospital(/|$)#', $path)) return 'hospital';
        if (preg_match('#^/doctor(/|$)#', $path))   return 'doctor';
        if (preg_match('#^/me(/|$)#', $path))       return 'me';
        if (preg_match('#^/point(/|$)#', $path))    return 'point';
        return '';
    }

    /**
     * 调后端 getLanguageConfig 拉一次,缓存 10 min。
     * 失败不抛,只记录日志,完全回落到本地 lang.php。
     */
    private function fetchRemoteLang(string $lang): array
    {
        $cacheKey = 'remote_lang:' . $lang;
        $cached = \think\facade\Cache::get($cacheKey);
        if (is_array($cached)) return $cached;

        try {
            $url = rtrim((string) config('api.base_url'), '/')
                 . '/getLanguageConfig?lang=' . urlencode($lang);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            if ($body) {
                $json = json_decode((string) $body, true);
                $data = is_array($json) ? (array) ($json['data'] ?? $json) : [];
                if ($data) {
                    \think\facade\Cache::set($cacheKey, $data, 600);
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[i18n] getLanguageConfig failed: ' . $e->getMessage());
        }
        // 失败仍 cache 一个空数组 1 分钟,避免高频重试拖慢请求
        \think\facade\Cache::set($cacheKey, [], 60);
        return [];
    }

    /**
     * 渲染页面 —— 自动注入 SEO 元数据 + 静态资源版本号 + 当前语言
     *
     * @param string $template 模板路径，例：pages/hospital/detail
     * @param array  $data     业务数据
     * @return \think\response\View
     */
    protected function render(string $template, array $data = [])
    {
        // 未显式设置 hreflang 时,按当前路径自动生成 5 种语言 + x-default
        $current = $this->seo->toArray();
        if (empty($current['hreflang'])) {
            $this->seo->setHreflang($this->buildAutoHreflang());
        }
        return view($template, array_merge($this->getDefaultViewData(), $data));
    }

    /**
     * 根据当前请求 path 推导多语言 alternate URL
     *   /cn/hospital/x → cn/zh/en/ja/th + x-default(英文)
     */
    private function buildAutoHreflang(): array
    {
        $segMap = (array) config('seo.lang_path_map');   // ['zh-Hans'=>'cn', 'en'=>'en', ...]
        $tagMap = (array) config('seo.lang_tag_map');    // ['zh-Hans'=>'zh-CN', ...]
        $base   = rtrim((string) config('seo.site_url'), '/');

        // 当前 pathinfo: "cn/hospital/x" / "cn" / "" 等
        $path = (string) $this->request->pathinfo();
        $segs = $path === '' ? [] : explode('/', $path);

        // 去掉首段 lang(如果是已知 lang seg)
        $knownSegs = array_flip(array_values($segMap));
        if (!empty($segs) && isset($knownSegs[$segs[0]])) {
            array_shift($segs);
        }
        $tail = implode('/', $segs);

        $alts = [];
        foreach ($segMap as $langKey => $seg) {
            $tag = $tagMap[$langKey] ?? $seg;
            $url = $base . '/' . $seg . ($tail !== '' ? '/' . $tail : '');
            $alts[$tag] = $url;
        }
        // x-default 用英文版(国际通行做法)
        $alts['x-default'] = $base . '/' . ($segMap['en'] ?? 'en') . ($tail !== '' ? '/' . $tail : '');
        return $alts;
    }

    /**
     * 触发 404 —— 通过抛 HttpResponseException 直接终止
     */
    protected function abort404(string $msg = 'Page Not Found')
    {
        // 即使 404 页面也要有基础 SEO，否则 base.html 缺变量会再次抛错
        $this->seo->setTdk('页面不存在 - 404', 'The requested page could not be found.');

        $data = array_merge($this->getDefaultViewData(), ['msg' => $msg]);
        $resp = view('pages/error/404', $data)->code(404);
        throw new HttpResponseException($resp);
    }

    /**
     * 解析当前语言
     *   优先级：URL :lang 段 > Cookie language > Accept-Language > 默认 zh-Hans
     */
    protected function resolveLang(): string
    {
        $segMap = (array) config('seo.lang_segment_map');

        $segment = $this->request->param('lang', '');
        if ($segment && isset($segMap[$segment])) {
            return $segMap[$segment];
        }

        $cookie = $this->request->cookie('language', '');
        if ($cookie && in_array($cookie, $segMap, true)) {
            return $cookie;
        }

        $accept = strtolower($this->request->header('Accept-Language', ''));
        if (str_starts_with($accept, 'zh-tw') || str_starts_with($accept, 'zh-hant')) return 'zh-Hant';
        if (str_starts_with($accept, 'ja'))     return 'ja';
        if (str_starts_with($accept, 'th'))     return 'th';
        if (str_starts_with($accept, 'en'))     return 'en';

        return (string) config('seo.default_lang', 'zh-Hans');
    }

    /**
     * 后端字段多语言前缀
     *   与后端 services/BaseServices 里的 FIELD_LANG_PREFIX 对应
     */
    protected function fieldLangPrefix(): string
    {
        switch ($this->lang) {
            case 'zh-Hant': return 'zh_hant_';
            case 'en':      return 'en_';
            case 'ja':      return 'ja_';
            case 'th':      return 'th_';
            default:        return '';  // zh-Hans 不加前缀
        }
    }
}
