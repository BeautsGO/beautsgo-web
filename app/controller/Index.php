<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\IndexRepository;

/**
 * 首页 —— 与 i.beautsgo.com/cn/ 真实页 1:1 对齐
 *   /              默认 zh-Hans
 *   /{lang}/       多语言入口 (cn/zh/en/ja/th)
 *   ?tab=N         切 4 个 tab(机构/项目/医生/积分),URL-based,SEO 友好
 *   ?page=N        分页
 *   ?area=X&level=Y&category=Z&q=KW  筛选
 */
class Index extends BaseController
{
    /** @var int 每页 size,与 IndexServices::getPageValue 默认 10 对齐 */
    private const PAGE_SIZE = 10;

    /** @var array tab key → 内部用 */
    private const TABS = ['hospital', 'project', 'doctor', 'point'];

    public function index()
    {
        $this->trackPage('首页');
        $tab   = max(0, min(3, (int) $this->request->param('tab', 0)));
        $page  = max(1, (int) $this->request->param('page', 1));
        $filters = [
            'area'     => (int)    $this->request->param('area', 0),
            'level'    => (int)    $this->request->param('level', 0),     // 医院特色 (hospital_meta type=1)
            'category' => (int)    $this->request->param('category', 0),  // 热门项目 (project_classify)
            'service'  => (string) $this->request->param('service', ''),  // 服务语种
            'kw'       => (string) $this->request->param('q', ''),
        ];

        $repo = new IndexRepository($this->lang);

        // 首屏固定数据
        $classes    = $repo->fetchHomeClass();
        $banner     = $repo->fetchBanner();
        $filterOpts = $repo->fetchFilterOptions();

        // 当前 tab 列表
        $data = ['list' => [], 'total' => 0];
        switch ($tab) {
            case 0: $data = $repo->fetchHospitalList($filters, $page, self::PAGE_SIZE); break;
            case 1: $data = $repo->fetchProjectList($filters,  $page, self::PAGE_SIZE); break;
            case 2: $data = $repo->fetchDoctorList($filters,   $page, self::PAGE_SIZE); break;
            case 3: $data = $repo->fetchPointList($filters,    $page, self::PAGE_SIZE); break;
        }
        $integralCls = $tab === 3 ? $repo->fetchIntegralClassList() : [];

        $tabLabels = [
            (string) ($this->tt('index.tabs.hospital', '甄选机构')),
            (string) ($this->tt('index.tabs.project',  '热推项目')),
            (string) ($this->tt('index.tabs.doctor',   '口碑院长')),
            (string) ($this->tt('index.tabs.point',    '积分商城')),
        ];

        // SEO
        $this->injectSeo($tab, $tabLabels[$tab], $data['list'], $page);

        $totalPages = max(1, (int) ceil($data['total'] / self::PAGE_SIZE));

        // 渐进增强:?_partial=1 时只 render 列表 partial,JS 拼到主页面
        if ($this->request->param('_partial', 0)) {
            $headers = ['X-Has-More' => ($page < $totalPages) ? '1' : '0'];
            return view('pages/_list_partial', [
                'tab'     => $tab,
                'list'    => $data['list'],
                'lang'    => $this->lang,
                'lang_seg'=> (config('seo.lang_path_map')[$this->lang] ?? 'cn'),
                'tt'      => (array) (config('lang.' . $this->lang) ?: []),
            ])->header($headers);
        }

        return $this->render('pages/index', [
            'tab'          => $tab,
            'tabKey'       => self::TABS[$tab],
            'page'         => $page,
            'totalPages'   => $totalPages,
            'filterParams' => $filters,
            'classes'      => $classes,
            'banner'       => $banner,
            'filterOpts'   => $filterOpts,
            'list'         => $data['list'],
            'total'        => $data['total'],
            'integralCls'  => $integralCls,
            'tabLabels'    => $tabLabels,
        ]);
    }

    /**
     * SEO 注入:WebSite + Organization + Breadcrumb + ItemList(当前 tab 列表)
     */
    private function injectSeo(int $tab, string $tabLabel, array $list, int $page): void
    {
        $base = rtrim((string) config('seo.site_url'), '/');
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');

        // title 不再拼品牌后缀(SeoService::setTdk 会自动 append config.seo.brand_suffix)
        $titleBase = $this->tt('seo.home.title', '韩国医美预约平台');
        $title = $page > 1
            ? sprintf('%s 第 %d 页 - %s', $tabLabel, $page, $titleBase)
            : sprintf('%s - %s', $tabLabel, $titleBase);

        $desc = (string) ($this->tt('seo.home.description',
            'BeautsGO 中韩同价、价格透明的韩国医美预约平台,覆盖整形、皮肤、抗衰等全品类项目,连接 900+ 顶尖机构与求美者。'));
        $keywords = '韩国医美,韩国整形医院,医美项目,K-Beauty,首尔整形,江南医美,皮肤科,整形外科';

        $canonical = $base . '/' . $langSeg . '/'
            . ($tab > 0 ? '?tab=' . $tab : '')
            . ($page > 1 ? ($tab > 0 ? '&' : '?') . 'page=' . $page : '');

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
            ]);

        // ItemList JSON-LD —— 当前 tab 列表前 10 条
        if (!empty($list)) {
            $items = [];
            foreach (array_slice($list, 0, 10) as $i => $row) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'url'      => $base . '/' . $langSeg . '/' . $this->itemUrlPath($tab, $row),
                    'name'     => (string) ($row['name'] ?? $row['title'] ?? $row['hospital_name'] ?? ''),
                ];
            }
            $this->seo->addJsonLd([
                '@context'        => 'https://schema.org',
                '@type'           => 'ItemList',
                'name'            => $tabLabel,
                'numberOfItems'   => count($items),
                'itemListElement' => $items,
            ]);
        }
    }

    private function itemUrlPath(int $tab, array $row): string
    {
        $slug = $row['slug'] ?? $row['en_name'] ?? $row['id'] ?? '';
        switch ($tab) {
            case 0: return 'hospital/' . $slug;
            case 1: return 'project/'  . $slug;
            case 2: return 'doctor/'   . $slug;
            case 3: return 'point/'    . $slug;
        }
        return '';
    }

    /**
     * 取一个 i18n 文案,失败回落
     */
    private function tt(string $key, string $fallback): string
    {
        $tt = (array) (config('lang.' . $this->lang) ?: []);
        return (string) ($tt[$key] ?? $fallback);
    }
}
