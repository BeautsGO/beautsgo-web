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

        $this->seo
            ->setTdk('登录 - BeautsGO', '登录 BeautsGO 韩国医美预约平台', '登录,韩国医美')
            ->setCanonical((string) config('seo.site_url') . '/' . $this->langSeg() . '/login')
            ->buildOrganization();
        $this->trackPage('登录页', 0, '登录');

        return $this->render('pages/auth/login', [
            'back'  => (string) $this->request->param('back', ''),
            'error' => '',
            'phone' => '',
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
        // 基础校验:6-15 位数字
        if (!preg_match('/^\d{6,15}$/', $phone)) {
            return json(['ok' => false, 'msg' => '手机号格式不正确']);
        }

        $resp = (new AuthService())->call('POST', '/common/getSmsCode', [
            'phone'      => $phone,
            'country_id' => $countryId,
        ]);
        return json([
            'ok'  => $resp['ok'],
            'msg' => $resp['msg'] ?: ($resp['ok'] ? '验证码已发送' : '发送失败'),
        ]);
    }

    /**
     * 手机号 + 验证码登录
     *   后端:POST /api/login/appBindPhone  (兼容旧版,直传 phone+code+country_id)
     *   utm/share 由 AuthService::call 自动从 cookie 注入
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
            ]);
        }

        $auth = new AuthService();
        $resp = $auth->call('POST', '/login/appBindPhone', [
            'phone'      => $phone,
            'code'       => $code,
            'country_id' => $countryId,
        ]);

        if (!$resp['ok']) {
            return $this->render('pages/auth/login', [
                'back'  => $back, 'phone' => $phone,
                'error' => $resp['msg'] ?: '登录失败',
            ]);
        }
        // 拿到 token 写 cookie
        $token = (string) ($resp['data']['ApiUniAuth'] ?? $resp['data']['token'] ?? '');
        if ($token !== '') {
            Cookie::set('beauts_token', $token, [
                'expire'   => 86400 * 7,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return redirect($back ?: '/' . $this->langSeg() . '/me');
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
