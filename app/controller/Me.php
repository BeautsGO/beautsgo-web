<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AuthService;

/**
 * 我的 —— /{lang}/me  /me/profile  /me/collect  /me/order  /me/news  /me/feedback
 * 所有路由用 AuthMiddleware 守卫
 */
class Me extends BaseController
{
    public function index()
    {
        $auth = new AuthService();
        // 一次性拿 user/info + 各订单状态计数 + jfShow(对齐原 me.vue 的 GET /user/info)
        $info = $auth->call('GET', '/user/info');
        $data = (array) ($info['data'] ?? []);
        $user = (array) ($data['user'] ?? $auth->getCurrentUser());

        // 兜底另外拉(老接口偶尔不返回这几个)
        if (empty($data['order'])) {
            $reminds = $auth->call('GET', '/user/remindNum');
            $data['order'] = (array) ($reminds['data'] ?? []);
        }
        $orderCnt = (array) ($data['order'] ?? []);
        $point   = (int) ($data['point'] ?? $user['point'] ?? 0);
        $balance = (float) ($data['wallet_price'] ?? $user['wallet_price'] ?? 0);
        $message = (int) ($data['message'] ?? 0);
        $jfShow  = (bool) ($data['jfShow'] ?? false);

        $this->trackPage('个人中心', (int) ($user['id'] ?? 0));
        $this->seo->setTdk('我的 - BeautsGO', '个人中心', '我的')
            ->setCanonical((string) config('seo.site_url') . '/' . $this->langSeg() . '/me')
            ->buildOrganization();

        return $this->render('pages/me/index', [
            'user'    => $user,
            'point'   => $point,
            'balance' => $balance,
            'message' => $message,
            'jfShow'  => $jfShow,
            'order'   => [
                'pay'     => (int) ($orderCnt['uPay']    ?? $orderCnt['pay']    ?? 0),
                'booking' => (int) ($orderCnt['booking'] ?? 0),
                'booked'  => (int) ($orderCnt['booked']  ?? 0),
                'refund'  => (int) ($orderCnt['refund']  ?? 0),
            ],
        ]);
    }

    /**
     * 个人资料(对齐 subPackages_lightningRod/me/user.vue)
     *   GET  /user/baseInfo  → user + form_info(动态字段:生日/性别等)
     *   POST /user/save      → user + form_info 合并保存
     */
    public function profile()
    {
        $auth = new AuthService();

        // 拉 baseInfo:user 基础信息 + form_info 动态字段
        $base = $auth->call('GET', '/user/baseInfo');
        $user = (array) ($base['data']['user'] ?? $auth->getCurrentUser());
        $initForm = (array) ($base['data']['form_info'] ?? []);

        $error = '';
        $saved = false;
        if ($this->request->isPost()) {
            // user 基础字段
            $payload = [
                'nickname' => trim((string) $this->request->param('nickname', '')),
                'avatar'   => (string) $this->request->param('avatar', $user['avatar'] ?? ''),
                'sex'      => (int) $this->request->param('sex', $user['sex'] ?? 0),
            ];
            // 动态字段(initForm 项 form[key]=value)
            $extra = (array) $this->request->param('form', []);
            foreach ($extra as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                $payload[$k] = is_array($v) ? '' : (string) $v;
            }
            $resp = $auth->call('POST', '/user/save', $payload);
            if ($resp['ok']) {
                $saved = true;
                $user = array_merge((array) $user, $payload);
                // 把新值回填 initForm 显示
                foreach ($initForm as &$it) {
                    $k = $it['key'] ?? '';
                    if ($k && isset($payload[$k])) $it['value'] = $payload[$k];
                }
            } else {
                $error = $resp['msg'] ?: '保存失败';
            }
        }

        $this->seo->setTdk('个人资料 - BeautsGO', '个人资料', '我的,资料')->buildOrganization();
        return $this->render('pages/me/profile', [
            'user'        => $user,
            'initForm'    => $initForm,
            'maskedPhone' => $this->maskPhone((string) ($user['phone'] ?? '')),
            'error'       => $error,
            'saved'       => $saved,
        ]);
    }

    /**
     * 手机号掩码(对齐 user.vue maskPhoneNumber)
     */
    private function maskPhone(string $phone): string
    {
        if ($phone === '') return '';
        if (strlen($phone) >= 11) return preg_replace('/^(\d{3})\d{4}(\d{4})/', '$1****$2', $phone);
        if (strlen($phone) >= 7)  return preg_replace('/^(\d{3})\d+(\d{2})$/', '$1***$2', $phone);
        return $phone;
    }

    public function collect()
    {
        $auth = new AuthService();
        $type = max(1, min(3, (int) $this->request->param('type', 1)));  // 1 医院 2 医生 3 项目
        $page = max(1, (int) $this->request->param('page', 1));

        $resp = $auth->call('GET', '/Collect/myCollect', [
            'collect_type' => $type,
            'page'         => $page,
            'limit'        => 10,
        ]);
        $list  = (array) ($resp['data']['list'] ?? $resp['data'] ?? []);
        $total = (int) ($resp['data']['count'] ?? count($list));
        $totalPages = max(1, (int) ceil($total / 10));

        $this->seo->setTdk('我的收藏 - BeautsGO', '我的收藏', '我的收藏')->buildOrganization();
        return $this->render('pages/me/collect', [
            'user'         => $auth->getCurrentUser(),
            'type'         => $type,
            'list'         => $list,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'filterParams' => ['area' => 0, 'level' => 0, 'category' => 0],
            'tab'          => 0,
        ]);
    }

    public function order()
    {
        $auth = new AuthService();
        $status = (int) $this->request->param('status', 0);
        $page   = max(1, (int) $this->request->param('page', 1));

        $resp = $auth->call('GET', '/Order/pageList', [
            'status' => $status,
            'page'   => $page,
            'limit'  => 10,
        ]);
        $list  = (array) ($resp['data']['list'] ?? $resp['data'] ?? []);
        $total = (int) ($resp['data']['count'] ?? count($list));
        $totalPages = max(1, (int) ceil($total / 10));

        $this->seo->setTdk('我的订单 - BeautsGO', '我的订单', '订单')->buildOrganization();
        return $this->render('pages/me/order', [
            'user'         => $auth->getCurrentUser(),
            'status'       => $status,
            'list'         => $list,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'filterParams' => ['status' => $status, 'area' => 0, 'level' => 0, 'category' => 0],
            'tab'          => 0,
        ]);
    }

    public function news()
    {
        $auth = new AuthService();
        $page = max(1, (int) $this->request->param('page', 1));
        $resp = $auth->call('GET', '/message/myMessage', ['page' => $page, 'limit' => 20]);
        $list  = (array) ($resp['data']['list'] ?? $resp['data'] ?? []);
        $total = (int) ($resp['data']['count'] ?? count($list));

        $this->seo->setTdk('消息中心 - BeautsGO', '消息中心', '消息')->buildOrganization();
        return $this->render('pages/me/news', [
            'user'         => $auth->getCurrentUser(),
            'list'         => $list,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => max(1, (int) ceil($total / 20)),
            'filterParams' => ['area' => 0, 'level' => 0, 'category' => 0],
            'tab'          => 0,
        ]);
    }

    public function record()
    {
        $auth = new AuthService();
        $type = max(1, min(3, (int) $this->request->param('type', 1)));
        $page = max(1, (int) $this->request->param('page', 1));
        $resp = $auth->call('GET', '/Browse/myBrowse', ['type' => $type, 'page' => $page, 'limit' => 10]);
        $list  = (array) ($resp['data']['list'] ?? $resp['data'] ?? []);
        $total = (int) ($resp['data']['count'] ?? count($list));

        $this->seo->setTdk('浏览记录 - BeautsGO', '我浏览过的医院/医生/项目', '浏览记录')->buildOrganization();
        return $this->render('pages/me/record', [
            'user'         => $auth->getCurrentUser(),
            'type'         => $type,
            'list'         => $list,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => max(1, (int) ceil($total / 10)),
            'filterParams' => ['area' => 0, 'level' => 0, 'category' => 0],
            'tab'          => 0,
        ]);
    }

    public function feedback()
    {
        $auth = new AuthService();
        $error = '';
        $saved = false;
        if ($this->request->isPost()) {
            $payload = [
                'content' => trim((string) $this->request->param('content', '')),
                'phone'   => trim((string) $this->request->param('phone', '')),
                'type'    => (int) $this->request->param('type', 1),
            ];
            if ($payload['content'] === '') {
                $error = '请输入反馈内容';
            } else {
                $resp = $auth->call('POST', '/feedback/save', $payload);
                if ($resp['ok']) $saved = true;
                else $error = $resp['msg'] ?: '提交失败';
            }
        }
        $this->seo->setTdk('意见反馈 - BeautsGO', '提交反馈', '反馈')->buildOrganization();
        return $this->render('pages/me/feedback', [
            'user'  => $auth->getCurrentUser(),
            'error' => $error,
            'saved' => $saved,
        ]);
    }

    public function wallet()
    {
        $auth = new AuthService();
        $type = max(0, min(2, (int) $this->request->param('type', 0)));  // 0 全部 1 收入 2 支出
        $page = max(1, (int) $this->request->param('page', 1));
        $balance = $auth->call('GET', '/user/getWalletPrice', []);
        $log     = $auth->call('GET', '/user/walletLogList/' . $type, ['page' => $page, 'limit' => 20]);
        $list  = (array) ($log['data']['list'] ?? $log['data'] ?? []);
        $total = (int) ($log['data']['count'] ?? count($list));

        $this->seo->setTdk('我的钱包 - BeautsGO', '我的钱包', '钱包')->buildOrganization();
        return $this->render('pages/me/wallet', [
            'user'         => $auth->getCurrentUser(),
            'balance'      => (float) ($balance['data']['wallet_price'] ?? $balance['data'] ?? 0),
            'type'         => $type,
            'list'         => $list,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => max(1, (int) ceil($total / 20)),
            'filterParams' => ['type' => $type, 'area' => 0, 'level' => 0, 'category' => 0],
            'tab'          => 0,
        ]);
    }

    public function pointsLog()
    {
        $auth = new AuthService();
        $type = max(0, min(2, (int) $this->request->param('type', 0)));
        $page = max(1, (int) $this->request->param('page', 1));
        $log = $auth->call('GET', '/user/pointList/' . $type, ['page' => $page, 'limit' => 20]);
        $list  = (array) ($log['data']['list'] ?? $log['data'] ?? []);
        $total = (int) ($log['data']['count'] ?? count($list));
        $point = $auth->call('GET', '/user/getPoint', []);

        $this->seo->setTdk('积分明细 - BeautsGO', '积分流水', '积分明细')->buildOrganization();
        return $this->render('pages/me/points-log', [
            'user'         => $auth->getCurrentUser(),
            'point'        => (int) ($point['data']['point'] ?? $point['data'] ?? 0),
            'type'         => $type,
            'list'         => $list,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => max(1, (int) ceil($total / 20)),
            'filterParams' => ['type' => $type, 'area' => 0, 'level' => 0, 'category' => 0],
            'tab'          => 0,
        ]);
    }

    /**
     * 系统消息详情(Message/detail/:id)
     */
    public function newsDetail(int $id = 0)
    {
        if (!$id) $this->abort404('Missing message id');
        $auth = new AuthService();
        $resp = $auth->call('GET', '/Message/detail/' . $id);
        $info = (array) ($resp['data'] ?? []);
        if (empty($info)) $this->abort404('Message not found');

        $title = $info['title'] ?? '系统消息';
        $this->seo->setTdk($title . ' - BeautsGO', '系统消息详情', '消息')->buildOrganization();
        return $this->render('pages/me/news-detail', [
            'user' => $auth->getCurrentUser(),
            'info' => $info,
        ]);
    }

    /**
     * 订单详情 — beauts_app/order/detail.vue
     *   GET /Appointment/detail/:id
     */
    public function orderDetail(int $id = 0)
    {
        if (!$id) $this->abort404('Missing order id');
        $auth = new AuthService();
        $resp = $auth->call('GET', '/Appointment/detail/' . $id);
        $info = (array) ($resp['data'] ?? []);
        if (empty($info)) $this->abort404('Order not found');

        // 客服二维码(同 addkefu)
        $cfg = $this->api->get('/getConfig');
        $cs  = (array) ($cfg['data']['platformConfig']['customer_service'] ?? []);
        $wxCode = $cs[0] ?? '';

        $this->seo->setTdk('订单详情 - BeautsGO', '订单详情', '订单')->buildOrganization();
        return $this->render('pages/me/order-detail', [
            'user'   => $auth->getCurrentUser(),
            'info'   => $info,
            'wxCode' => $wxCode,
        ]);
    }

    public function settings()
    {
        $auth = new AuthService();
        $this->seo->setTdk('设置 - BeautsGO', '账户设置', '设置')->buildOrganization();
        return $this->render('pages/me/settings', [
            'user' => $auth->getCurrentUser(),
        ]);
    }

    private function langSeg(): string
    {
        return (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
    }
}
