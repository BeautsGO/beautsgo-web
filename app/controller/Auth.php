<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AuthService;
use think\facade\Cookie;

/**
 * 登录 / 退出 / 短信验证码
 *   GET  /{lang}/login              显示登录页(1:1 还原 pages/login/login.vue)
 *   POST /{lang}/login/send-code    发送短信验证码 (AJAX, JSON 返回)
 *   POST /{lang}/login              手机号 + 验证码登录(调 /api/login/appBindPhone)
 *   GET  /{lang}/logout             退出
 */
class Auth extends BaseController
{
    public function loginPage()
    {
        $auth = new AuthService();
        if ($auth->isLoggedIn()) {
            $back = (string) $this->request->param('back', '/' . $this->langSeg() . '/me');
            return redirect($back);
        }

        // 1:1 对齐 login.vue:810 getCountryList():$http.get('Common/countryList')
        $countryList = $this->fetchCountryList();
        $defaultCountry = $this->defaultCountryFallback();
        foreach ($countryList as $c) {
            if ((int) ($c['id'] ?? 0) === 1) { $defaultCountry = $c; break; }
        }

        $this->seo
            ->setTdk('登录 - BeautsGO', '登录 BeautsGO 韩国医美预约平台', '登录,韩国医美')
            ->setCanonical((string) config('seo.site_url') . '/' . $this->langSeg() . '/login')
            ->buildOrganization();
        $this->trackPage('登录页', 0, '登录');

        return $this->render('pages/auth/login', [
            'back'            => (string) $this->request->param('back', ''),
            'error'           => '',
            'phone'           => '',
            'countryList'     => $countryList,
            'defaultCountry'  => $defaultCountry,
        ]);
    }

    /**
     * 发送短信验证码 (AJAX)
     *   POST /{lang}/login/send-code  body: phone, country_id
     *   后端:POST /api/common/getSmsCode
     */
    public function sendCode()
    {
        $phone     = trim((string) $this->request->param('phone', ''));
        $countryId = (int) $this->request->param('country_id', 0);

        if ($phone === '') {
            return json(['ok' => false, 'msg' => '请输入手机号']);
        }
        if (!preg_match('/^\d{6,15}$/', $phone)) {
            return json(['ok' => false, 'msg' => '手机号格式不正确']);
        }

        $resp = (new AuthService())->sendSmsCode($phone, $countryId);
        return json([
            'ok'  => $resp['ok'],
            'msg' => $resp['msg'] ?: ($resp['ok'] ? '验证码已发送' : '发送失败'),
        ]);
    }

    /**
     * 手机号 + 验证码登录(对齐 login.vue:1007 appLogin)
     *   POST /api/login/bindPhone  body: phone, code, country_id, utm_*, shareId, shareType
     *   utm/share 由 AuthService 自动从 cookie 注入(命中 /login/bindPhone)
     */
    public function submitLogin()
    {
        $phone     = trim((string) $this->request->param('phone', ''));
        $code      = trim((string) $this->request->param('code', ''));
        $countryId = (int) $this->request->param('country_id', 0);
        $back      = (string) $this->request->param('back', '/' . $this->langSeg() . '/me');

        if ($phone === '' || $code === '') {
            return $this->render('pages/auth/login', [
                'back'  => $back, 'phone' => $phone,
                'error' => '请填写手机号和验证码',
                'countryList'    => $this->fetchCountryList(),
                'defaultCountry' => $this->defaultCountryFallback(),
            ]);
        }

        // utm/share 5 字段从 cookie 取(login.vue 原是从 Storage 取,SSR 端等价从 Cookie)
        $attribution = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'shareId', 'shareType'] as $k) {
            $v = (string) ($_COOKIE[$k] ?? '');
            if ($v !== '') $attribution[$k] = $v;
        }

        $auth = new AuthService();
        $resp = $auth->phoneLogin($phone, $code, $countryId, $attribution);

        if (!$resp['ok']) {
            return $this->render('pages/auth/login', [
                'back'  => $back, 'phone' => $phone,
                'error' => $resp['msg'] ?: '登录失败',
                'countryList'    => $this->fetchCountryList(),
                'defaultCountry' => $this->defaultCountryFallback(),
            ]);
        }
        return redirect($back ?: '/' . $this->langSeg() . '/me');
    }

    private function fetchCountryList(): array
    {
        try {
            $resp = (new AuthService())->call('GET', '/Common/countryList');
            if (!empty($resp['ok'])) {
                return (array) ($resp['data']['all'] ?? []);
            }
        } catch (\Throwable $e) {}
        return [];
    }

    private function defaultCountryFallback(): array
    {
        return [
            'id' => 1, 'dial' => '86',
            'name_cn' => '中国', 'native_language' => '中国',
            'national_flag' => 'https://beautsgoimg.59w.net/country_ico/cn.svg',
        ];
    }

    public function logout()
    {
        (new AuthService())->logout();
        return redirect('/' . $this->langSeg() . '/');
    }

    private function langSeg(): string
    {
        return (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
    }
}
