<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\IndexRepository;
use think\facade\Db;

/**
 * 积分商城 —— /{lang}/point/shop  /  /{lang}/point/{id}
 */
class Points extends BaseController
{
    private const PAGE_SIZE = 12;

    public function shop()
    {
        $page    = max(1, (int) $this->request->param('page', 1));
        $catId   = (int) $this->request->param('category', 0);

        $repo = new IndexRepository($this->lang);
        $integralCls = $repo->fetchIntegralClassList();
        $data = $repo->fetchPointList(['category' => $catId], $page, self::PAGE_SIZE);
        $totalPages = max(1, (int) ceil($data['total'] / self::PAGE_SIZE));

        // 我的积分(对齐 pointShop.vue:6 显示 point)
        $auth = new \app\service\AuthService();
        $pResp = $auth->call('GET', '/user/getPoint');
        $point = (int) ($pResp['data']['point'] ?? $pResp['data'] ?? 0);

        $title = '积分商城 - BeautsGO';
        $desc  = '使用积分兑换医美项目、体验金、护肤品等。';
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . '/point/shop';
        $this->seo->setTdk($title, $desc, '积分商城,医美积分兑换')
            ->setCanonical($canonical)
            ->buildOrganization()
            ->buildBreadcrumb([['name' => '首页', 'url' => '/'], ['name' => '积分商城', 'url' => '/point/shop']]);

        return $this->render('pages/point/shop', [
            'list'         => $data['list'],
            'total'        => $data['total'],
            'page'         => $page,
            'totalPages'   => $totalPages,
            'integralCls'  => $integralCls,
            'filterParams' => ['category' => $catId, 'area' => 0, 'level' => 0],
            'tab'          => 3,
            'point'        => $point,
        ]);
    }

    public function detail(int $id = 0)
    {
        if (!$id) $this->abort404('Missing point id');
        $prefix = $this->langPrefix();

        $row = Db::name('integral_project')
            ->field(['id', $prefix . 'title AS title', 'en_title', 'title AS zh_title',
                     $prefix . 'content AS content', 'cover_detail', 'point', 'price', 'num', 'redeem_num',
                     'h_id'])
            ->where('id', $id)
            ->where('status', 1)
            ->find();
        if (!$row) $this->abort404('Point item not found');

        if (empty($row['title'])) $row['title'] = $row['en_title'] ?: $row['zh_title'];
        $cov = json_decode((string) $row['cover_detail'], true) ?: [];
        $row['cover']     = is_array($cov) ? $cov : [];
        $row['cover_url'] = $row['cover'][0]['url'] ?? '';
        $row['content']   = htmlspecialchars_decode((string) ($row['content'] ?? ''));
        $row['redeem_num'] = $row['redeem_num'] ?: random_int(50, 500);

        // 适用医院(h_id 字段是 JSON 数组,如 ["254","349"])
        $hids = [];
        if (!empty($row['h_id'])) {
            if (is_array($row['h_id'])) $hids = $row['h_id'];
            else {
                $decoded = json_decode((string) $row['h_id'], true);
                $hids = is_array($decoded) ? $decoded : [];
            }
        }
        $hospitals = [];
        if ($hids) {
            $hospitals = Db::name('hospital')->whereIn('id', $hids)->where('status', 1)
                ->field(['id', $prefix . 'name AS name', 'en_name', 'name AS zh_name', 'cover_detail',
                         $this->columnExists('hospital','slug') ? 'slug' : 'NULL AS slug'])
                ->limit(6)
                ->select()->toArray();
            foreach ($hospitals as &$h) {
                if (empty($h['name'])) $h['name'] = $h['en_name'] ?: $h['zh_name'];
                $hc = json_decode((string) $h['cover_detail'], true) ?: [];
                $h['cover_url'] = is_array($hc) ? ($hc[0]['url'] ?? '') : '';
                if (empty($h['slug'])) $h['slug'] = (string) $h['id'];
                unset($h['cover_detail']);
            }
        }

        $title = $row['title'] . ' - 积分兑换 - BeautsGO';
        $desc = mb_substr(strip_tags((string) ($row['content'] ?? '')), 0, 155);
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . '/point/' . $id;
        $this->seo->setTdk($title, $desc, '积分兑换,韩国医美')
            ->setCanonical($canonical)
            ->setOg(['title' => $title, 'description' => $desc, 'image' => $row['cover_url'] ?: config('seo.org_logo'), 'url' => $canonical, 'type' => 'product'])
            ->buildOrganization()
            ->buildBreadcrumb([['name' => '首页', 'url' => '/'], ['name' => '积分商城', 'url' => '/point/shop'], ['name' => $row['title'], 'url' => '/point/' . $id]]);

        return $this->render('pages/point/detail', [
            'item'      => $row,
            'hospitals' => $hospitals,
        ]);
    }

    private function langPrefix(): string
    {
        switch ($this->lang) {
            case 'zh-Hant': return 'zh_hant_';
            case 'en':      return 'en_';
            case 'ja':      return 'ja_';
            case 'th':      return 'th_';
            default:        return '';
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $k = $table . '.' . $column;
        if (isset($cache[$k])) return $cache[$k];
        try {
            return $cache[$k] = in_array($column, (array) Db::name($table)->getTableFields(), true);
        } catch (\Throwable $e) {
            return $cache[$k] = false;
        }
    }

    public function sign()
    {
        $auth = new \app\service\AuthService();
        $signed = false;
        $error = '';
        if ($this->request->isPost()) {
            $resp = $auth->call('POST', '/sign/info', []);
            if ($resp['ok']) $signed = true;
            else $error = $resp['msg'] ?: '签到失败';
        }
        // 获取当前积分
        $pointResp = $auth->call('GET', '/user/getPoint', []);
        $point = (int) ($pointResp['data']['point'] ?? $pointResp['data'] ?? 0);

        $this->seo->setTdk('每日签到 - BeautsGO', '签到领积分', '签到,积分')->buildOrganization();
        return $this->render('pages/point/sign', [
            'user'   => $auth->getCurrentUser(),
            'point'  => $point,
            'signed' => $signed,
            'error'  => $error,
        ]);
    }

    public function task()
    {
        $auth = new \app\service\AuthService();
        $resp = $auth->call('GET', '/Point/taskList', []);
        $tasks = (array) ($resp['data'] ?? []);
        if (isset($tasks['list'])) $tasks = $tasks['list'];

        $this->seo->setTdk('积分任务 - BeautsGO', '完成任务领积分', '积分任务')->buildOrganization();
        return $this->render('pages/point/task', [
            'user'  => $auth->getCurrentUser(),
            'tasks' => $tasks,
        ]);
    }

    /**
     * 团队分享背景图(beauts_app/point/team.vue)
     */
    public function team()
    {
        $auth = new \app\service\AuthService();
        $user = $auth->getCurrentUser();
        $this->seo->setTdk('邀请好友 - BeautsGO', '恭喜解锁 BeautsGO 变美福利,邀请好友一起来', '邀请,分享')
            ->buildOrganization();
        return $this->render('pages/point/team', [
            'user'     => $user,
            'shareId'  => $user['id'] ?? 0,
            'bgImage'  => 'https://beautsgoimg.59w.net/20250228141951/67c155876c36d.png',
            'shareImg' => 'https://beautsgoimg.59w.net/20250305102538/67c7b62215bae.jpg',
        ]);
    }

    /**
     * 任务分享背景图(beauts_app/point/taskShare.vue)
     */
    public function taskShare()
    {
        $auth = new \app\service\AuthService();
        $user = $auth->getCurrentUser();
        $this->seo->setTdk('任务分享 - BeautsGO', '分享 BeautsGO 任务给好友', '任务,分享')
            ->buildOrganization();
        return $this->render('pages/point/team', [
            'user'     => $user,
            'shareId'  => $user['id'] ?? 0,
            'bgImage'  => 'https://beautsgoimg.59w.net/20250228141951/67c155876c36d.png',
            'shareImg' => 'https://beautsgoimg.59w.net/20250305102538/67c7b62215bae.jpg',
        ]);
    }

    /**
     * 积分订单详情(beauts_app/pointOrderDetail/pointOrderDetail.vue)
     *   /api/userIntegralDetail/{id} + /api/Form/expressData/{id}
     */
    public function orderDetail(int $id = 0)
    {
        if (!$id) $this->abort404('Missing point order id');
        $auth = new \app\service\AuthService();
        $resp = $auth->call('GET', '/userIntegralDetail/' . $id);
        $info = (array) ($resp['data'] ?? []);
        if (empty($info)) $this->abort404('Point order not found');

        // 物流(可选,失败容忍)
        $express = $auth->call('GET', '/Form/expressData/' . $id);
        $expressData = (array) ($express['data'] ?? []);

        $this->seo->setTdk('积分订单 - BeautsGO', '积分订单详情', '积分订单')->buildOrganization();
        return $this->render('pages/point/order-detail', [
            'user'    => $auth->getCurrentUser(),
            'info'    => $info,
            'express' => $expressData,
        ]);
    }
}
