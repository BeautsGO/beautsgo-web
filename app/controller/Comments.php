<?php
declare(strict_types=1);

namespace app\controller;

use app\repository\HospitalRepository;
use app\repository\DoctorRepository;
use app\repository\ProjectRepository;
use think\facade\Db;

/**
 * 评论列表 —— GET /{lang}/comment/{type}/{with_id}
 *   type: 1=医院 2=医生 3=项目
 */
class Comments extends BaseController
{
    private const PAGE_SIZE = 20;

    public function listing(int $type = 0, int $with_id = 0)
    {
        if (!in_array($type, [1, 2, 3], true) || $with_id <= 0) {
            $this->abort404('Invalid comment listing params');
        }
        $page = max(1, (int) $this->request->param('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $total = (int) Db::name('comment')
            ->where(['type' => $type, 'with_id' => $with_id, 'status' => 2])
            ->count();

        $rows = Db::name('comment')
            ->field(['id', 'uid', 'uid_type', 'rating', 'create_time', 'mediaList',
                     'content', 'en_content', 'zh_hant_content', 'ja_content'])
            ->where(['type' => $type, 'with_id' => $with_id, 'status' => 2])
            ->order('create_time desc')
            ->limit($offset, self::PAGE_SIZE)
            ->select()->toArray();

        $prefix = $this->langPrefix();
        $realUids   = array_column(array_filter($rows, function ($r) { return !empty($r['uid_type']); }), 'uid');
        $virtualUids = array_column(array_filter($rows, function ($r) { return empty($r['uid_type']); }), 'uid');
        $realUsers   = $realUids ? Db::name('user')->whereIn('id', $realUids)->column('nickname,avatar', 'id') : [];
        $virtualUsers = $virtualUids ? Db::name('virtual_user')->whereIn('id', $virtualUids)->column('nickname,avatar', 'id') : [];

        foreach ($rows as &$r) {
            $key = $prefix . 'content';
            $val = $r[$key] ?? $r['content'] ?? '';
            if (empty($val)) $val = $r['en_content'] ?? '';
            $r['content'] = trim(html_entity_decode(strip_tags((string) $val), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $media = $r['mediaList'] ?? '';
            $arr   = is_array($media) ? $media : (json_decode((string) $media, true) ?: []);
            $r['mediaList'] = $arr;
            $u = !empty($r['uid_type']) ? ($realUsers[$r['uid']] ?? null) : ($virtualUsers[$r['uid']] ?? null);
            $r['nickname'] = $u['nickname'] ?? '匿名';
            $r['avatar']   = $u['avatar']   ?: '/static/icon/default-avatar.png';
            $r['rating']   = (int) ($r['rating'] ?? 0);
        }

        // 主体名(医院/医生/项目)
        $subjectName = $this->fetchSubjectName($type, $with_id);
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));

        $title = ($subjectName ?: ['', '机构', '医生', '项目'][$type]) . ' 用户评价 - BeautsGO';
        $desc  = '查看 ' . ($subjectName ?: '机构') . ' 的全部用户真实评价、星级评分与就医体验。';
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . '/comment/' . $type . '/' . $with_id;
        $this->seo->setTdk($title, $desc, '用户评价,医美点评')
            ->setCanonical($canonical)
            ->buildOrganization()
            ->buildBreadcrumb([
                ['name' => '首页', 'url' => '/'],
                ['name' => $subjectName ?: '评价', 'url' => '/comment/' . $type . '/' . $with_id],
            ]);

        return $this->render('pages/comment/list', [
            'comments'     => $rows,
            'total'        => $total,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'type'         => $type,
            'with_id'      => $with_id,
            'subjectName'  => $subjectName,
            'filterParams' => ['type' => $type, 'with_id' => $with_id, 'area' => 0, 'level' => 0, 'category' => 0],
            'tab'          => 0,
        ]);
    }

    private function fetchSubjectName(int $type, int $id): string
    {
        $prefix = $this->langPrefix();
        $tables = [1 => 'hospital', 2 => 'doctors', 3 => 'project'];
        $tab = $tables[$type] ?? null;
        if (!$tab) return '';
        $row = Db::name($tab)
            ->where('id', $id)
            ->field([$prefix . 'name AS name', 'en_name', 'name AS zh_name'])
            ->find();
        if (!$row) return '';
        return (string) ($row['name'] ?: ($row['en_name'] ?: $row['zh_name']));
    }

    /**
     * GET/POST /cn/comment/publish?type=1&with_id=N
     */
    public function publish()
    {
        $auth = new \app\service\AuthService();
        $type    = max(1, min(3, (int) $this->request->param('type', 1)));
        $withId  = (int) $this->request->param('with_id', 0);
        if (!$withId) $this->abort404('Missing with_id');

        $error = '';
        $saved = false;
        if ($this->request->isPost()) {
            $payload = [
                'type'    => $type,
                'with_id' => $withId,
                'rating'  => max(1, min(5, (int) $this->request->param('rating', 5))),
                'content' => trim((string) $this->request->param('content', '')),
                'apt_id'  => (int) $this->request->param('apt_id', 0),
            ];
            if ($payload['content'] === '') {
                $error = '请填写评价内容';
            } else {
                $resp = $auth->call('POST', '/comment/publish', $payload);
                if ($resp['ok']) $saved = true;
                else $error = $resp['msg'] ?: '发布失败';
            }
        }

        $subjectName = $this->fetchSubjectName($type, $withId);
        $this->seo->setTdk('写评价 - ' . $subjectName . ' - BeautsGO', '写评价', '写评价')->buildOrganization();

        return $this->render('pages/comment/publish', [
            'user'        => $auth->getCurrentUser(),
            'type'        => $type,
            'with_id'     => $withId,
            'subjectName' => $subjectName,
            'error'       => $error,
            'saved'       => $saved,
        ]);
    }

    private function langPrefix(): string
    {
        switch ($this->lang) {
            case 'zh-Hant': return 'zh_hant_';
            case 'en':      return 'en_';
            case 'ja':      return 'ja_';
            case 'th':      return 'th_';
            default:        return '';
        }
    }
}
