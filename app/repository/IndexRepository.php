<?php
declare(strict_types=1);

namespace app\repository;

use think\facade\Db;

/**
 * 首页数据仓库 —— 直接走 DB，字段对齐 beautsgo_api/IndexServices 各方法
 *
 * READ-ONLY: 本类只允许 SELECT 操作
 *
 * 跳过项(SSR 不需要):
 *   - 用户画像推荐(getUserPortraitHospital/getDoctorUserPortrait/getProjectUserPortrait)
 *   - shuffle 随机(SEO 要求稳定顺序)
 *   - wx_audit 微信审核态(默认按 status=1 取全部公开)
 *   - SOURCE_TYPE 'lgy' 人民币汇率换算(默认 KRW)
 *   - UserSearchSimpleRecord 写入
 */
class IndexRepository
{
    /** @var string 当前语言 zh-Hans / zh-Hant / en / ja / th */
    private $lang;

    /** @var string 多语言字段前缀 '' / 'zh_hant_' / 'en_' / 'ja_' / 'th_' */
    private $prefix;

    public function __construct(string $lang = 'zh-Hans')
    {
        $this->lang   = $lang;
        $this->prefix = $this->langPrefix($lang);
    }

    /* ============================================================
     *  1) 项目分类宫格 —— IndexServices::getHomeClass()
     * ============================================================ */
    public function fetchHomeClass(): array
    {
        $rows = Db::name('project_classify')
            ->where('status', 1)
            ->where('pid', 0)
            ->where('is_hot', 1)
            ->field(['id', 'icon', 'is_hot', 'bgxcx_ico',
                     $this->prefix . 'name AS name', 'sort', 'en_name'])
            ->order('sort desc, id asc')
            ->select()
            ->toArray();
        foreach ($rows as &$r) {
            if (empty($r['name'])) $r['name'] = $r['en_name'] ?? '';
            $r['slug'] = $this->normalizeSlug((string) ($r['en_name'] ?? '')) ?: (string) $r['id'];
        }
        return $rows;
    }

    /**
     * 项目分类 id → 语言感知的 name(用于面包屑 / 页面标题)
     */
    public function findClassifyNameById(int $id, string $lang = ''): ?string
    {
        $row = Db::name('project_classify')
            ->where('id', $id)
            ->where('status', 1)
            ->field([$this->prefix . 'name AS name', 'en_name', 'name AS zh_name'])
            ->find();
        if (!$row) return null;
        return (string) ($row['name'] ?: $row['en_name'] ?: $row['zh_name']);
    }

    /**
     * 项目分类 slug → id (4 级 fallback,对齐 HospitalRepository::findIdBySlug)
     */
    public function findClassifyIdBySlug(string $slug): ?int
    {
        $slugLower = strtolower($slug);
        $slugLower = preg_replace('/[^a-z0-9-]/', '', $slugLower) ?: '';
        if ($slugLower === '') return null;

        // 优先精确 en_name 转 slug 匹配
        $id = Db::name('project_classify')
            ->whereRaw('LOWER(REPLACE(en_name, " ", "-")) = ? OR LOWER(REPLACE(en_name, " ", "")) = ?',
                       [$slugLower, str_replace('-', '', $slugLower)])
            ->where('status', 1)
            ->value('id');
        if ($id) return (int) $id;

        // PHP 端 normalize 全表兜底
        $list = Db::name('project_classify')->where('status', 1)
            ->where('en_name', '<>', '')
            ->field('id,en_name')->select()->toArray();
        foreach ($list as $row) {
            if ($this->normalizeSlug((string) $row['en_name']) === $slugLower) {
                return (int) $row['id'];
            }
        }
        return null;
    }

    /**
     * 把 en_name 转成 URL 安全的 slug
     */
    public function normalizeSlug(string $enName): string
    {
        $s = strtolower($enName);
        $s = preg_replace('/\s+/', '-', $s);
        $s = preg_replace('/[^a-z0-9-]/', '', $s);
        $s = trim($s, '-');
        return preg_replace('/-+/', '-', $s) ?: '';
    }

    /* ============================================================
     *  2) Banner 轮播 —— IndexServices::getBanner()
     * ============================================================ */
    public function fetchBanner(): array
    {
        $rows = Db::name('advertise')
            ->where('status', 1)
            ->where('show_location', 1)
            ->field(['id', 'title', 'picture', 'path', 'colors', 'location', 'type'])
            ->order('sort desc')
            ->select()
            ->toArray();
        // picture 字段可能是 JSON 或纯 URL，统一规范化为 cover.url
        foreach ($rows as &$r) {
            $pic = $this->processJsonArray($r['picture'] ?? '');
            if (is_array($pic) && !empty($pic[0])) {
                $r['cover'] = is_array($pic[0]) ? $pic[0] : ['url' => $pic[0]];
            } else {
                $r['cover'] = ['url' => (string) ($r['picture'] ?? '')];
            }
        }
        return $rows;
    }

    /* ============================================================
     *  3) tab 0 医院列表 —— IndexServices::getHospitalNew()
     *      buildHospitalQuery + getRecommendProjects + getTradingAreas
     *      + getDoctorsCount + getHospitalDiscounts + processHospitalItems
     * ============================================================ */
    public function fetchHospitalList(array $filters, int $page, int $size = 10): array
    {
        $offset = max(0, ($page - 1) * $size);
        $where  = [['h.status', '=', 1]];
        if (!empty($filters['level']))  $where[] = ['l.id', '=', (int) $filters['level']];
        if (!empty($filters['area']))   $where[] = ['h.trading_area_id', '=', (int) $filters['area']];
        // 关键词搜索(多列 LIKE,对齐 projectList 同款写法)
        if (!empty($filters['kw'])) {
            $kw = '%' . trim((string) $filters['kw']) . '%';
            $where[] = ['h.name|h.en_name|h.zh_cn_address', 'like', $kw];
        }

        // 主查询(去掉 LEFT JOIN appointment 的 GROUP COUNT，单独再查 — 简化避免 GROUP BY 复杂度)
        $base = Db::name('hospital')->alias('h')
            ->leftJoin('hospital_level l', 'l.id=h.level_id')
            ->where($where);

        $total = (clone $base)->count('h.id');

        $rows = $base->field([
                'h.id', 'h.en_name', 'h.name AS zh_name',
                'h.' . $this->prefix . 'name AS name',
                'h.client_h_id', 'h.cover_detail', 'h.dept_id',
                'h.same_price', 'h.response_time', 'h.trading_area_id',
                'h.discount', 'h.discount_text', 'h.is_cooper', 'h.sort',
                'l.' . $this->prefix . 'name AS level_name',
            ])
            ->order('h.is_cooper desc, h.sort desc, h.id desc')
            ->limit($offset, $size)
            ->select()
            ->toArray();

        if (!$rows) return ['list' => [], 'total' => 0];

        $hids = array_column($rows, 'id');

        // 预约数(单独 group by)
        $apptMap = Db::name('appointment')->whereIn('h_id', $hids)
            ->group('h_id')->column('count(*) as cnt, h_id', 'h_id');

        // 商圈名映射
        $tradingIds = array_filter(array_column($rows, 'trading_area_id'));
        $tradingAreas = $tradingIds
            ? Db::name('hospital_trading_area')->whereIn('id', $tradingIds)
                  ->column($this->prefix . 'name', 'id')
            : [];

        // 医生数
        $doctorsCount = Db::name('doctors')->whereIn('h_id', $hids)
            ->where('status', 1)->group('h_id')
            ->column('count(*) as cnt, h_id', 'h_id');

        // 活动 / 积分(getHospitalDiscounts)
        $activityMap = Db::name('activity_project')->whereIn('h_id', $hids)
            ->where('status', 1)->group('h_id')->column('count(*) as cnt, h_id', 'h_id');
        $integralMap = Db::name('integral_project')->whereIn('h_id', $hids)
            ->where('status', 1)->group('h_id')->column('count(*) as cnt, h_id', 'h_id');

        // 推荐项目(每家最多 2 个)
        $recRows = Db::name('project')->whereIn('h_id', $hids)
            ->where('status', 1)->where('korean_won', '>', 0)
            ->field(['h_id', 'id', $this->prefix . 'name AS name', 'en_name', 'name AS zh_name',
                     'korean_won', $this->prefix . 'unit AS unit'])
            ->order('sort desc, create_time desc')
            ->select()->toArray();
        $recByHid = [];
        foreach ($recRows as $p) {
            $hid = $p['h_id'];
            if (!isset($recByHid[$hid])) $recByHid[$hid] = [];
            if (count($recByHid[$hid]) >= 2) continue;
            if (empty($p['name'])) $p['name'] = $p['en_name'] ?: $p['zh_name'];
            $price = $this->formatKrPrice($p['korean_won']);
            $p['price_str'] = $p['unit'] ? $price . '/' . $p['unit'] : $price;
            $recByHid[$hid][] = $p;
        }

        $hoursUnit = $this->tt('index.common.Hour', '小时');

        foreach ($rows as &$r) {
            $cover = $this->processJsonArray($r['cover_detail']);
            $r['thumb']             = $cover;
            $r['cover_url']         = $cover[0]['url'] ?? '';
            $r['trading_area_name'] = $tradingAreas[$r['trading_area_id']] ?? '';
            $r['doctors_count']     = (int) ($doctorsCount[$r['id']]['cnt'] ?? 0);
            $r['has_activity']      = isset($activityMap[$r['id']]);
            $r['has_integral']      = isset($integralMap[$r['id']]);
            $r['appointment_count'] = (int) ($apptMap[$r['id']]['cnt'] ?? 0);
            $r['response_time']     = $r['response_time'] ?: 24;
            $r['response_time_str'] = $r['response_time'] . $hoursUnit;
            $r['recommend_project'] = $recByHid[$r['id']] ?? [];
            if (empty($r['name'])) $r['name'] = $r['en_name'] ?: $r['zh_name'];
            $r['slug'] = $this->buildSlug($r);
            unset($r['cover_detail']);
        }
        return ['list' => $rows, 'total' => $total];
    }

    /* ============================================================
     *  4) tab 1 项目列表 —— IndexServices::getProject()
     * ============================================================ */
    public function fetchProjectList(array $filters, int $page, int $size = 10): array
    {
        $offset = max(0, ($page - 1) * $size);
        $where  = [['p.status', '=', 1], ['h.status', '=', 1], ['d.status', '=', 1]];

        if (!empty($filters['kw'])) {
            $kw = '%' . trim($filters['kw']) . '%';
            $where[] = ['p.name|h.name|d.name|p.en_name|h.en_name|d.en_name', 'like', $kw];
        }
        if (!empty($filters['category'])) {
            $cid = (int) $filters['category'];
            $isParent = Db::name('project_classify')->where('id', $cid)->value('pid') === 0;
            if ($isParent) {
                $children = Db::name('project_classify')->where('pid', $cid)->column('id');
                if ($children) $where[] = ['c.cate_id', 'IN', $children];
            } else {
                $where[] = ['c.cate_id', '=', $cid];
            }
        }
        if (!empty($filters['area'])) {
            $where[] = ['h.trading_area_id', '=', (int) $filters['area']];
        }

        $base = Db::name('project')->alias('p')
            ->leftJoin('doctors d', 'd.id=p.d_id')
            ->leftJoin('hospital h', 'd.h_id=h.id')
            ->leftJoin('project_cate c', 'c.project_id=p.id')
            ->where($where);

        $total = (clone $base)->group('p.id')->count('p.id');

        $rows = $base->field([
                'p.id', 'p.cover_detail', 'p.price', 'p.korean_won', 'p.d_id', 'h.same_price',
                'p.sale_nums', 'p.service_fee',
                'p.name AS zh_name', 'p.en_name',
                'p.' . $this->prefix . 'name AS name',
                'p.unit AS zh_unit', 'p.en_unit',
                'p.' . $this->prefix . 'unit AS unit',
                'h.name AS zh_hospital_name', 'h.en_name AS en_hospital_name',
                'h.' . $this->prefix . 'name AS hospital_name', 'h.client_h_id', 'h.id AS h_id',
                'd.name AS zh_doctor_name', 'd.en_name AS en_doctor_name',
                'd.' . $this->prefix . 'name AS doctor_name',
            ])
            ->group('p.id')
            ->order('p.is_recommend desc, p.sort desc, h.is_recommend desc, p.create_time desc')
            ->limit($offset, $size)
            ->select()
            ->toArray();

        foreach ($rows as &$r) {
            $cover = $this->processJsonArray($r['cover_detail']);
            $r['cover']         = $cover;
            $r['cover_url']     = $cover[0]['url'] ?? '';
            $r['korean_won']    = $this->formatKrPrice($r['korean_won']);
            if (empty($r['name']))           $r['name']           = $r['en_name'] ?: $r['zh_name'];
            if (empty($r['unit']))           $r['unit']           = $r['en_unit'] ?: $r['zh_unit'];
            if (empty($r['hospital_name'])) $r['hospital_name']  = $r['en_hospital_name'] ?: $r['zh_hospital_name'];
            if (empty($r['doctor_name']))   $r['doctor_name']    = $r['en_doctor_name'] ?: $r['zh_doctor_name'];
            // service_fee → [price, ₩](对齐 ProjectRepository::fetchDetailById)
            $r['service_fee'] = [(int) ($r['service_fee'] ?: 30000), '₩'];
            $r['slug']          = $this->buildSlug($r);
            unset($r['cover_detail']);
        }
        return ['list' => $rows, 'total' => $total];
    }

    /* ============================================================
     *  5) tab 2 医生列表 —— IndexServices::getDoctor()
     * ============================================================ */
    public function fetchDoctorList(array $filters, int $page, int $size = 10): array
    {
        $offset = max(0, ($page - 1) * $size);
        $where = [['d.status', '=', 1], ['h.status', '=', 1]];
        if (!empty($filters['area'])) $where[] = ['h.trading_area_id', '=', (int) $filters['area']];
        if (!empty($filters['kw'])) {
            $kw = '%' . trim((string) $filters['kw']) . '%';
            $where[] = ['d.name|d.en_name|h.name|h.en_name', 'like', $kw];
        }

        $base = Db::name('doctors')->alias('d')
            ->leftJoin('hospital h', 'h.id=d.h_id')
            ->leftJoin('hospital_level l', 'l.id=h.level_id')
            ->where($where);

        $total = (clone $base)->count('d.id');

        $rows = $base->field([
                'd.id', 'd.' . $this->prefix . 'name AS name',
                'd.en_name', 'd.name AS zh_name',
                'd.cover_detail', 'd.h_id',
                'l.' . $this->prefix . 'name AS hospital_level_name',
                'h.' . $this->prefix . 'name AS hospital_name',
                'h.en_name AS en_hospital_name', 'h.name AS zh_hospital_name',
                'h.client_h_id', 'd.service_fee', 'd.working_experience',
                'd.is_home', 'd.sort',
            ])
            ->order('d.is_home desc, d.sort desc, h.is_recommend desc')
            ->limit($offset, $size)
            ->select()
            ->toArray();

        if (!$rows) return ['list' => [], 'total' => 0];
        $dids = array_column($rows, 'id');

        // 医生项目 (JOIN 一次拿完)
        $projRows = Db::name('project')->whereIn('d_id', $dids)
            ->where('status', 1)
            ->field(['id', 'd_id', 'price', 'korean_won', 'unit', 'name', 'cover_detail'])
            ->select()->toArray();
        $projByDid = [];
        foreach ($projRows as $p) {
            $cov = $this->processJsonArray($p['cover_detail']);
            $p['cover'] = $cov;
            $p['cover_url'] = $cov[0]['url'] ?? '';
            $p['korean_won'] = $this->formatKrPrice($p['korean_won']);
            unset($p['cover_detail']);
            $projByDid[$p['d_id']][] = $p;
        }

        // 医生标签(三层 JOIN)
        $tagRows = Db::name('project')->alias('p')
            ->join('project_cate pc', 'pc.project_id=p.id')
            ->join('project_classify pc2', 'pc2.id=pc.cate_pid')
            ->whereIn('p.d_id', $dids)
            ->field(['p.d_id', 'pc2.' . $this->prefix . 'name AS name'])
            ->distinct(true)
            ->select()->toArray();
        $tagByDid = [];
        foreach ($tagRows as $t) {
            if (empty($t['name'])) continue;
            $tagByDid[$t['d_id']][] = $t['name'];
        }

        foreach ($rows as &$r) {
            $cover = $this->processJsonArray($r['cover_detail']);
            $r['cover']         = $cover;
            $r['cover_url']     = $cover[0]['url'] ?? '';
            if (empty($r['name']))           $r['name']          = $r['en_name'] ?: $r['zh_name'];
            if (empty($r['hospital_name'])) $r['hospital_name'] = $r['en_hospital_name'] ?: $r['zh_hospital_name'];
            $r['tag']           = isset($tagByDid[$r['id']])
                ? array_values(array_slice(array_unique($tagByDid[$r['id']]), 0, 6)) : [];
            $r['project']       = $projByDid[$r['id']] ?? [];
            $r['slug']          = $this->buildSlug($r);
            unset($r['cover_detail']);
        }
        return ['list' => $rows, 'total' => $total];
    }

    /* ============================================================
     *  6) tab 3 积分商品 + 分类横滚
     * ============================================================ */
    public function fetchIntegralClassList(): array
    {
        return Db::name('integral_classify')
            ->where('status', 1)
            ->field(['id AS classify_id', $this->prefix . 'name AS name', 'sort'])
            ->order('sort desc, id asc')
            ->select()->toArray();
    }

    public function fetchPointList(array $filters, int $page, int $size = 10): array
    {
        $offset = max(0, ($page - 1) * $size);
        $base = Db::name('integral_project')->where('status', 1);
        if (!empty($filters['category'])) {
            $base = $base->where('classify_id', (int) $filters['category']);
        }
        $total = (clone $base)->count();
        $rows = $base->field(['id', $this->prefix . 'title AS title', 'en_title',
                             'title AS zh_title', 'cover_detail', 'point', 'price'])
            ->order('sort desc, id desc')
            ->limit($offset, $size)
            ->select()->toArray();
        foreach ($rows as &$r) {
            $cov = $this->processJsonArray($r['cover_detail']);
            $r['cover']     = $cov;
            $r['cover_url'] = $cov[0]['url'] ?? '';
            if (empty($r['title'])) $r['title'] = $r['en_title'] ?: $r['zh_title'];
            $r['redeem_num'] = random_int(50, 500);  // 与 API 端 nums 一致
            unset($r['cover_detail']);
        }
        return ['list' => $rows, 'total' => $total];
    }

    /* ============================================================
     *  7) 筛选区选项 —— 商圈 / 医院特色 / 擅长项目 / 项目分类
     *      与 SearchServices::conditionList 对齐(hospital_meta 表 + trading_area + project_classify)
     * ============================================================ */
    public function fetchFilterOptions(): array
    {
        $area = Db::name('hospital_trading_area')
            ->where('status', 1)
            ->field(['id', $this->prefix . 'name AS name', 'en_name', 'name AS zh_name', 'sort'])
            ->order('sort desc, id asc')
            ->limit(30)
            ->select()->toArray();
        foreach ($area as &$a) {
            if (empty($a['name'])) $a['name'] = $a['en_name'] ?: $a['zh_name'];
        }

        // 医院特色 / 擅长项目(hospital_meta 表,type=1 特色 / type=2 擅长)
        $metaRows = Db::name('hospital_meta')
            ->where('status', 1)
            ->where('id', '<>', 27)  // 与 API 端 27='院内可退税'不展示一致
            ->field(['id', $this->prefix . 'name AS name', 'en_name', 'name AS zh_name', 'type'])
            ->order('sort asc, id asc')
            ->select()->toArray();
        $feature = $specialty = [];
        foreach ($metaRows as $m) {
            if (empty($m['name'])) $m['name'] = $m['en_name'] ?: $m['zh_name'];
            if ((int) $m['type'] === 1) $feature[]   = ['id' => $m['id'], 'name' => $m['name']];
            if ((int) $m['type'] === 2) $specialty[] = ['id' => $m['id'], 'name' => $m['name']];
        }

        // 服务语种(SearchServices::getServiceLanguageList 是 4 个固定值)
        $serviceLang = [
            ['id' => 'Chinese',     'name' => $this->tt('filter.lang.zh',   '中文')],
            ['id' => 'Japanese',    'name' => $this->tt('filter.lang.ja',   '日语')],
            ['id' => 'english',     'name' => $this->tt('filter.lang.en',   '英语')],
            ['id' => 'Traditional', 'name' => $this->tt('filter.lang.zhtw', '繁中')],
        ];

        $classify = $this->fetchHomeClass();
        return compact('area', 'feature', 'specialty', 'classify', 'serviceLang');
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

    private function tt(string $key, string $fallback): string
    {
        $tt = (array) (config('lang.' . $this->lang) ?: []);
        return (string) ($tt[$key] ?? $fallback);
    }

    /**
     * 为列表项构造 slug(优先 en_name,空则用 id) —— 与 BeautsGO 真实 URL 风格一致
     */
    private function buildSlug(array $row): string
    {
        $en = trim((string) ($row['en_name'] ?? ''));
        if ($en === '') return (string) ($row['id'] ?? '');
        $s = strtolower($en);
        $s = preg_replace('/\s+/', '-', $s);
        $s = preg_replace('/[^a-z0-9-]/', '', $s);
        $s = trim($s, '-');
        $s = preg_replace('/-+/', '-', $s);
        return $s !== '' ? $s : (string) $row['id'];
    }
}
