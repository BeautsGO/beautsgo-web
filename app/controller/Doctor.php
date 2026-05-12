<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\DoctorRepository;

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
