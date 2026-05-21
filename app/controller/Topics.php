<?php
declare(strict_types=1);

namespace app\controller;

use think\facade\Db;

/**
 * 专题集合页 —— /{lang}/topics(marketing 活动专题)
 * 商圈页 —— /{lang}/topics/{area-slug}(对齐 topics/index.vue 商圈下医院列表)
 */
class Topics extends BaseController
{
    /**
     * 商圈页:slug → trading_area_id → 301 redirect 到 /hospital?area=N
     * 对齐 topics/index.vue 的 /topics/{area-slug} 语义化 URL
     */
    public function area(string $slug = '')
    {
        if ($slug === '') $this->abort404('Missing area slug');
        $prefix = '';
        switch ($this->lang) {
            case 'zh-Hant': $prefix = 'zh_hant_'; break;
            case 'en':      $prefix = 'en_';      break;
            case 'ja':      $prefix = 'ja_';      break;
            case 'th':      $prefix = 'th_';      break;
        }
        $list = Db::name('hospital_trading_area')
            ->where('status', 1)
            ->field(['id', $prefix . 'name AS name', 'en_name', 'name AS zh_name'])
            ->select()->toArray();
        $areaId = 0;
        if (ctype_digit($slug)) {
            foreach ($list as $a) {
                if ((int) $a['id'] === (int) $slug) { $areaId = (int) $a['id']; break; }
            }
        } else {
            $targetSlug = strtolower($slug);
            foreach ($list as $a) {
                $s = strtolower(preg_replace('/[^a-z0-9-]/', '', preg_replace('/\s+/', '-', strtolower((string) $a['en_name']))));
                if ($s === $targetSlug) { $areaId = (int) $a['id']; break; }
            }
        }
        if (!$areaId) $this->abort404('Area not found');

        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        return redirect('/' . $langSeg . '/hospital?area=' . $areaId, 301);
    }

    public function listing()
    {
        $now = time();
        $items = Db::name('marketing_project')
            ->where('status', 1)
            ->where(function ($q) use ($now) {
                $q->whereOr([
                    ['start_time', '=', 0],
                    ['start_time', '<=', $now],
                ]);
            })
            ->where(function ($q) use ($now) {
                $q->whereOr([
                    ['end_time', '=', 0],
                    ['end_time', '>=', $now],
                ]);
            })
            ->field(['id', 'name', 'cover', 'korean_won', 'unit', 'project_feature',
                     'hospital_id', 'price'])
            ->order('is_home desc, sort desc, id desc')
            ->limit(20)
            ->select()->toArray();
        foreach ($items as &$it) {
            $c = json_decode((string) ($it['cover'] ?? ''), true) ?: [];
            $it['cover_url'] = is_array($c) ? ($c[0]['url'] ?? '') : (is_string($c) ? $c : '');
        }

        $title = '医美专题 - BeautsGO';
        $desc  = '汇集韩国医美最新活动、季节限定优惠、明星同款、新品上市等专题资讯。';
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . '/topics';
        $this->seo->setTdk($title, $desc, '医美活动,专题,促销')
            ->setCanonical($canonical)
            ->buildOrganization()
            ->buildBreadcrumb([['name' => '首页', 'url' => '/'], ['name' => '专题', 'url' => '/topics']]);

        return $this->render('pages/topics', ['items' => $items]);
    }
}
