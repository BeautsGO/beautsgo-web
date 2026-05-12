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

        // hospital_price 单条:cover JSON / nums
        $price = Db::name('hospital_price')
            ->where('h_id', $hospital['id'])
            ->field(['id', 'h_id', 'cover_detail'])
            ->find();
        $coverArr = [];
        if ($price && !empty($price['cover_detail'])) {
            $d = json_decode((string) $price['cover_detail'], true);
            $coverArr = is_array($d) ? $d : [];
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
            'hospital' => $hospital,
            'covers'   => $coverArr,
        ]);
    }
}
