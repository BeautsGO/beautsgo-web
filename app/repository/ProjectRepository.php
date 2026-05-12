<?php
declare(strict_types=1);

namespace app\repository;

use think\facade\Db;

/**
 * 项目数据仓库 —— 字段对齐 beautsgo_api/ProjectServices::detail()
 *
 * READ-ONLY:只 SELECT
 *
 * 跳过项:
 *   - 黑名单用户重定向到 level_id=37
 *   - is_collect 收藏态
 *   - ChatInfo / has_chat 客服在线状态
 *   - sale_nums 随机 +1 写库(产生副作用,SSR 端不做)
 *   - SOURCE_TYPE 'lgy' 人民币汇率换算(默认 KRW)
 *
 * 注意: project 表没有 h_id 字段,要通过 doctors.h_id 间接拿
 */
class ProjectRepository
{
    /** @var string */ private $lang;
    /** @var string */ private $prefix;

    public function __construct(string $lang = 'zh-Hans')
    {
        $this->lang   = $lang;
        $this->prefix = $this->langPrefix($lang);
    }

    /* ============================================================
     *  Public API
     * ============================================================ */

    public function detailBySlug(string $slug)
    {
        $id = $this->findIdBySlug($slug);
        return $id ? $this->detailById($id) : null;
    }

    public function detailById(int $id)
    {
        $row = $this->fetchProjectRow($id);
        if (!$row) return null;
        $row['doctor']   = $this->fetchDoctorForProject((int) ($row['d_id'] ?? 0));
        $hid = (int) ($row['doctor']['h_id'] ?? 0);
        $row['hospital'] = $this->fetchHospitalForProject($hid);
        $row['tag']      = $this->fetchClassifyTags($id);
        if ($row['doctor']) $row['doctor']['tag'] = $row['tag'];
        $row['related']  = $this->fetchRelatedProjects((int) ($row['cate_id'] ?? 0), $id, 4);
        return $row;
    }

    /* ============================================================
     *  Slug → ID 解析
     * ============================================================ */

    private function findIdBySlug(string $slug)
    {
        $slugLower = strtolower($slug);
        $slugLower = preg_replace('/[^a-z0-9-]/', '', $slugLower);
        if ($slugLower === '') return null;

        if ($this->columnExists('project', 'slug')) {
            $id = Db::name('project')->where('slug', $slugLower)->value('id');
            if ($id) return (int) $id;
            $noDash = str_replace('-', '', $slugLower);
            $id = Db::name('project')
                ->whereRaw('REPLACE(LOWER(slug), "-", "") = ?', [$noDash])
                ->value('id');
            if ($id) return (int) $id;
        }

        $id = Db::name('project')
            ->whereRaw('LOWER(REPLACE(en_name, " ", "-")) = ? OR LOWER(REPLACE(en_name, " ", "")) = ?',
                       [$slugLower, $slugLower])
            ->value('id');
        if ($id) return (int) $id;

        $list = Db::name('project')->field('id, en_name')->where('en_name', '<>', '')
            ->select()->toArray();
        foreach ($list as $r) {
            if ($this->normalizeSlug((string) ($r['en_name'] ?? '')) === $slugLower) {
                return (int) $r['id'];
            }
        }
        \think\facade\Log::warning('[project-slug-resolve] MISS slug=' . $slug);
        return null;
    }

    private function normalizeSlug(string $en): string
    {
        $s = strtolower($en);
        $s = preg_replace('/\s+/', '-', $s);
        $s = preg_replace('/[^a-z0-9-]/', '', $s);
        $s = trim($s, '-');
        return preg_replace('/-+/', '-', $s) ?: '';
    }

    /* ============================================================
     *  Project 主信息
     * ============================================================ */

    private function fetchProjectRow(int $id)
    {
        $fields = [
            'id', 'cover_detail', 'banner_detail', 'sale_nums', 'd_id',
            'price', 'form_id', 'cate_id', 'service_fee', 'korean_won',
            'content', 'zh_hant_content', 'en_content', 'ja_content',
            $this->prefix . 'name AS name', 'name AS zh_name', 'en_name',
            $this->prefix . 'unit AS unit', 'unit AS zh_unit', 'en_unit',
        ];
        if ($this->columnExists('project', 'slug')) $fields[] = 'slug';

        $row = Db::name('project')
            ->where('status', 1)
            ->field($fields)
            ->find($id);
        if (!$row) return null;

        // 多语言 fallback
        if (empty($row['name'])) $row['name'] = $row['en_name'] ?: $row['zh_name'];
        if (empty($row['unit'])) $row['unit'] = $row['en_unit'] ?: $row['zh_unit'];

        // 富文本 content 多语言
        $row['content'] = $this->pickContent($row);

        // cover / banner JSON 解析
        $cover = $this->processJsonArray($row['cover_detail'] ?? '');
        $banner = $this->processJsonArray($row['banner_detail'] ?? '');
        $row['cover']     = $cover;
        $row['banner']    = $banner;
        $row['cover_url'] = $cover[0]['url'] ?? '';
        unset($row['cover_detail'], $row['banner_detail']);

        // 价格
        $row['korean_won'] = $this->formatKrPrice($row['korean_won']);
        $row['price']      = $this->formatZeroPrice($row['price']);
        $row['sale_nums']  = $this->showSaleNums((int) $row['sale_nums']);

        // service_fee → [价格, 币种]
        $defaultFee = 30000;
        $row['service_fee'] = [$row['service_fee'] ?: $defaultFee, '₩'];

        return $row;
    }

    /**
     * content 富文本字段挑选 —— 与后端 Project::getContentAttr 等价
     * 数据库存的是 HTML entity 编码字符串(&lt;p&gt;...&lt;/p&gt;),要 htmlspecialchars_decode
     * 区别于 case/comment 的 pickLangContent(strip_tags 后输出纯文本)
     */
    private function pickContent(array $row): string
    {
        $key = $this->prefix . 'content';
        $val = $row[$key] ?? $row['content'] ?? '';
        if (empty($val)) $val = $row['en_content'] ?? '';
        if (empty($val)) $val = $row['content'] ?? '';
        if ($val === '<p>&nbsp;</p>') $val = '';
        return htmlspecialchars_decode((string) $val);
    }

    /* ============================================================
     *  Doctor 卡 + Hospital 卡
     * ============================================================ */

    public function fetchDoctorForProject(int $did): array
    {
        if (!$did) return [];
        $fields = [
            'a.id', 'a.cover_detail', 'a.h_id',
            'b.' . $this->prefix . 'name AS job_name',
            'b.en_name AS en_job_name', 'b.name AS zh_job_name',
            'a.' . $this->prefix . 'name AS name',
            'a.en_name', 'a.name AS zh_name',
        ];
        if ($this->columnExists('doctors', 'slug')) $fields[] = 'a.slug';

        $row = Db::name('doctors')->alias('a')
            ->leftJoin('doctors_job b', 'b.id=a.jobs_id')
            ->field($fields)
            ->where('a.id', $did)
            ->find();
        if (!$row) return [];

        if (empty($row['name']))     $row['name']     = $row['en_name'] ?: $row['zh_name'];
        if (empty($row['job_name'])) $row['job_name'] = $row['en_job_name'] ?: $row['zh_job_name'];
        $cover = $this->processJsonArray($row['cover_detail'] ?? '');
        $row['cover']     = $cover;
        $row['cover_url'] = $cover[0]['url'] ?? '';
        $row['slug'] = $row['slug'] ?? $this->normalizeSlug((string) ($row['en_name'] ?? '')) ?: (string) $row['id'];
        unset($row['cover_detail']);
        return $row;
    }

    public function fetchHospitalForProject(int $hid): array
    {
        if (!$hid) return [];
        $fields = [
            'a.id', 'a.cover_detail', 'a.dept_id', 'a.same_price', 'a.clinic_fee',
            'a.zh_cn_address', 'a.response_time', 'a.is_cooper',
            'a.en_name', 'a.name AS zh_name', 'a.client_h_id',
            'a.' . $this->prefix . 'name AS name',
            'l.' . $this->prefix . 'name AS level_name',
        ];
        if ($this->columnExists('hospital', 'slug')) $fields[] = 'a.slug';

        $row = Db::name('hospital')->alias('a')
            ->leftJoin('hospital_level l', 'a.level_id=l.id')
            ->field($fields)
            ->where('a.id', $hid)
            ->find();
        if (!$row) return [];

        if (empty($row['name'])) $row['name'] = $row['en_name'] ?: $row['zh_name'];
        $cover = $this->processJsonArray($row['cover_detail'] ?? '');
        $row['cover']     = $cover;
        $row['cover_url'] = $cover[0]['url'] ?? '';
        $row['response_time'] = $row['response_time'] ?: 24;
        $row['slug'] = $row['slug'] ?? $this->normalizeSlug((string) ($row['en_name'] ?? '')) ?: (string) $row['id'];

        // 医生数(医院聚合)
        $row['doctors_count'] = (int) Db::name('doctors')
            ->where(['h_id' => $hid, 'status' => 1])->count();
        unset($row['cover_detail']);
        return $row;
    }

    /* ============================================================
     *  Tags / Cases / Comments / Related
     * ============================================================ */

    public function fetchClassifyTags(int $pid): array
    {
        $field = 'b.' . $this->prefix . 'name AS name';
        $rows = Db::name('project_cate')->alias('a')
            ->join('project_classify b', 'b.id=a.cate_id')
            ->where(['project_id' => $pid, 'a.status' => 1])
            ->limit(4)
            ->column($field);
        return array_values(array_unique(array_filter((array) $rows)));
    }

    public function fetchCases(int $pid, int $limit = 6): array
    {
        $rows = Db::name('compare_case')
            ->field([
                'id', 'with_id', 'type', 'uid', 'uid_type', 'pictures',
                'content', 'en_content', 'zh_hant_content', 'ja_content',
            ])
            ->where('type', 3)
            ->where('with_id', $pid)
            ->where('status', 1)
            ->order('create_time desc')
            ->limit($limit)
            ->select()->toArray();
        if (!$rows) return [];

        $realUids   = array_column(array_filter($rows, function ($r) { return !empty($r['uid_type']); }), 'uid');
        $virtualUids = array_column(array_filter($rows, function ($r) { return empty($r['uid_type']); }), 'uid');
        $realUsers   = $realUids ? Db::name('user')->whereIn('id', $realUids)->column('nickname,avatar', 'id') : [];
        $virtualUsers = $virtualUids ? Db::name('virtual_user')->whereIn('id', $virtualUids)->column('nickname,avatar', 'id') : [];

        $defaultAvatar = '/static/icon/default-avatar.png';
        foreach ($rows as &$r) {
            $r['content'] = $this->pickLangContent($r, 'content');
            $pics = $this->processJsonArray($r['pictures'] ?? '');
            $r['pictures']  = $pics;
            $r['pic_url_0'] = $pics[0]['url'] ?? '';
            $r['pic_url_1'] = $pics[1]['url'] ?? '';
            $r['pic_count'] = count($pics);
            $u = !empty($r['uid_type']) ? ($realUsers[$r['uid']] ?? null) : ($virtualUsers[$r['uid']] ?? null);
            $r['user'] = [
                'nickname' => $u['nickname'] ?? '',
                'avatar'   => $u['avatar'] ?: $defaultAvatar,
            ];
        }
        return $rows;
    }

    public function fetchComments(int $pid, int $limit = 2): array
    {
        $rows = Db::name('comment')
            ->field([
                'id', 'uid', 'uid_type', 'rating', 'create_time', 'mediaList',
                'content', 'en_content', 'zh_hant_content', 'ja_content',
            ])
            ->where('type', 3)
            ->where('with_id', $pid)
            ->where('status', 2)
            ->order('create_time desc')
            ->limit($limit)
            ->select()->toArray();
        if (!$rows) return [];

        $realUids   = array_column(array_filter($rows, function ($r) { return !empty($r['uid_type']); }), 'uid');
        $virtualUids = array_column(array_filter($rows, function ($r) { return empty($r['uid_type']); }), 'uid');
        $realUsers   = $realUids ? Db::name('user')->whereIn('id', $realUids)->column('nickname,avatar', 'id') : [];
        $virtualUsers = $virtualUids ? Db::name('virtual_user')->whereIn('id', $virtualUids)->column('nickname,avatar', 'id') : [];

        foreach ($rows as &$r) {
            $r['content'] = $this->pickLangContent($r, 'content');
            $media = $this->processJsonArray($r['mediaList'] ?? '');
            $r['mediaList'] = array_map(function ($m) {
                if (is_string($m)) return ['url' => $m, 'type' => $this->guessMediaType($m)];
                $url = $m['url'] ?? '';
                return [
                    'url'  => $url,
                    'type' => $m['type'] ?? ($url ? $this->guessMediaType($url) : 'image'),
                ];
            }, is_array($media) ? $media : []);
            $u = !empty($r['uid_type']) ? ($realUsers[$r['uid']] ?? null) : ($virtualUsers[$r['uid']] ?? null);
            $r['nickname']         = $u['nickname'] ?? '匿名';
            $r['avatar']           = $u['avatar'] ?: '/static/icon/default-avatar.png';
            $r['is_google_review'] = 0;
        }
        return $rows;
    }

    public function fetchCommentTags(int $pid): array
    {
        $field = $this->prefix . 'name';
        return Db::name('tag_relationship')->alias('tr')
            ->leftJoin('tag t', 't.id=tr.tag_id AND t.is_del=0')
            ->where('t.type', 3)
            ->where('tr.with_id', $pid)
            ->group('tr.tag_id')
            ->order('tr.create_time desc')
            ->field("t.id, t.$field AS name")
            ->select()->toArray();
    }

    public function fetchRelatedProjects(int $cateId, int $excludePid, int $limit = 4): array
    {
        $where = [['p.status', '=', 1]];
        if ($excludePid) $where[] = ['p.id', '<>', $excludePid];

        $base = Db::name('project')->alias('p')->where($where);

        // 同分类
        if ($cateId) {
            $base = $base->join('project_cate pc', 'pc.project_id=p.id')
                ->where('pc.cate_id', $cateId)
                ->group('p.id');
        }

        $fields = [
            'p.id', 'p.cover_detail', 'p.korean_won', 'p.sale_nums',
            'p.' . $this->prefix . 'name AS name', 'p.en_name', 'p.name AS zh_name',
            'p.' . $this->prefix . 'unit AS unit', 'p.unit AS zh_unit', 'p.en_unit',
        ];
        if ($this->columnExists('project', 'slug')) $fields[] = 'p.slug';

        $rows = $base->field($fields)
            ->order('p.is_recommend desc, p.sort desc, p.id desc')
            ->limit($limit)
            ->select()->toArray();
        foreach ($rows as &$r) {
            if (empty($r['name'])) $r['name'] = $r['en_name'] ?: $r['zh_name'];
            if (empty($r['unit'])) $r['unit'] = $r['en_unit'] ?: $r['zh_unit'];
            $cov = $this->processJsonArray($r['cover_detail'] ?? '');
            $r['cover']     = $cov;
            $r['cover_url'] = $cov[0]['url'] ?? '';
            $r['korean_won'] = $this->formatKrPrice($r['korean_won']);
            $r['slug'] = $r['slug'] ?? $this->normalizeSlug((string) ($r['en_name'] ?? '')) ?: (string) $r['id'];
            unset($r['cover_detail']);
        }
        return $rows;
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    private function pickLangContent(array $row, string $field): string
    {
        $key = $this->prefix . $field;
        $val = $row[$key] ?? $row[$field] ?? '';
        if ($val === '<p>&nbsp;</p>') $val = '';
        if (empty($val) && !empty($row['en_' . $field])) $val = $row['en_' . $field];
        if (empty($val) && !empty($row[$field]))         $val = $row[$field];
        $val = strip_tags((string) $val);
        $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($val);
    }

    private function processJsonArray($json): array
    {
        if (empty($json)) return [];
        if (is_array($json)) return $json;
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $cols = Db::name($table)->getTableFields();
            return $cache[$key] = in_array($column, (array) $cols, true);
        } catch (\Throwable $e) {
            return $cache[$key] = false;
        }
    }

    private function guessMediaType(string $url): string
    {
        $low = strtolower($url);
        foreach (['.mp4', '.mov', '.webm', '.m4v'] as $ext) {
            if (substr($low, -strlen($ext)) === $ext || strpos($low, $ext . '?') !== false) return 'video';
        }
        return 'image';
    }

    private function langPrefix(string $lang): string
    {
        switch ($lang) {
            case 'zh-Hant': return 'zh_hant_';
            case 'en':      return 'en_';
            case 'ja':      return 'ja_';
            case 'th':      return 'th_';
            default:        return '';
        }
    }

    private function formatKrPrice($won): string
    {
        $w = (float) $won;
        if ($w >= 10000) return rtrim(rtrim(number_format($w / 10000, 1, '.', ''), '0'), '.') . '万';
        if ($w >= 1000)  return number_format($w);
        return (string) (int) $w;
    }

    private function formatZeroPrice($price): string
    {
        return ((int) $price === 0) ? '¥0' : (string) $price;
    }

    private function showSaleNums(int $n): string
    {
        if ($n >= 10000) return round($n / 10000, 1) . '万';
        return (string) $n;
    }
}
