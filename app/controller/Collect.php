<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AuthService;

/**
 * 收藏代理(1:1 对齐 detail.vue:1151 collection())
 *   POST /{lang}/collect  body: type=1|2|3, with_id=N, is_collect=0|1
 *   透传后端 POST /api/Collect/doCollect
 *
 * 静默登录拿到的 beauts_token cookie 由 AuthService::call 自动带上。
 */
class Collect extends BaseController
{
    public function toggle()
    {
        $type    = (int) $this->request->param('type', 0);
        $withId  = (int) $this->request->param('with_id', 0);
        $isColl  = (int) $this->request->param('is_collect', 1);

        if (!in_array($type, [1, 2, 3], true) || $withId <= 0) {
            return json(['ok' => false, 'msg' => '参数错误']);
        }

        $resp = (new AuthService())->call('POST', '/Collect/doCollect', [
            'with_id'      => $withId,
            'collect_type' => $type,
            'is_collect'   => $isColl,
        ]);
        return json([
            'ok'   => $resp['ok'],
            'msg'  => $resp['msg'] ?: ($resp['ok'] ? ($isColl ? '已收藏' : '已取消') : '操作失败'),
            'data' => ['is_collect' => $isColl],
        ]);
    }
}
