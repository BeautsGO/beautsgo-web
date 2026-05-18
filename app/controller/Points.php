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
        $auth = new \app\service\AuthService();

        // 1:1 对齐 pointShopDetail.vue getShopDetail():$http.get('/Integral/detail/'+id)
        $resp = $auth->call('GET', '/Integral/detail/' . $id);
        $row  = (array) ($resp['data'] ?? []);
        if (empty($row)) $this->abort404('Point item not found');

        // 按当前语言挑标题/正文/使用规则(对齐 vue:608-619)
        $titleField   = $this->localizedField($row, 'title', $row['title'] ?? '');
        $contentField = $this->localizedField($row, 'content', $row['content'] ?? '');
        $useIntro     = $this->localizedField($row, 'use_intro', $row['use_intro'] ?? '');
        $row['title']     = $titleField;
        $row['content']   = htmlspecialchars_decode((string) $contentField);
        $row['use_intro'] = htmlspecialchars_decode((string) $useIntro);
        $banner = (array) ($row['banner'] ?? $row['cover_detail'] ?? []);
        if (!$banner && !empty($row['cover'])) {
            $banner = is_array($row['cover']) ? [$row['cover']] : [];
        }
        $row['banner']  = $banner;
        $row['cover_url'] = $banner[0]['url'] ?? ($banner[0]['cover'] ?? '');

        // 适用医院 1:1 对齐 vue getHospital():$http.get('Integral/hospitalList/'+id)
        $hResp = $auth->call('GET', '/Integral/hospitalList/' . $id);
        $hospitals = (array) ($hResp['data'] ?? []);
        foreach ($hospitals as &$h) {
            // cover 可能是 {url:...} 对象或字符串;统一为数组方便模板取值
            if (is_array($h['cover'] ?? null)) {
                $h['cover_url'] = $h['cover']['url'] ?? ($h['cover']['cover'] ?? '');
            } else {
                $h['cover_url'] = (string) ($h['cover'] ?? '');
                $h['cover'] = ['url' => $h['cover_url']];
            }
            if (empty($h['slug'])) $h['slug'] = (string) ($h['id'] ?? '');
        }
        unset($h);
        $selected = $hospitals[0] ?? [];

        // 用户当前积分(对齐 vue getPoint():$http.get('user/getPoint'))
        $pResp = $auth->call('GET', '/user/getPoint');
        $pData = (array) ($pResp['data'] ?? []);
        $userPoint = (int) ($pData['user_point'] ?? $pData['point'] ?? 0);

        // 价格格式化(对齐 vue formatPrice 中文 ≥1万显示 万₩)
        $formattedPrice = $this->formatPrice((float) ($row['price'] ?? 0));

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
            'item'             => $row,
            'hospitals'        => $hospitals,
            'selectedHospital' => $selected,
            'userPoint'        => $userPoint,
            'formattedPrice'   => $formattedPrice,
        ]);
    }

    private function localizedField(array $row, string $base, string $fallback): string
    {
        $prefix = '';
        switch ($this->lang) {
            case 'zh-Hant': $prefix = 'zh_hant_'; break;
            case 'en':      $prefix = 'en_';      break;
            case 'ja':      $prefix = 'ja_';      break;
            case 'th':      $prefix = 'th_';      break;
            case 'ko-KR':   $prefix = 'ko_kr_';   break;
        }
        if ($prefix && !empty($row[$prefix . $base])) return (string) $row[$prefix . $base];
        return (string) $fallback;
    }

    private function formatPrice(float $price): string
    {
        if ($price <= 0) return '';
        if (in_array($this->lang, ['zh-Hans', 'zh-Hant'], true)) {
            if ($price >= 10000) return number_format($price / 10000, 1) . '万₩';
            return ((int) $price) . '₩';
        }
        return (string) $price;
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
        $error = '';
        // 1:1 对齐 point.vue getSigninfo():POST /sign/info { go_check }
        // go_check=1 表示执行签到;0/缺省 仅查询日历
        $goCheck = (int) ($this->request->isPost() ? 1 : 0);
        $resp = $auth->call('POST', '/sign/info', ['go_check' => $goCheck]);
        $data = (array) ($resp['data'] ?? []);
        if (!$resp['ok'] && $goCheck === 1) $error = $resp['msg'] ?: '签到失败';

        $signList = (array) ($data['seven_days_records'] ?? []);
        $continuousDays = (int) ($data['continuous_days'] ?? 0);
        $hasSignedToday = (bool) ($data['has_signed_in_today'] ?? false);
        $signed = ($goCheck === 1 && $hasSignedToday);

        $pointResp = $auth->call('GET', '/user/getPoint', []);
        $pData = (array) ($pointResp['data'] ?? []);
        $point = (int) ($pData['user_point'] ?? $pData['point'] ?? 0);

        $this->seo->setTdk('每日签到 - BeautsGO', '签到领积分', '签到,积分')->buildOrganization();
        return $this->render('pages/point/sign', [
            'user'           => $auth->getCurrentUser(),
            'point'          => $point,
            'signed'         => $signed,
            'error'          => $error,
            'signList'       => $signList,
            'continuousDays' => $continuousDays,
            'hasSignedToday' => $hasSignedToday,
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
