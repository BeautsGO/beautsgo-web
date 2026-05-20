<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\ProjectRepository;
use think\facade\Db;

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

    /**
     * 项目案例列表(对齐 case/list.vue type=3)
     */
    public function caseList(string $slug = '')
    {
        if ($slug === '') $this->abort404('Missing project slug');
        $repo = new ProjectRepository($this->lang);
        $project = ctype_digit($slug) ? $repo->detailById((int) $slug) : $repo->detailBySlug($slug);
        if (!$project) $this->abort404('Project not found');

        $pid = (int) $project['id'];
        $subjectName = (string) ($project['name'] ?? '');
        $subjectUrl  = '/project/' . ($project['slug'] ?? $project['id']);

        $page = max(1, (int) $this->request->param('page', 1));
        $offset = ($page - 1) * 20;
        $rows = Db::name('compare_case')
            ->field(['id', 'with_id', 'type', 'uid', 'uid_type', 'pictures',
                     'content', 'en_content', 'zh_hant_content', 'ja_content', 'create_time'])
            ->where('type', 3)->where('with_id', $pid)->where('status', 1)
            ->order('create_time desc')
            ->limit($offset, 20)
            ->select()->toArray();
        $total = (int) Db::name('compare_case')
            ->where('type', 3)->where('with_id', $pid)->where('status', 1)->count();

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
            'filterParams' => ['type' => 3, 'with_id' => $pid, 'area' => 0, 'level' => 0, 'category' => 0],
        ]);
    }

    /**
     * 项目评论列表语义化别名 → 301 redirect /comment/3/{id}
     */
    public function commentList(string $slug = '')
    {
        if ($slug === '') $this->abort404('Missing project slug');
        $repo = new ProjectRepository($this->lang);
        $project = ctype_digit($slug) ? $repo->detailById((int) $slug) : $repo->detailBySlug($slug);
        if (!$project) $this->abort404('Project not found');
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        return redirect('/' . $langSeg . '/comment/3/' . (int) $project['id'], 301);
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
