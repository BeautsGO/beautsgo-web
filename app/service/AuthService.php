<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cookie;
use think\facade\Cache;
use think\facade\Log;

/**
 * 认证服务 —— 封装 beautsgo_api 的登录/退出/用户信息接口
 *
 * Token 机制:
 *   - 后端登录返回 `ApiUniAuth` 字段
 *   - SSR 端存到 httpOnly cookie `beauts_token`(7 天)
 *   - 所有需要登录的 API 请求,在 Authorization header 带 `Bearer {token}` 或自定义 header
 *   - 与 UniApp H5 共享同域 cookie(后续如果需要)
 */
class AuthService
{
    private const COOKIE_NAME    = 'beauts_token';
    private const COOKIE_LIFETIME = 86400 * 7;  // 7 天
    private const CACHE_KEY_USER  = 'user_info:';
    // 1:1 来源:beauts_app/pages/login/login.vue:986 sendVerificationCode 用的 secret
    private const SMS_SIGN_KEY    = 'NwRxcB9yhzTlU3R6qR1f9Yv2JG';

    /** @var string */
    private $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('api.base_url'), '/');
    }

    /* ============================================================
     *  短信验证码(对齐 beauts_app login.vue:976 sendVerificationCode)
     *    POST /common/getSmsCode  body: phone, country_id, sign, timestamp, from_source
     * ============================================================ */
    public function sendSmsCode(string $phone, int $countryId): array
    {
        $timestamp = time();
        $params = [
            'phone'       => $phone,
            'country_id'  => $countryId,
            'timestamp'   => $timestamp,
            'from_source' => self::fromSource(),
        ];
        $params['sign'] = self::md5SignEncrypt($params, self::SMS_SIGN_KEY);
        return $this->request('POST', '/common/getSmsCode', $params);
    }

    /* ============================================================
     *  手机号 + 验证码登录(对齐 login.vue:1007 appLogin)
     *    POST /login/bindPhone
     * ============================================================ */
    public function phoneLogin(string $phone, string $code, int $countryId, array $attribution = []): array
    {
        $payload = array_merge([
            'phone'      => $phone,
            'code'       => $code,
            'country_id' => $countryId,
        ], $attribution);
        $resp = $this->request('POST', '/login/bindPhone', $payload);
        if ($resp['ok']) {
            $token = (string) ($resp['data']['ApiUniAuth'] ?? $resp['data']['token'] ?? '');
            if ($token !== '') {
                $this->setToken($token);
                $user = (array) ($resp['data']['user'] ?? []);
                if ($user) Cache::set(self::CACHE_KEY_USER . $token, $user, 300);
            }
        }
        return $resp;
    }

    /* ============================================================
     *  静默登录(游客 token)— 对齐 App.vue appLogin
     *    POST /login/appLogin
     *    用于:用户进站时分配游客身份,登录前也能浏览/埋点
     * ============================================================ */
    public function silentLogin(string $deviceId, array $attribution = []): array
    {
        $payload = array_merge([
            'device_id'     => $deviceId,
            'push_clientid' => '',
        ], $attribution);
        $resp = $this->request('POST', '/login/appLogin', $payload);
        if ($resp['ok']) {
            $token = (string) ($resp['data']['ApiUniAuth'] ?? '');
            if ($token !== '') {
                $this->setToken($token);
            }
        }
        return $resp;
    }

    /* ============================================================
     *  MD5 签名(1:1 移植 utils/base.js:166 md5_encrypt_sign)
     *    1. 过滤 sign / 空字符串 / 数组
     *    2. key 字典序排序
     *    3. 拼接 key=val&key=val...
     *    4. HTML 实体转义(& → &amp; 等)
     *    5. md5(arg + appKey)
     * ============================================================ */
    public static function md5SignEncrypt(array $params, string $appKey = ''): string
    {
        $filter = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign') continue;
            if (is_array($v)) continue;
            if ($v === '' || $v === null) continue;
            if (is_int($v) || is_float($v)) $v = (string) $v;
            $filter[$k] = $v;
        }
        ksort($filter);
        $arg = '';
        foreach ($filter as $k => $v) $arg .= $k . '=' . $v . '&';
        $arg = rtrim($arg, '&');
        // 与 JS 端逐次链式 replace 等价(& 必须最先转,否则后续 &lt; 会被二次转)
        $arg = str_replace('&', '&amp;', $arg);
        $arg = str_replace('<', '&lt;',  $arg);
        $arg = str_replace('>', '&gt;',  $arg);
        $arg = str_replace('"', '&quot;', $arg);
        $arg = str_replace("'", '&#039;', $arg);
        return md5($arg . $appKey);
    }

    /**
     * from_source(对齐 utils/base.js:129)
     * SSR 端固定 'web'(浏览器,非微信内嵌)
     */
    public static function fromSource(): string
    {
        return 'web';
    }

    /* ============================================================
     *  发送邮箱验证码
     * ============================================================ */
    public function sendEmailCode(string $email): array
    {
        return $this->request('POST', '/user/email/sendActivation', [
            'email' => $email,
        ]);
    }

    /* ============================================================
     *  邮箱+验证码 登录(同时承担注册)
     * ============================================================ */
    public function emailLogin(string $email, string $code): array
    {
        $resp = $this->request('POST', '/login/emailLogin', [
            'email' => $email,
            'code'  => $code,
        ]);
        if ($resp['ok'] && !empty($resp['data']['ApiUniAuth'])) {
            $this->setToken((string) $resp['data']['ApiUniAuth']);
            $user = $resp['data']['user'] ?? [];
            if ($user) Cache::set(self::CACHE_KEY_USER . $resp['data']['ApiUniAuth'], $user, 300);
        }
        return $resp;
    }

    /* ============================================================
     *  退出登录(清 cookie + 缓存)
     * ============================================================ */
    public function logout(): void
    {
        $token = $this->getToken();
        if ($token) Cache::delete(self::CACHE_KEY_USER . $token);
        Cookie::delete(self::COOKIE_NAME);
    }

    /* ============================================================
     *  当前登录态
     * ============================================================ */
    public function getToken(): ?string
    {
        $t = Cookie::get(self::COOKIE_NAME);
        return $t ? (string) $t : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->getToken() !== null;
    }

    /**
     * 取当前用户基本信息(带缓存,5min)
     */
    public function getCurrentUser(): array
    {
        $token = $this->getToken();
        if (!$token) return [];

        $cacheKey = self::CACHE_KEY_USER . $token;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) return (array) $cached;

        $resp = $this->request('GET', '/user/baseInfo', [], $token);
        if ($resp['ok']) {
            $user = (array) ($resp['data'] ?? []);
            Cache::set($cacheKey, $user, 300);
            return $user;
        }
        if (in_array($resp['code'], [401, 403], true)) {
            $this->logout();  // token 失效清理
        }
        return [];
    }

    /**
     * 透传 GET/POST(已登录场景下带 token)
     *
     * 自动 UTM/share 归因:命中登录/留电类接口时,从 Cookie 取 5 个 last-click 值塞进 payload。
     * 后端 (LoginServices::doLogin / LoginBindPhoneServices::doBindPhone) 会写入
     * utm_visit_log + share_record。
     */
    public function call(string $method, string $path, array $params = []): array
    {
        $params = $this->attachAttribution($path, $params);
        return $this->request(strtoupper($method), $path, $params, $this->getToken());
    }

    /**
     * 命中登录/绑定手机/分享类接口时,把 cookie 里的 utm/share 5 件套合并进 payload。
     * 用户已显式传同名 key 的优先用用户传的(避免覆盖意图)。
     */
    private function attachAttribution(string $path, array $params): array
    {
        $needAttribution = [
            '/Login/appLogin',
            '/login/appLogin',
            '/LoginBindPhone/bindPhone',
            '/loginbindphone/bindphone',
            '/login/emailLogin',
        ];
        $normalized = '/' . ltrim($path, '/');
        $hit = false;
        foreach ($needAttribution as $p) {
            if (strcasecmp($normalized, $p) === 0) { $hit = true; break; }
        }
        if (!$hit) return $params;

        foreach (['shareId', 'shareType', 'utm_source', 'utm_medium', 'utm_campaign'] as $k) {
            if (isset($params[$k]) && $params[$k] !== '' && $params[$k] !== null) continue;
            $v = $_COOKIE[$k] ?? '';
            if ($v !== '') $params[$k] = $v;
        }
        return $params;
    }

    /* ============================================================
     *  Internal
     * ============================================================ */

    private function setToken(string $token): void
    {
        Cookie::set(self::COOKIE_NAME, $token, [
            'expire'   => self::COOKIE_LIFETIME,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function request(string $method, string $path, array $params = [], ?string $token = null): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($method === 'GET' && !empty($params)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
        }
        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'X-Client: beautsgo-ssr',
            // 后端 BaseApi 用 source-type header 判断登录源
            // (utils/base.js fromSource() 浏览器返回 'web')
            'source-type: web',
            'platform: web',
            'User-Agent: BeautsGO-SSR/1.0',
        ];
        if ($token) {
            $headers[] = 'ApiUniAuth: ' . $token;
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 500 || $err) {
            Log::error('[Auth] ' . $path . ' http=' . $code . ' err=' . $err);
            return ['ok' => false, 'code' => $code ?: 500, 'msg' => 'server error', 'data' => []];
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'code' => 500, 'msg' => 'invalid json', 'data' => []];
        }
        $apiCode = (int) ($json['code'] ?? 0);
        return [
            'ok'   => $apiCode === 0 || $apiCode === 200,
            'code' => $apiCode,
            'msg'  => (string) ($json['msg'] ?? ''),
            'data' => $json['data'] ?? [],
        ];
    }
}
