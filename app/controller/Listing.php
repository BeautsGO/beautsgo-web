<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\IndexRepository;

/**
 * 列表页 —— 复用 IndexRepository 各 fetch* 方法
 *   GET /{lang}/hospital  → hospitalList
 *   GET /{lang}/doctor    → doctorList
 *   GET /{lang}/project   → projectList(?cate=N 分类)
 *   GET /{lang}/search    → search(?q=KW&tab=0/1/2)
 */
class Listing extends BaseController
{
    private const PAGE_SIZE = 10;

    public function hospitalList()  { $this->trackPage('机构列表页'); return $this->renderList('hospital', 0); }
    public function doctorList()    { $this->trackPage('医生列表页'); return $this->renderList('doctor',   2); }
    public function projectList()   { $this->trackPage('项目列表页'); return $this->renderList('project',  1); }

    /**
     * 项目分类详情列表 —— /{lang}/projects/category/{slug}
     *   e.g. /cn/projects/category/skin-boosters
     */
    public function projectByCategory(string $slug = '')
    {
        if ($slug === '') $this->abort404('Missing category slug');
        $repo = new IndexRepository($this->lang);
        $cid = ctype_digit($slug) ? (int) $slug : $repo->findClassifyIdBySlug($slug);
        if (!$cid) $this->abort404('Category not found: ' . $slug);

        // 拿分类名 + 顶部分类列表(供"返回全部"链接 + tab 高亮)
        $name = (string) ($repo->findClassifyNameById($cid, $this->lang) ?? $slug);
        $this->trackPage('项目分类页:' . $name, $cid);

        return $this->renderList('project', 1, [
            'forcedCategory' => $cid,
            'categoryName'   => $name,
            'categorySlug'   => $slug,
        ]);
    }

    public function search()
    {
        $kw  = trim((string) $this->request->param('q', ''));
        $tab = max(0, min(3, (int) $this->request->param('tab', 0)));

        // 无 q → 搜索建议页(对齐 pages/search/search.vue:历史 + 热门)
        if ($kw === '') {
            // 热门搜索:后端 getConfig.platformConfig.searchConfig
            $cfg = $this->api->get('/getConfig');
            $hot = (array) ($cfg['data']['platformConfig']['searchConfig'] ?? []);
            $hot = array_values(array_filter(array_map('strval', $hot)));

            $this->trackPage('搜索建议页', 0, '搜索');
            $this->seo->setTdk(($this->tt('search.title', '搜索') . ' - BeautsGO'), '搜索韩国医美机构/医生/项目', '搜索')
                ->setCanonical((string) config('seo.site_url') . '/' . (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn') . '/search')
                ->buildOrganization();
            return $this->render('pages/search/suggest', [
                'hotList' => $hot,
            ]);
        }

        $this->trackPage('搜索结果页', 0, '搜索', '搜索: ' . $kw);
        return $this->renderList('search', $tab, ['kw' => $kw]);
    }

    /**
     * @param string $kind  hospital / doctor / project / search
     * @param int    $tab   0=hospital, 1=project, 2=doctor, 3=point
     */
    private function renderList(string $kind, int $tab, array $extraFilters = [])
    {
        $page  = max(1, (int) $this->request->param('page', 1));
        $area  = (int)    $this->request->param('area', 0);
        $level = (int)    $this->request->param('level', 0);
        $catId = (int) ($extraFilters['forcedCategory'] ?? $this->request->param('category', $this->request->param('cate', 0)));
        $kw    = (string) ($extraFilters['kw'] ?? $this->request->param('q', ''));
        $service = (string) $this->request->param('service', '');

        $filters = compact('area', 'level', 'service', 'kw') + ['category' => $catId];

        $repo = new IndexRepository($this->lang);
        $filterOpts = $repo->fetchFilterOptions();

        switch ($tab) {
            case 0: $data = $repo->fetchHospitalList($filters, $page, self::PAGE_SIZE); break;
            case 1: $data = $repo->fetchProjectList($filters,  $page, self::PAGE_SIZE); break;
            case 2: $data = $repo->fetchDoctorList($filters,   $page, self::PAGE_SIZE); break;
            case 3: $data = $repo->fetchPointList($filters,    $page, self::PAGE_SIZE); break;
            default: $data = ['list' => [], 'total' => 0];
        }

        // 渐进增强:?_partial=1 出列表片段
        if ($this->request->param('_partial', 0)) {
            $headers = ['X-Has-More' => ($page * self::PAGE_SIZE < $data['total']) ? '1' : '0'];
            return view('pages/_list_partial', [
                'tab'      => $tab,
                'list'     => $data['list'],
                'lang'     => $this->lang,
                'lang_seg' => (config('seo.lang_path_map')[$this->lang] ?? 'cn'),
                'tt'       => (array) (config('lang.' . $this->lang) ?: []),
            ])->header($headers);
        }

        $tabLabels = [
            $this->tt('index.tabs.hospital', '甄选机构'),
            $this->tt('index.tabs.project',  '热推项目'),
            $this->tt('index.tabs.doctor',   '口碑院长'),
            $this->tt('index.tabs.point',    '积分商城'),
        ];

        // 项目分类页:用分类名做 title + canonical + breadcrumb
        if (!empty($extraFilters['categorySlug'])) {
            $this->injectCategorySeo($extraFilters['categoryName'], $extraFilters['categorySlug'], $page);
        } else {
            $this->injectSeo($kind, $tab, $tabLabels[$tab], $page, $kw);
        }

        $totalPages = max(1, (int) ceil($data['total'] / self::PAGE_SIZE));

        return $this->render('pages/list', [
            'kind'         => $kind,
            'tab'          => $tab,
            'tabKey'       => ['hospital','project','doctor','point'][$tab] ?? 'hospital',
            'page'         => $page,
            'totalPages'   => $totalPages,
            'filterParams' => $filters,
            'filterOpts'   => $filterOpts,
            'list'         => $data['list'],
            'total'        => $data['total'],
            'tabLabels'    => $tabLabels,
            'isSearch'     => $kind === 'search',
            'keyword'      => $kw,
        ]);
    }

    private function injectSeo(string $kind, int $tab, string $tabLabel, int $page, string $kw): void
    {
        $base    = rtrim((string) config('seo.site_url'), '/');
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');

        $titleBase = $this->tt('seo.home.title', '韩国医美预约平台');
        if ($kind === 'search' && $kw !== '') {
            $title = sprintf('"%s" - %s 搜索结果%s - %s',
                $kw, $tabLabel, $page > 1 ? ' 第 ' . $page . ' 页' : '', $titleBase);
        } else {
            $title = sprintf('%s%s - %s', $tabLabel, $page > 1 ? ' 第 ' . $page . ' 页' : '', $titleBase);
        }

        $desc = $this->tt('seo.home.description', 'BeautsGO 韩国医美预约平台,覆盖整形、皮肤、抗衰等全品类项目。');

        $kwBits = array_filter([$tabLabel, '韩国医美', $kw ?: null]);
        $keywords = implode(',', $kwBits);

        $path = $kind === 'search' ? '/search' : '/' . $kind;
        $canonical = $base . '/' . $langSeg . $path
            . ($page > 1 ? '?page=' . $page : '');

        $this->seo
            ->setTdk($title, $desc, $keywords)
            ->setCanonical($canonical)
            ->setOg([
                'title'       => $title,
                'description' => $desc,
                'image'       => (string) config('seo.org_logo'),
                'url'         => $canonical,
                'type'        => 'website',
            ])
            ->buildWebSite()
            ->buildOrganization()
            ->buildBreadcrumb([
                ['name' => $this->seo->getBreadcrumbI18n()['home'] ?? '首页', 'url' => '/'],
                ['name' => $tabLabel, 'url' => $path],
            ]);
    }

    private function injectCategorySeo(string $categoryName, string $categorySlug, int $page): void
    {
        $base    = rtrim((string) config('seo.site_url'), '/');
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $brand   = $this->tt('seo.home.title', '韩国医美预约平台');

        $title = $categoryName . ($page > 1 ? ' 第 ' . $page . ' 页' : '') . ' - ' . $brand;
        $desc  = sprintf('%s 韩国医美项目,价格透明、医院齐全,在 BeautsGO 上一站预约。', $categoryName);
        $kwds  = $categoryName . ',韩国医美,' . $categoryName . '项目预约';
        $canonical = $base . '/' . $langSeg . '/projects/category/' . $categorySlug . ($page > 1 ? '?page=' . $page : '');

        $this->seo
            ->setTdk($title, $desc, $kwds)
            ->setCanonical($canonical)
            ->setOg([
                'title'       => $title,
                'description' => $desc,
                'image'       => (string) config('seo.org_logo'),
                'url'         => $canonical,
                'type'        => 'website',
            ])
            ->buildWebSite()
            ->buildOrganization()
            ->buildBreadcrumb([
                ['name' => $this->seo->getBreadcrumbI18n()['home']    ?? '首页', 'url' => '/'],
                ['name' => $this->seo->getBreadcrumbI18n()['project'] ?? '项目', 'url' => '/project'],
                ['name' => $categoryName, 'url' => '/projects/category/' . $categorySlug],
            ]);
    }

    private function tt(string $key, string $fallback): string
    {
        $tt = (array) (config('lang.' . $this->lang) ?: []);
        return (string) ($tt[$key] ?? $fallback);
    }
}
