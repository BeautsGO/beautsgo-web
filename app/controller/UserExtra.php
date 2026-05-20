<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AuthService;
use think\facade\Db;

/**
 * 用户态附加功能 —— 改手机号 / 我的评论 / 我的案例 / 写案例 / 分享 / 积分兑换
 *   /{lang}/me/change-phone
 *   /{lang}/me/comments
 *   /{lang}/me/cases
 *   /{lang}/me/cases/new
 *   /{lang}/share?type=N&id=N
 *   /{lang}/point/:id/redeem
 *   /{lang}/point/:id/confirm
 *   /{lang}/point/:id/success
 */
class UserExtra extends BaseController
{
    /* ============== 改手机号(对齐 changePhone.vue)============== */
    public function changePhone()
    {
        $auth = new AuthService();
        $user = $auth->getCurrentUser();
        $error = '';
        $saved = false;
        if ($this->request->isPost()) {
            // 对齐 changePhone.vue 字段名:phone / code / country_id
            $phone     = trim((string) $this->request->param('phone', ''));
            $code      = trim((string) $this->request->param('code', ''));
            $countryId = (int) $this->request->param('country_id', 0);

            if (!preg_match('/^\d{6,15}$/', $phone)) {
                $error = '请输入正确的手机号';
            } elseif ($code === '') {
                $error = '请输入验证码';
            } else {
                $resp = $auth->call('POST', '/login/changePhone', [
                    'phone'      => $phone,
                    'code'       => $code,
                    'country_id' => $countryId,
                ]);
                if ($resp['ok']) $saved = true;
                else $error = $resp['msg'] ?: '更换失败';
            }
        }

        // 手机号掩码显示
        $maskedPhone = '';
        if (!empty($user['phone'])) {
            $p = (string) $user['phone'];
            $maskedPhone = strlen($p) >= 11
                ? preg_replace('/^(\d{3})\d{4}(\d{4})/', '$1****$2', $p)
                : $p;
        }

        // 1:1 对齐 changePhone.vue 国家选择列表(同 login)
        $countryList = [];
        $defaultCountry = ['id' => 1, 'dial' => '86', 'name_cn' => '中国', 'native_language' => '中国', 'national_flag' => 'https://beautsgoimg.59w.net/country_ico/cn.svg'];
        try {
            $r = $auth->call('GET', '/Common/countryList');
            if (!empty($r['ok'])) {
                $countryList = (array) ($r['data']['all'] ?? []);
                foreach ($countryList as $c) {
                    if ((int) ($c['id'] ?? 0) === 1) { $defaultCountry = $c; break; }
                }
            }
        } catch (\Throwable $e) {}

        $this->seo->setTdk('更换手机号 - BeautsGO', '更换手机号', '更换手机号')->buildOrganization();
        return $this->render('pages/me/change-phone', [
            'user'           => $user,
            'maskedPhone'    => $maskedPhone,
            'error'          => $error,
            'saved'          => $saved,
            'countryList'    => $countryList,
            'defaultCountry' => $defaultCountry,
        ]);
    }

    /* ============== 我的评论 ============== */
    public function myComments()
    {
        $auth = new AuthService();
        $user = $auth->getCurrentUser();
        $uid  = (int) ($user['id'] ?? 0);
        $page = max(1, (int) $this->request->param('page', 1));

        // 直读 comment 表(用户态接口未必暴露 /comment/my,直接 SQL)
        $list = $uid ? Db::name('comment')
            ->where('uid', $uid)->where('status', 2)
            ->field(['id', 'type', 'with_id', 'content', 'rating', 'create_time'])
            ->order('create_time desc')
            ->limit(($page - 1) * 20, 20)
            ->select()->toArray() : [];
        $total = $uid ? (int) Db::name('comment')->where('uid', $uid)->where('status', 2)->count() : 0;

        $this->seo->setTdk('我的评价 - BeautsGO', '我发布的评价', '我的评价')->buildOrganization();
        return $this->render('pages/me/comments', [
            'user'         => $user,
            'list'         => $list,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => max(1, (int) ceil($total / 20)),
            'filterParams' => ['area' => 0, 'level' => 0, 'category' => 0],
            'tab'          => 0,
        ]);
    }

    /* ============== 我的案例 ============== */
    public function myCases()
    {
        $auth = new AuthService();
        $user = $auth->getCurrentUser();
        $uid  = (int) ($user['id'] ?? 0);
        $page = max(1, (int) $this->request->param('page', 1));

        $list = $uid ? Db::name('compare_case')
            ->where('uid', $uid)->where('status', 1)
            ->field(['id', 'type', 'with_id', 'content', 'pictures', 'create_time'])
            ->order('create_time desc')
            ->limit(($page - 1) * 20, 20)
            ->select()->toArray() : [];
        foreach ($list as &$r) {
            $pics = json_decode((string) ($r['pictures'] ?? ''), true) ?: [];
            $r['pic_url_0'] = is_array($pics) ? ($pics[0]['url'] ?? '') : '';
        }
        $total = $uid ? (int) Db::name('compare_case')->where('uid', $uid)->where('status', 1)->count() : 0;

        $this->seo->setTdk('我的案例 - BeautsGO', '我发布的案例', '我的案例')->buildOrganization();
        return $this->render('pages/me/cases', [
            'user'         => $user,
            'list'         => $list,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => max(1, (int) ceil($total / 20)),
            'filterParams' => ['area' => 0, 'level' => 0, 'category' => 0],
            'tab'          => 0,
        ]);
    }

    /* ============== 写案例 ============== */
    public function newCase()
    {
        $auth = new AuthService();
        $error = '';
        $saved = false;
        if ($this->request->isPost()) {
            $payload = [
                'type'    => (int) $this->request->param('type', 1),
                'with_id' => (int) $this->request->param('with_id', 0),
                'content' => trim((string) $this->request->param('content', '')),
                'pictures'=> (string) $this->request->param('pictures', '[]'),
            ];
            if ($payload['content'] === '') {
                $error = '请填写内容';
            } else {
                // 对齐 userCase.vue:120 POST Cases/publish
                $resp = $auth->call('POST', '/Cases/publish', $payload);
                if ($resp['ok']) $saved = true;
                else $error = $resp['msg'] ?: '发布失败';
            }
        }
        $this->seo->setTdk('写案例 - BeautsGO', '分享我的医美案例', '写案例')->buildOrganization();
        return $this->render('pages/me/case-new', [
            'user'  => $auth->getCurrentUser(),
            'error' => $error,
            'saved' => $saved,
        ]);
    }

    /* ============== 分享 / 二维码 ============== */
    public function share()
    {
        $auth = new AuthService();
        $user = $auth->getCurrentUser();
        $type = (int) $this->request->param('type', 0);
        $id   = (int) $this->request->param('id', 0);

        $base = rtrim((string) config('seo.site_url'), '/');
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $url = $base . '/' . $langSeg . '/' .
            (['', 'hospital', 'doctor', 'project'][$type] ?? '') . '/' . $id;

        // 用免费 QR API(或本地库),这里用 google chart api
        $qrcode = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($url);

        $this->seo->setTdk('分享 - BeautsGO', '分享给好友', '分享')->buildOrganization();
        return $this->render('pages/share/index', [
            'user'   => $user,
            'url'    => $url,
            'qrcode' => $qrcode,
        ]);
    }

    /* ============== 积分兑换流程 ============== */
    public function redeem(int $id = 0)
    {
        if (!$id) $this->abort404('Missing point id');
        $auth = new AuthService();
        $row = Db::name('integral_project')
            ->where('id', $id)->where('status', 1)
            ->field(['id', 'title', 'cover_detail', 'point', 'price'])->find();
        if (!$row) $this->abort404('Point item not found');
        $cov = json_decode((string) $row['cover_detail'], true) ?: [];
        $row['cover_url'] = is_array($cov) ? ($cov[0]['url'] ?? '') : '';

        $this->seo->setTdk($row['title'] . ' - 积分兑换 - BeautsGO', '填写收件地址', '积分兑换')
            ->buildOrganization();
        return $this->render('pages/point/redeem', [
            'user' => $auth->getCurrentUser(),
            'item' => $row,
        ]);
    }

    public function pointConfirm()
    {
        $auth = new AuthService();
        if (!$this->request->isPost()) {
            return redirect('/' . $this->langSeg() . '/point/shop');
        }
        $payload = [
            'pid'      => (int) $this->request->param('pid', 0),
            'name'     => trim((string) $this->request->param('name', '')),
            'phone'    => trim((string) $this->request->param('phone', '')),
            'address'  => trim((string) $this->request->param('address', '')),
            'remark'   => (string) $this->request->param('remark', ''),
        ];
        $resp = $auth->call('POST', '/integral/exchange', $payload);
        if ($resp['ok']) {
            return $this->render('pages/point/success', [
                'orderNo' => (string) ($resp['data']['order_no'] ?? ''),
            ]);
        }
        return $this->render('pages/point/redeem', [
            'user'  => $auth->getCurrentUser(),
            'item'  => ['id' => $payload['pid'], 'title' => '兑换商品', 'cover_url' => '', 'point' => 0, 'price' => 0],
            'error' => $resp['msg'] ?: '兑换失败',
        ]);
    }

    /**
     * 物流详情 — beauts_app/expressData/expressData.vue
     *   GET /Form/expressData/:id
     */
    public function expressData(int $id = 0)
    {
        if (!$id) $this->abort404('Missing express id');
        $resp = $this->api->get('/Form/expressData/' . $id);
        $data = (array) ($resp['data'] ?? []);
        if (empty($data)) $this->abort404('Express not found');

        $this->seo->setTdk('物流详情 - BeautsGO', '物流跟踪', '物流')->buildOrganization();
        return $this->render('pages/me/express', [
            'user'    => (new AuthService())->getCurrentUser(),
            'express' => $data,
        ]);
    }

    private function langSeg(): string
    {
        return (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
    }
}
