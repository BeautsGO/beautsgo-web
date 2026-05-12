<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\AuthService;
use think\Request;
use think\Response;

/**
 * 用户态路由中间件 —— 未登录跳 /cn/login?back=
 *
 * 在路由层声明:
 *   Route::get('me', 'Me/index')->middleware(\app\middleware\AuthMiddleware::class);
 */
class AuthMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        $auth = new AuthService();
        if (!$auth->isLoggedIn()) {
            $back = urlencode($request->url(true));
            $lang = $this->detectLangSeg($request);
            return redirect('/' . $lang . '/login?back=' . $back);
        }
        // 注入当前用户到 request
        $request->user = $auth->getCurrentUser();
        return $next($request);
    }

    private function detectLangSeg(Request $request): string
    {
        $seg = $request->param('lang', '');
        $map = (array) config('seo.lang_segment_map');
        return $seg && isset($map[$seg]) ? $seg : 'cn';
    }
}
