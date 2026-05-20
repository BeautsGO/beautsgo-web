<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\HospitalRepository;
use think\facade\Db;

/**
 * 医院相关页面控制器
 *   GET /{lang}/hospital/{slug} → detail
 */
class Hospital extends BaseController
{
    public function detail(string $slug = '')
    {
        if ($slug === '') {
            $this->abort404('Missing hospital slug');
        }

        $repo = new HospitalRepository($this->lang);

        // 兼容 id 直访：/cn/hospital/358 (调试用，slug 解析有问题时也能看页面)
        if (ctype_digit($slug)) {
            $hospital = $repo->detailById((int) $slug);
        } else {
            $hospital = $repo->detailBySlug($slug);
        }

        if (!$hospital) {
            $this->abort404('Hospital not found: ' . $slug);
        }

        $hid = (int) $hospital['id'];

        // 一次性聚合所有附加数据（Repository 内部都是单次 SQL）
        $cases       = $repo->fetchCases($hid, 6);
        $comments    = $repo->fetchComments($hid, 2);
        $commentTags = $repo->fetchCommentTags($hid);
        $integral    = $repo->fetchIntegralList($hid, 4);
        $intro       = $repo->fetchIntroduction($hid);
        $services    = $repo->expandServices($hospital['services'] ?? []);
        $hpPrice     = $repo->fetchHospitalPrice($hid);
        $hasAd       = $repo->hasAdvertise($hid);
        $activity    = $repo->fetchActivityList($hid);
        $recommend   = $repo->getRecommendList($hid, 4);

        // 吸顶 tabs（与 detail.vue line 1329 等价）
        $stickyList = [
            ['id' => 1, 'name' => $hospital['tag_project'] ?? '特色项目'],
            ['id' => 2, 'name' => $hospital['tag_doc']     ?? '专家精选'],
            ['id' => 3, 'name' => $hospital['tag_hos']     ?? '机构简介'],
        ];

        // ---------- SEO 注入 ----------
        $rating = $this->buildRatingFromComments($comments, $hospital['rating'] ?? null);
        $this->injectSeo($hospital, $rating);

        // ---------- 浏览轨迹埋点 ----------
        $this->trackPage('机构详情页', $hid);

        // ---------- 渲染 ----------
        return $this->render('pages/hospital/detail', [
            'hospital'        => $hospital,
            'banner'          => $hospital['banner'] ?? [],
            'experts'         => $hospital['Expert'] ?? [],
            'projects'        => $hospital['FeaturedProjects'] ?? [],
            'qualifications'  => $hospital['qualification'] ?? [],
            'cases'           => $cases,
            'comments'        => $comments,
            'tags'            => $commentTags,
            'integral'        => $integral,
            'introduction'    => $intro,
            'services'        => $services,
            'hospitalPrice'   => $hpPrice,
            'hasAd'           => $hasAd,
            'activity'        => $activity,
            'sticky'          => $stickyList,
            'recommend'       => $recommend,
        ]);
    }

    /**
     * 用真实评论数据构造 rating（覆盖 fetchRatingAggregate 的 fallback）
     */
    private function buildRatingFromComments(array $comments, $repoRating)
    {
        // 优先用 Repository 已聚合的真评分
        if (!empty($repoRating) && (int) ($repoRating['count'] ?? 0) > 0) {
            return $repoRating;
        }
        // 否则从 comments 现拼一个
        if (empty($comments)) return null;
        $ratings = array_filter(array_column($comments, 'rating'));
        if (empty($ratings)) return null;
        return [
            'count'     => count($ratings),
            'avgRating' => round(array_sum($ratings) / count($ratings), 1),
        ];
    }

    /**
     * 把医院数据组装成 SEO 元数据
     */
    private function injectSeo(array $hospital, $rating = null): void
    {
        $name    = (string) ($hospital['name'] ?? '');
        $address = (string) ($hospital['zh_cn_address'] ?? '');
        $level   = (string) ($hospital['level'] ?? '');
        $area    = (string) (($hospital['tradingArea']['name'] ?? '') ?: '首尔');

        // TDK
        $title = $name . ' - ' . $area . '医美机构';
        $desc  = $this->buildDescription($hospital);

        $kwBits = array_filter([
            $name,
            $hospital['en_name'] ?? '',
            $area . '医美',
            $level,
            '韩国整形',
        ]);
        $keywords = implode(',', $kwBits);

        // 当前页 URL（slug_url 已规范化）
        $canonical = (string) config('seo.site_url')
            . '/' . (config('seo.lang_path_map')[$this->lang] ?? 'cn')
            . '/hospital/' . ($hospital['slug_url'] ?? $hospital['id']);

        $cover = $hospital['banner'][0]['cover']
            ?? $hospital['cover'][0]['url']
            ?? (string) config('seo.org_logo');

        $this->seo
            ->setTdk($title, $desc, $keywords)
            ->setCanonical($canonical)
            ->setOg([
                'title'       => $title,
                'description' => $desc,
                'image'       => $cover,
                'url'         => $canonical,
                'type'        => 'website',
            ])
            ->buildOrganization()
            ->buildHospital($hospital, $rating ?: ($hospital['rating'] ?? null))
            ->buildBreadcrumb([
                ['name' => $this->seo->getBreadcrumbI18n()['home'],     'url' => '/'],
                ['name' => $this->seo->getBreadcrumbI18n()['hospital'], 'url' => '/hospital'],
                ['name' => $name,                                       'url' => '/hospital/' . ($hospital['slug_url'] ?? $hospital['id'])],
            ]);
    }

    /**
     * 拼装 description（≤ 155 字符）
     */
    private function buildDescription(array $h): string
    {
        $bits = [];
        if (!empty($h['name']))           $bits[] = $h['name'];
        if (!empty($h['level']))          $bits[] = $h['level'];
        if (!empty($h['zh_cn_address'])) $bits[] = '位于' . $h['zh_cn_address'];
        if (!empty($h['response_time'])) $bits[] = '平均响应' . $h['response_time'] . '小时';
        if (!empty($h['Expert']))        $bits[] = '入驻医生 ' . count($h['Expert']) . ' 位';
        if (!empty($h['FeaturedProjects'])) $bits[] = '热门项目 ' . count($h['FeaturedProjects']) . ' 个';

        $desc = implode('，', $bits) . '。';
        // 兜底：如果信息不足，用 advertise_content
        if (mb_strlen($desc) < 60 && !empty($h['advertise_content'])) {
            $desc .= mb_substr(strip_tags((string) $h['advertise_content']), 0, 100);
        }
        return $desc;
    }

    /**
     * 医院价目表 —— GET /{lang}/hospital/{slug}/price
     */
    public function price(string $slug = '')
    {
        if ($slug === '') $this->abort404('Missing hospital slug');
        $repo = new HospitalRepository($this->lang);
        $hospital = ctype_digit($slug) ? $repo->detailById((int) $slug) : $repo->detailBySlug($slug);
        if (!$hospital) $this->abort404('Hospital not found');

        $hid = (int) $hospital['id'];

        // 1:1 对齐 hospitalPrice.vue getInfo():$http.get('Hospital/priceDetail/'+id)
        $richContent = '';
        $watermarkUrl = '';
        $isShow = true;
        $auth = new \app\service\AuthService();
        try {
            $resp = $auth->call('GET', '/Hospital/priceDetail/' . $hid);
            if (!empty($resp['ok'])) {
                $d = (array) ($resp['data'] ?? []);
                $info = (array) ($d['info'] ?? []);
                $isShow = (bool) ($d['is_show'] ?? true);
                $prefix = $this->langPrefixForPrice();
                // 按当前语言挑 content / watermark_url
                $contentKey = $prefix . 'content';
                $watermarkKey = $prefix . 'watermark_url';
                $richContent = (string) ($info[$contentKey] ?? $info['content'] ?? '');
                $watermarkUrl = (string) ($info[$watermarkKey] ?? $info['watermark_url'] ?? '');
                $richContent = htmlspecialchars_decode($richContent);
                // 用户未登录时(is_show=false)做内容遮罩
                if (empty($auth->getCurrentUser()['phone'] ?? '')) $isShow = false;
            }
        } catch (\Throwable $e) { /* fallback to DB */ }

        // 兜底:本地 hospital_price 表
        $coverArr = [];
        if ($richContent === '') {
            $price = Db::name('hospital_price')
                ->where('h_id', $hid)
                ->field(['id', 'h_id', 'cover_detail'])
                ->find();
            if ($price && !empty($price['cover_detail'])) {
                $d = json_decode((string) $price['cover_detail'], true);
                $coverArr = is_array($d) ? $d : [];
            }
        }

        $title = $hospital['name'] . ' 价目表 - 韩国医美价格透明 - BeautsGO';
        $desc  = $hospital['name'] . ' 全部医美项目价目表,中韩同价、价格透明,提前心中有数。';
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . '/hospital/' . ($hospital['slug_url'] ?? $hospital['id']) . '/price';

        $this->seo->setTdk($title, $desc, $hospital['name'] . ',价目表,韩国医美价格')
            ->setCanonical($canonical)
            ->setOg(['title' => $title, 'description' => $desc, 'image' => ($coverArr[0]['url'] ?? config('seo.org_logo')), 'url' => $canonical, 'type' => 'website'])
            ->buildOrganization()
            ->buildBreadcrumb([
                ['name' => '首页', 'url' => '/'],
                ['name' => $hospital['name'], 'url' => '/hospital/' . ($hospital['slug_url'] ?? $hospital['id'])],
                ['name' => '价目表', 'url' => '/hospital/' . ($hospital['slug_url'] ?? $hospital['id']) . '/price'],
            ]);

        return $this->render('pages/hospital/price', [
            'hospital'     => $hospital,
            'covers'       => $coverArr,
            'richContent'  => $richContent,
            'watermarkUrl' => $watermarkUrl,
            'isShow'       => $isShow,
        ]);
    }

    /**
     * 医院全部项目页(对齐 pages/project/project.vue + /hospital/{slug}/allproject)
     */
    public function allProject(string $slug = '')
    {
        if ($slug === '') $this->abort404('Missing hospital slug');
        $repo = new HospitalRepository($this->lang);
        $hospital = ctype_digit($slug) ? $repo->detailById((int) $slug) : $repo->detailBySlug($slug);
        if (!$hospital) $this->abort404('Hospital not found');

        $hid = (int) $hospital['id'];
        $page = max(1, (int) $this->request->param('page', 1));

        $repoIdx = new \app\repository\IndexRepository($this->lang);
        // 用 fetchProjectList 但限定为本医院的项目
        // 直接查 project 表更直接
        $offset = ($page - 1) * 20;
        $rows = Db::name('project')->alias('p')
            ->leftJoin('hospital h', 'h.id=p.h_id')
            ->where(['p.status' => 1, 'p.h_id' => $hid])
            ->field([
                'p.id', 'p.cover_detail', 'p.korean_won', 'p.price',
                'p.sale_nums', 'p.service_fee',
                'p.name AS zh_name', 'p.en_name',
                'p.unit AS zh_unit', 'p.en_unit',
                'p.is_recommend', 'p.sort', 'p.create_time',
            ])
            ->order('p.is_recommend desc, p.sort desc, p.create_time desc')
            ->limit($offset, 20)
            ->select()
            ->toArray();
        $total = (int) Db::name('project')->where(['status' => 1, 'h_id' => $hid])->count();

        // 归一化
        foreach ($rows as &$r) {
            $cov = json_decode((string) ($r['cover_detail'] ?? ''), true) ?: [];
            $r['cover'] = is_array($cov) ? $cov : [];
            $r['cover_url'] = $r['cover'][0]['url'] ?? '';
            $r['name'] = $r['zh_name'] ?: $r['en_name'];
            $r['unit'] = $r['zh_unit'] ?: $r['en_unit'];
            $r['hospital_name'] = $hospital['name'] ?? '';
            $r['service_fee'] = [(int) ($r['service_fee'] ?: 30000), '₩'];
            $r['slug'] = !empty($r['en_name'])
                ? preg_replace('/[^a-z0-9-]/', '', strtolower(preg_replace('/\s+/', '-', $r['en_name'])))
                : (string) $r['id'];
            unset($r['cover_detail'], $r['zh_name'], $r['zh_unit'], $r['en_unit']);
        }
        unset($r);

        $title = $hospital['name'] . ' 全部项目 - BeautsGO';
        $desc  = $hospital['name'] . ' 全部医美项目目录,涵盖整形 / 皮肤 / 抗衰 / 注射等品类。';
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . '/hospital/' . ($hospital['slug_url'] ?? $hospital['id']) . '/allproject';

        $this->seo->setTdk($title, $desc, $hospital['name'] . ',项目,医美')
            ->setCanonical($canonical)
            ->buildOrganization()
            ->buildBreadcrumb([
                ['name' => '首页', 'url' => '/'],
                ['name' => $hospital['name'], 'url' => '/hospital/' . ($hospital['slug_url'] ?? $hospital['id'])],
                ['name' => '全部项目', 'url' => '/hospital/' . ($hospital['slug_url'] ?? $hospital['id']) . '/allproject'],
            ]);

        return $this->render('pages/hospital/all-project', [
            'hospital'    => $hospital,
            'list'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'totalPages'  => max(1, (int) ceil($total / 20)),
            'filterParams'=> ['area' => 0, 'level' => 0, 'category' => 0],
            'tab'         => 1,
        ]);
    }

    private function langPrefixForPrice(): string
    {
        switch ($this->lang) {
            case 'zh-Hant': return 'zh_hant_';
            case 'en':      return 'en_';
            case 'ja':      return 'ja_';
            case 'th':      return 'th_';
            case 'ko-KR':   return 'ko_kr_';
            default:        return '';
        }
    }
}
