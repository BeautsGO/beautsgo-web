<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\DoctorRepository;
use think\facade\Db;

/**
 * 医生详情页 —— GET /{lang}/doctor/{slug}
 */
class Doctor extends BaseController
{
    public function detail(string $slug = '')
    {
        if ($slug === '') {
            $this->abort404('Missing doctor slug');
        }
        $repo = new DoctorRepository($this->lang);

        $doctor = ctype_digit($slug) ? $repo->detailById((int) $slug) : $repo->detailBySlug($slug);
        if (!$doctor) {
            $this->abort404('Doctor not found: ' . $slug);
        }

        $did = (int) $doctor['id'];

        $cases       = $repo->fetchCases($did, 6);
        $comments    = $repo->fetchComments($did, 2);
        $commentTags = $repo->fetchCommentTags($did);

        $this->injectSeo($doctor);
        $this->trackPage('医生详情页', $did);

        return $this->render('pages/doctor/detail', [
            'doctor'    => $doctor,
            'hospital'  => $doctor['hospital'] ?? [],
            'projects'  => $doctor['FeaturedProjects'] ?? [],
            'honor'     => $doctor['honor'] ?? [],
            'tags'      => $commentTags,
            'cases'     => $cases,
            'comments'  => $comments,
        ]);
    }

    /**
     * 医生案例列表(对齐 case/list.vue type=2)/{lang}/doctor/{slug}/caselist
     */
    public function caseList(string $slug = '')
    {
        if ($slug === '') $this->abort404('Missing doctor slug');
        $repo = new DoctorRepository($this->lang);
        $doctor = ctype_digit($slug) ? $repo->detailById((int) $slug) : $repo->detailBySlug($slug);
        if (!$doctor) $this->abort404('Doctor not found');

        $did = (int) $doctor['id'];
        return $this->caseListRender(2, $did, $doctor['name'] ?? '', '/doctor/' . ($doctor['slug'] ?? $doctor['id']));
    }

    /**
     * 共用案例列表渲染(type 1/2/3,with_id=$id)
     */
    private function caseListRender(int $type, int $withId, string $subjectName, string $subjectUrl)
    {
        $page = max(1, (int) $this->request->param('page', 1));
        $offset = ($page - 1) * 20;
        $rows = Db::name('compare_case')
            ->field(['id', 'with_id', 'type', 'uid', 'uid_type', 'pictures',
                     'content', 'en_content', 'zh_hant_content', 'ja_content', 'create_time'])
            ->where('type', $type)->where('with_id', $withId)->where('status', 1)
            ->order('create_time desc')
            ->limit($offset, 20)
            ->select()->toArray();
        $total = (int) Db::name('compare_case')
            ->where('type', $type)->where('with_id', $withId)->where('status', 1)->count();

        $prefix = '';
        switch ($this->lang) {
            case 'zh-Hant': $prefix = 'zh_hant_'; break;
            case 'en':      $prefix = 'en_';      break;
            case 'ja':      $prefix = 'ja_';      break;
        }
        $realUids = array_column(array_filter($rows, function ($r) { return !empty($r['uid_type']); }), 'uid');
        $virtualUids = array_column(array_filter($rows, function ($r) { return empty($r['uid_type']); }), 'uid');
        $realUsers   = $realUids ? Db::name('user')->whereIn('id', $realUids)->column('nickname,avatar', 'id') : [];
        $virtualUsers = $virtualUids ? Db::name('virtual_user')->whereIn('id', $virtualUids)->column('nickname,avatar', 'id') : [];
        $defaultAvatar = '/static/icon/default-avatar.png';

        foreach ($rows as &$r) {
            $val = $r[$prefix . 'content'] ?? $r['content'] ?? $r['en_content'] ?? '';
            $r['content'] = trim(html_entity_decode(strip_tags((string) $val), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $pics = !empty($r['pictures']) ? json_decode((string) $r['pictures'], true) : [];
            $r['pictures'] = is_array($pics) ? $pics : [];
            $r['pic_url_0'] = $r['pictures'][0]['url'] ?? '';
            $r['pic_url_1'] = $r['pictures'][1]['url'] ?? '';
            $u = !empty($r['uid_type']) ? ($realUsers[$r['uid']] ?? null) : ($virtualUsers[$r['uid']] ?? null);
            $r['user'] = ['nickname' => $u['nickname'] ?? '', 'avatar' => $u['avatar'] ?: $defaultAvatar];
        }
        unset($r);

        $title = $subjectName . ' 真实案例 - BeautsGO';
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . $subjectUrl . '/caselist';
        $this->seo->setTdk($title, $subjectName . ' 真实案例汇总,前后对照', '案例,医美前后对照')
            ->setCanonical($canonical)
            ->buildOrganization()
            ->buildBreadcrumb([
                ['name' => '首页', 'url' => '/'],
                ['name' => $subjectName, 'url' => $subjectUrl],
                ['name' => '案例', 'url' => $subjectUrl . '/caselist'],
            ]);

        return $this->render('pages/case/list', [
            'list'         => $rows,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => max(1, (int) ceil($total / 20)),
            'tab'          => 0,
            'filterParams' => ['type' => $type, 'with_id' => $withId, 'area' => 0, 'level' => 0, 'category' => 0],
        ]);
    }

    /**
     * 拼装 SEO 元数据
     */
    private function injectSeo(array $d): void
    {
        $name    = (string) ($d['name'] ?? '');
        $hosName = (string) ($d['hospital']['name'] ?? '');
        $job     = (string) ($d['job_name'] ?? '');
        $area    = $hosName ?: '韩国';

        $title = trim($name . ($job ? '·' . $job : '') . ' - ' . $area);
        $desc  = $this->buildDescription($d);

        $kwBits = array_filter([$name, $hosName, $job, '韩国医美医生', '整形医生', '皮肤科医生']);
        $keywords = implode(',', $kwBits);

        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = (string) config('seo.site_url') . '/' . $langSeg
            . '/doctor/' . ($d['slug'] ?? $d['id']);

        $cover = $d['cover_url']
            ?? ($d['banner'][0]['cover'] ?? null)
            ?? (string) config('seo.org_logo');

        $this->seo
            ->setTdk($title, $desc, $keywords)
            ->setCanonical($canonical)
            ->setOg([
                'title'       => $title,
                'description' => $desc,
                'image'       => $cover,
                'url'         => $canonical,
                'type'        => 'profile',
            ])
            ->buildOrganization()
            ->buildDoctor($d)
            ->buildBreadcrumb([
                ['name' => $this->seo->getBreadcrumbI18n()['home']   ?? '首页', 'url' => '/'],
                ['name' => $this->seo->getBreadcrumbI18n()['doctor'] ?? '医生', 'url' => '/doctor'],
                ['name' => $name, 'url' => '/doctor/' . ($d['slug'] ?? $d['id'])],
            ]);
    }

    /**
     * description ≤ 155 字
     */
    private function buildDescription(array $d): string
    {
        $bits = [];
        if (!empty($d['name']))             $bits[] = (string) $d['name'];
        if (!empty($d['job_name']))         $bits[] = (string) $d['job_name'];
        if (!empty($d['hospital']['name'])) $bits[] = '所在 ' . $d['hospital']['name'];
        if (!empty($d['education']))        $bits[] = (string) $d['education'];
        if (!empty($d['FeaturedProjects'])) $bits[] = '主理项目 ' . count($d['FeaturedProjects']) . ' 项';
        if (!empty($d['tag']))              $bits[] = '擅长 ' . implode('/', array_slice($d['tag'], 0, 3));

        $desc = implode(',', $bits) . '。';
        if (mb_strlen($desc) < 60 && !empty($d['curriculum_vitae'])) {
            $desc .= mb_substr(strip_tags((string) $d['curriculum_vitae']), 0, 100);
        }
        return mb_substr($desc, 0, 155);
    }
}
