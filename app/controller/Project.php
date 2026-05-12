<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\ProjectRepository;

/**
 * 项目详情页 —— GET /{lang}/project/{slug}
 */
class Project extends BaseController
{
    public function detail(string $slug = '')
    {
        if ($slug === '') {
            $this->abort404('Missing project slug');
        }
        $repo = new ProjectRepository($this->lang);

        $project = ctype_digit($slug) ? $repo->detailById((int) $slug) : $repo->detailBySlug($slug);
        if (!$project) {
            $this->abort404('Project not found: ' . $slug);
        }

        $pid = (int) $project['id'];

        $cases       = $repo->fetchCases($pid, 6);
        $comments    = $repo->fetchComments($pid, 2);
        $commentTags = $repo->fetchCommentTags($pid);

        $this->injectSeo($project);
        $this->trackPage('项目详情页', $pid);

        return $this->render('pages/project/detail', [
            'project'  => $project,
            'doctor'   => $project['doctor']   ?? [],
            'hospital' => $project['hospital'] ?? [],
            'tag'      => $project['tag']      ?? [],
            'related'  => $project['related']  ?? [],
            'cases'    => $cases,
            'comments' => $comments,
            'tags'     => $commentTags,
        ]);
    }

    private function injectSeo(array $p): void
    {
        $name    = (string) ($p['name'] ?? '');
        $hosName = (string) ($p['hospital']['name'] ?? '');
        $docName = (string) ($p['doctor']['name'] ?? '');

        $title = trim($name . ($hosName ? ' - ' . $hosName : '') . ' 韩国医美项目');
        $desc  = $this->buildDescription($p);

        $kwBits = array_filter([
            $name, $hosName, $docName,
            '韩国医美', '整形项目', '价格透明',
        ]);
        $keywords = implode(',', $kwBits);

        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = (string) config('seo.site_url') . '/' . $langSeg
            . '/project/' . ($p['slug'] ?? $p['id']);

        $cover = $p['cover_url']
            ?? ($p['cover'][0]['url'] ?? null)
            ?? (string) config('seo.org_logo');

        $this->seo
            ->setTdk($title, $desc, $keywords)
            ->setCanonical($canonical)
            ->setOg([
                'title'       => $title,
                'description' => $desc,
                'image'       => $cover,
                'url'         => $canonical,
                'type'        => 'product',
            ])
            ->buildOrganization()
            ->buildProject($p)
            ->buildBreadcrumb([
                ['name' => $this->seo->getBreadcrumbI18n()['home']    ?? '首页', 'url' => '/'],
                ['name' => $this->seo->getBreadcrumbI18n()['project'] ?? '项目', 'url' => '/project'],
                ['name' => $name, 'url' => '/project/' . ($p['slug'] ?? $p['id'])],
            ]);
    }

    private function buildDescription(array $p): string
    {
        $bits = [];
        if (!empty($p['name']))             $bits[] = (string) $p['name'];
        if (!empty($p['korean_won']))       $bits[] = '价格 ₩' . $p['korean_won'] . ($p['unit'] ? '/' . $p['unit'] : '');
        if (!empty($p['hospital']['name'])) $bits[] = '由 ' . $p['hospital']['name'] . ' 提供';
        if (!empty($p['doctor']['name']))   $bits[] = '主理医生 ' . $p['doctor']['name'];
        if (!empty($p['tag']))              $bits[] = '适用 ' . implode('/', array_slice($p['tag'], 0, 3));

        $desc = implode(',', $bits) . '。';
        if (mb_strlen($desc) < 60 && !empty($p['content'])) {
            $desc .= mb_substr(strip_tags((string) $p['content']), 0, 100);
        }
        return mb_substr($desc, 0, 155);
    }
}
