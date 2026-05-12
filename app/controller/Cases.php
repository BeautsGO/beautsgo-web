<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\CaseRepository;

/**
 * 案例 —— /{lang}/case  /  /{lang}/case/{id}
 */
class Cases extends BaseController
{
    private const PAGE_SIZE = 12;

    public function listing()
    {
        $type    = (int) $this->request->param('type', 0);
        $withId  = (int) $this->request->param('with_id', 0);
        $page    = max(1, (int) $this->request->param('page', 1));

        $repo = new CaseRepository($this->lang);
        $data = $repo->fetchList(compact('type', 'withId') + ['type' => $type, 'with_id' => $withId], $page, self::PAGE_SIZE);

        $totalPages = max(1, (int) ceil($data['total'] / self::PAGE_SIZE));

        $title = '医美案例 - 韩国医美预约平台';
        $desc  = 'BeautsGO 提供海量真实韩国医美案例,涵盖整形、皮肤、抗衰等各类项目前后对照,助你做出更明智的医美决策。';
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . '/case';

        $this->seo->setTdk($title, $desc, '医美案例,韩国医美前后对照')
            ->setCanonical($canonical)
            ->setOg(['title' => $title, 'description' => $desc, 'image' => config('seo.org_logo'), 'url' => $canonical, 'type' => 'website'])
            ->buildWebSite()->buildOrganization()
            ->buildBreadcrumb([['name' => '首页', 'url' => '/'], ['name' => '案例', 'url' => '/case']]);

        return $this->render('pages/case/list', [
            'list'         => $data['list'],
            'total'        => $data['total'],
            'page'         => $page,
            'totalPages'   => $totalPages,
            'tab'          => 0,
            'filterParams' => ['type' => $type, 'with_id' => $withId, 'area' => 0, 'level' => 0, 'category' => 0],
        ]);
    }

    public function detail(int $id = 0)
    {
        if (!$id) $this->abort404('Missing case id');
        $repo = new CaseRepository($this->lang);
        $case = $repo->fetchDetail($id);
        if (!$case) $this->abort404('Case not found');

        $title = mb_substr($case['content'] ?? '医美案例', 0, 30) . ' - 真实案例 - BeautsGO';
        $desc = mb_substr($case['content'] ?? '', 0, 155);
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . '/case/' . $id;
        $img = $case['pictures'][0]['url'] ?? config('seo.org_logo');

        $this->seo->setTdk($title, $desc, '医美案例,前后对照')
            ->setCanonical($canonical)
            ->setOg(['title' => $title, 'description' => $desc, 'image' => $img, 'url' => $canonical, 'type' => 'article'])
            ->buildOrganization()
            ->buildBreadcrumb([
                ['name' => '首页', 'url' => '/'],
                ['name' => '案例', 'url' => '/case'],
                ['name' => '案例 #' . $id, 'url' => '/case/' . $id],
            ]);

        return $this->render('pages/case/detail', ['case' => $case]);
    }
}
