<?php
declare(strict_types=1);

namespace app\controller;

use think\facade\Db;

/**
 * 专题集合页 —— /{lang}/topics
 *   展示活动专题(activity / marketing_project)
 */
class Topics extends BaseController
{
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
