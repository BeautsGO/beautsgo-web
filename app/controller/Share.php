<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AuthService;

/**
 * 分享上报代理(1:1 对齐 detail.vue Share/save 调用)
 *   POST /{lang}/share  body: type=1|2|3, with_id=N
 *   透传后端 POST /api/Share/save  (with_id + collect_type)
 *
 * 用户点分享按钮 → JS 弹浮层同时 fire-and-forget POST 此端点。
 */
class Share extends BaseController
{
    public function save()
    {
        $type   = (int) $this->request->param('type', 0);
        $withId = (int) $this->request->param('with_id', 0);
        if (!in_array($type, [1, 2, 3], true) || $withId <= 0) {
            return json(['ok' => false, 'msg' => '参数错误']);
        }
        $resp = (new AuthService())->call('POST', '/Share/save', [
            'with_id'      => $withId,
            'collect_type' => $type,
        ]);
        return json(['ok' => $resp['ok'], 'msg' => $resp['msg'] ?: 'ok']);
    }
}
