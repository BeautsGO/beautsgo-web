<?php
declare(strict_types=1);

namespace app\repository;

use think\facade\Db;

/**
 * 医生数据仓库 —— 字段对齐 beautsgo_api/DoctorsServices::detail()
 *
 * READ-ONLY:只允许 SELECT
 *
 * 跳过项(SSR 端无需):
 *   - 黑名单用户重定向到 level_id=37(用户态)
 *   - is_collect 收藏态(走 AJAX)
 *   - has_chat / ChatInfo(走 AJAX)
 *   - browse 写入
 *   - SOURCE_TYPE 'lgy' 人民币汇率换算(SSR 默认 KRW)
 */
class DoctorRepository
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
        $row = $this->fetchDoctorRow($id);
        if (!$row) return null;
        $row['hospital']         = $this->fetchHospitalForDoctor((int) ($row['h_id'] ?? 0));
        $row['job_name']         = $this->fetchJobName((int) ($row['jobs_id'] ?? 0));
        $row['honor']            = $this->fetchHonor($id);
        $row['FeaturedProjects'] = $this->fetchFeaturedProjects($id, 4);
        $row['tag']              = $this->fetchTags($id, 6);
        return $row;
    }

    /* ============================================================
     *  Slug → ID 解析 —— 4 级 fallback,与 HospitalRepository 同结构
     * ============================================================ */

    private function findIdBySlug(string $slug)
    {
        $slugLower = strtolower($slug);
        $slugLower = preg_replace('/[^a-z0-9-]/', '', $slugLower);
        if ($slugLower === '') return null;

        // 1) slug 字段精确匹配(doctors 表有 slug 列)
        if ($this->columnExists('doctors', 'slug')) {
            $id = Db::name('doctors')->where('slug', $slugLower)->value('id');
            if ($id) return (int) $id;
            // 2) slug 去连字符
            $noDash = str_replace('-', '', $slugLower);
            $id = Db::name('doctors')
                ->whereRaw('REPLACE(LOWER(slug), "-", "") = ?', [$noDash])
                ->value('id');
            if ($id) return (int) $id;
        }

        // 3) en_name SQL 规范化
        $id = Db::name('doctors')
            ->whereRaw('LOWER(REPLACE(en_name, " ", "-")) = ? OR LOWER(REPLACE(en_name, " ", "")) = ?',
                       [$slugLower, $slugLower])
            ->value('id');
        if ($id) return (int) $id;

        // 4) PHP 端规范化全表比对
        $list = Db::name('doctors')->field('id, en_name')->where('en_name', '<>', '')
            ->select()->toArray();
        foreach ($list as $row) {
            if ($this->normalizeSlug((string) ($row['en_name'] ?? '')) === $slugLower) {
                return (int) $row['id'];
            }
        }

        \think\facade\Log::warning('[doctor-slug-resolve] MISS slug=' . $slug);
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
     *  Doctor 主信息 —— 对齐 DoctorsServices::detail L223-280
     * ============================================================ */

    private function fetchDoctorRow(int $id)
    {
        $fields = [
            'id', 'h_id', 'banner_detail', 'cover_detail',
            'age', 'blood', 'constellation', 'faith',
            'visit_time', 'jobs_id', 'service_fee',
            'en_name', 'name AS zh_name',
            $this->prefix . 'name AS name',
            'en_education', 'education AS zh_education',
            $this->prefix . 'education AS education',
            'en_school', 'school AS zh_school',
            $this->prefix . 'school AS school',
            'en_curriculum_vitae', 'curriculum_vitae AS zh_curriculum_vitae',
            $this->prefix . 'curriculum_vitae AS curriculum_vitae',
            'association', 'academic', 'paper', 'books', 'charitable',
            'working_time', 'surgical_style', 'is_experience', 'is_teach',
        ];
        // slug 列如果存在则一并取
        if ($this->columnExists('doctors', 'slug')) {
            $fields[] = 'slug';
        }

        // 注意: API 端 DoctorsServices::detail 不过滤 status,SSR 端保持一致
        $row = Db::name('doctors')
            ->field($fields)
            ->where('id', $id)
            ->find();
        if (!$row) return null;

        // 多语言字段 fallback
        foreach (['name', 'education', 'school', 'curriculum_vitae'] as $f) {
            if (empty($row[$f])) {
                $en = $row['en_' . $f] ?? '';
                $zh = $row['zh_' . $f] ?? '';
                $row[$f] = $en !== '' ? $en : $zh;
            }
        }

        // banner / cover JSON 解析(Doctors model append['cover','banner'] 的等价)
        $row['banner'] = $this->processJsonArray($row['banner_detail'] ?? '');
        $row['cover']  = $this->processJsonArray($row['cover_detail'] ?? '');
        unset($row['banner_detail'], $row['cover_detail']);

        // 多行文本 → 数组 (与 Doctors model getXxxAttr 等价)
        foreach (['association', 'academic', 'paper', 'books', 'charitable'] as $f) {
            $row[$f] = !empty($row[$f]) ? array_values(array_filter(explode("\n", (string) $row[$f]))) : [];
        }

        // visit_time JSON(后端 getVisitTime)—— SSR 端直接拼成可显示字符串
        $vt = $this->processJsonArray($row['visit_time'] ?? '');
        if (is_array($vt)) {
            $parts = [];
            foreach ($vt as $v) {
                if (is_string($v)) $parts[] = $v;
                elseif (is_array($v)) {
                    $label = (string) ($v['name'] ?? $v['week'] ?? '');
                    $time  = (string) ($v['time'] ?? $v['value'] ?? '');
                    $merged = trim($label . ($label && $time ? ' ' : '') . $time);
                    if ($merged !== '') $parts[] = $merged;
                }
            }
            $row['visit_time'] = implode(' / ', $parts);
        } else {
            $row['visit_time'] = (string) ($row['visit_time'] ?? '');
        }

        // service_fee → [价格, 币种]
        $defaultFee = 30000;  // 与 apiadmin.DEFAULT_SERVICE_FEE 同
        $row['service_fee'] = [$row['service_fee'] ?: $defaultFee, '₩'];

        // 工作年限
        if (!empty($row['working_time'])) {
            $ts = strtotime((string) $row['working_time']);
            $row['working_time'] = $ts ? date('Y/m/d', $ts) : '-';
        }

        // 兜底:cover_url
        $row['cover_url'] = $row['cover'][0]['url'] ?? '';

        return $row;
    }

    /* ============================================================
     *  执业医院(医生页"执业机构"卡)
     * ============================================================ */

    public function fetchHospitalForDoctor(int $hid): array
    {
        if (!$hid) return [];
        $field = [
            'h.id', 'h.same_price', 'h.cover_detail', 'h.level_id',
            'h.response_time', 'h.clinic_fee',
            'h.' . $this->prefix . 'name AS name',
            'h.en_name', 'h.name AS zh_name', 'h.ko_kr_name',
            'h.zh_cn_address', 'h.en_address', 'h.is_cooper', 'h.client_h_id',
            'l.' . $this->prefix . 'name AS level_name',
        ];
        if ($this->columnExists('hospital', 'slug')) $field[] = 'h.slug';

        $row = Db::name('hospital')->alias('h')
            ->leftJoin('hospital_level l', 'l.id=h.level_id')
            ->field($field)
            ->where('h.status', 1)
            ->where('h.id', $hid)
            ->find();
        if (!$row) return [];

        if (empty($row['name'])) $row['name'] = $row['en_name'] ?: $row['zh_name'];
        $cover = $this->processJsonArray($row['cover_detail'] ?? '');
        $row['cover']     = $cover;
        $row['cover_url'] = $cover[0]['url'] ?? '';
        $row['address']   = $row['zh_cn_address'] ?: $row['en_address'];
        $row['doctors_count'] = (int) Db::name('doctors')
            ->where(['h_id' => $hid, 'status' => 1])->count();
        $row['response_time'] = $row['response_time'] ?: 24;
        $row['slug'] = $row['slug'] ?? $this->normalizeSlug((string) ($row['en_name'] ?? ''))
            ?: (string) $row['id'];
        unset($row['cover_detail']);
        return $row;
    }

    private function fetchJobName(int $jid): string
    {
        if (!$jid) return '';
        $name = Db::name('doctors_job')->where('id', $jid)
            ->value($this->prefix . 'name');
        if ($name) return (string) $name;
        return (string) Db::name('doctors_job')->where('id', $jid)->value('name');
    }

    /* ============================================================
     *  Honor / FeaturedProjects / Tags
     * ============================================================ */

    private function fetchHonor(int $did): array
    {
        return Db::name('doctors_honor_detail')->where('d_id', $did)
            ->column('title') ?: [];
    }

    public function fetchFeaturedProjects(int $did, int $limit = 4): array
    {
        $rows = Db::name('project')
            ->where(['d_id' => $did, 'status' => 1])
            ->field(['id', $this->prefix . 'name AS name', 'en_name', 'name AS zh_name',
                     'cover_detail', 'price', 'korean_won',
                     $this->prefix . 'unit AS unit', 'en_unit', 'unit AS zh_unit'])
            ->order('is_feature desc, sort desc, id desc')
            ->limit($limit)
            ->select()->toArray();
        foreach ($rows as &$r) {
            if (empty($r['name'])) $r['name'] = $r['en_name'] ?: $r['zh_name'];
            if (empty($r['unit'])) $r['unit'] = $r['en_unit'] ?: $r['zh_unit'];
            $cov = $this->processJsonArray($r['cover_detail'] ?? '');
            $r['cover']     = $cov;
            $r['cover_url'] = $cov[0]['url'] ?? '';
            $r['korean_won'] = $this->formatKrPrice($r['korean_won']);
            unset($r['cover_detail']);
        }
        return $rows;
    }

    private function fetchTags(int $did, int $limit = 6): array
    {
        $field = 'c.' . $this->prefix . 'name';
        $rows = Db::name('project')->alias('a')
            ->join('project_cate b', 'b.project_id=a.id')
            ->join('project_classify c', 'c.id=b.cate_id')
            ->where(['a.d_id' => $did, 'a.status' => 1])
            ->group('c.id')
            ->limit($limit)
            ->column($field . ' AS name');
        $rows = array_filter(array_map(function ($n) { return (string) $n; }, (array) $rows));
        return array_values(array_unique($rows));
    }

    /* ============================================================
     *  Cases —— 与 HospitalRepository::fetchCases 复用同结构,只是 type=2
     * ============================================================ */

    public function fetchCases(int $did, int $limit = 6): array
    {
        $rows = Db::name('compare_case')
            ->field([
                'id', 'with_id', 'type', 'uid', 'uid_type', 'pictures',
                'content', 'en_content', 'zh_hant_content', 'ja_content',
            ])
            ->where('type', 2)
            ->where('with_id', $did)
            ->where('status', 1)
            ->order('create_time desc')
            ->limit($limit)
            ->select()
            ->toArray();
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

    /* ============================================================
     *  Comments (type=2 医生反馈) —— 复用 hospital 评论结构
     * ============================================================ */

    public function fetchComments(int $did, int $limit = 2): array
    {
        $rows = Db::name('comment')
            ->field([
                'id', 'uid', 'uid_type', 'rating', 'create_time', 'mediaList',
                'content', 'en_content', 'zh_hant_content', 'ja_content',
            ])
            ->where('type', 2)
            ->where('with_id', $did)
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

    public function fetchCommentTags(int $did): array
    {
        $field = $this->prefix . 'name';
        return Db::name('tag_relationship')->alias('tr')
            ->leftJoin('tag t', 't.id=tr.tag_id AND t.is_del=0')
            ->where('t.type', 2)
            ->where('tr.with_id', $did)
            ->group('tr.tag_id')
            ->order('tr.create_time desc')
            ->field("t.id, t.$field AS name")
            ->select()->toArray();
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

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
}
