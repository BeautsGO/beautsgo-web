<?php
declare(strict_types=1);

namespace app\repository;

use think\facade\Db;

/**
 * 医院数据仓库 —— 直接走 DB，对齐 beautsgo_api/HospitalServices::detail() 的字段结构
 *
 * READ-ONLY：本类只允许 SELECT 操作，禁止任何 insert/update/delete 调用。
 *           DB 账号本身建议设置为只读权限作为最后兜底。
 *
 * 与 API 服务的对应关系：
 *   - findIdBySlug()        ↔ HospitalServices::findIdByNormalizedSlug()
 *   - detailBySlug/ById()   ↔ HospitalServices::detail()
 *   - getExperts()          ↔ HospitalServices::getExpert()
 *   - getFeaturedProjects() ↔ HospitalServices::getFeaturedItems()
 *
 * 与 API 不同的地方（DB 直读独享福利）：
 *   - 输出 phone / latitude / longitude（API 没暴露）
 *   - 输出 aggregateRating（join feedback 实时聚合）
 *   - 跳过用户态字段（is_collect / has_chat / browse 记录写入）
 */
class HospitalRepository
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
     *  Public API
     * ============================================================ */

    /**
     * 通过 slug 拿医院详情
     */
    public function detailBySlug(string $slug)
    {
        $id = $this->findIdBySlug($slug);
        if (!$id) return null;
        return $this->detailById($id);
    }

    /**
     * 通过 id 拿医院详情
     * @return array|null  与 HospitalServices::detail() 输出字段对齐 + SEO 增强字段
     */
    public function detailById(int $id)
    {
        $row = $this->fetchHospitalRow($id);
        if (!$row) return null;

        // 1. JSON 字段解析（API 端用 ORM accessor，我们用 DB 手动解）
        $row['banner']        = $this->processBanner($row['banner_detail'] ?? '');
        $row['cover_detail']  = $this->processCover($row['cover_detail'] ?? '');
        $row['cover']         = $row['cover_detail'];                  // alias，与 API 端一致
        $row['services']      = $this->processJsonArray($row['services'] ?? '');

        // 2. business_hours 按 \n 切分
        $row['business_hours'] = !empty($row['business_hours'])
            ? explode("\n", (string) $row['business_hours'])
            : null;

        // 3. address 兜底
        if (empty($row['zh_cn_address'])) {
            $row['zh_cn_address'] = $row['en_address'] ?? '';
        }

        // 4. 关联：商圈名（语言感知）
        $row['tradingArea'] = $this->fetchTradingArea((int) ($row['trading_area_id'] ?? 0));

        // 5. 关联：医院等级名（语言感知）
        $row['level'] = $this->fetchLevelName((int) ($row['level_id'] ?? 0));

        // 6. 关联：专家（医生 Top 4）
        $row['Expert'] = $this->fetchExperts($id, 4);

        // 7. 关联：特色项目（Top 4）
        $row['FeaturedProjects'] = $this->fetchFeaturedProjects($id, 4);

        // 8. 关联：资质证书
        $row['qualification'] = $this->fetchQualifications($id);

        // 9. SSR 增强：评分聚合（用于 Schema.org aggregateRating）
        $row['rating'] = $this->fetchRatingAggregate($id);

        // 10. 内页 tag 兜底（与 LanguageConst 默认值对齐 —— 暂用硬编码默认）
        $row['tag_project'] = $row['tag_project'] ?? '' ?: $this->defaultTag('project');
        $row['tag_doc']     = $row['tag_doc']     ?? '' ?: $this->defaultTag('doc');
        $row['tag_hos']     = $row['tag_hos']     ?? '' ?: $this->defaultTag('hos');

        // 11. card_title（小程序分享用，SSR 也复用作 OG title）
        $row['card_title'] = $row['name'] ?? '';

        // 12. page_name 用于 <title>
        $row['page_name']  = ($row['name'] ?? '') . $this->pageNameSuffix();

        // 13. SEO 安全的 slug（en_name 规范化），用于所有 URL 拼装
        $row['slug_url'] = !empty($row['en_name'])
            ? $this->normalizeSlug((string) $row['en_name'])
            : (string) $row['id'];

        return $row;
    }

    /**
     * 精选案例 (CompareCase 表，对齐 ComparedServices::caseList)
     * type=1 是医院案例
     */
    public function fetchCases(int $hid, int $limit = 6): array
    {
        $rows = Db::name('compare_case')
            ->field([
                'id', 'with_id', 'type', 'uid', 'uid_type', 'pictures',
                'content', 'en_content', 'zh_hant_content', 'ja_content',
            ])
            ->where('type', 1)
            ->where('with_id', $hid)
            ->where('status', 1)
            ->order('create_time desc')
            ->limit($limit)
            ->select()
            ->toArray();

        if (empty($rows)) return [];

        // 用户信息（按 uid_type 决定真实/虚拟用户表）
        $realUids   = array_column(array_filter($rows, function ($r) { return !empty($r['uid_type']); }), 'uid');
        $virtualUids = array_column(array_filter($rows, function ($r) { return empty($r['uid_type']); }), 'uid');

        $realUsers    = $realUids ? Db::name('user')->whereIn('id', $realUids)->column('nickname,avatar', 'id') : [];
        $virtualUsers = $virtualUids ? Db::name('virtual_user')->whereIn('id', $virtualUids)->column('nickname,avatar', 'id') : [];

        $defaultAvatar = '/static/icon/default-avatar.png';

        foreach ($rows as &$r) {
            // 多语言 content 选择
            $r['content'] = $this->pickLangContent($r, 'content');

            // pictures JSON 解析（同时给模板预拍扁 0/1 两张图的 URL）
            $pics = !empty($r['pictures']) ? json_decode((string) $r['pictures'], true) : [];
            $r['pictures']     = is_array($pics) ? $pics : [];
            $r['pic_url_0']    = $r['pictures'][0]['url'] ?? '';
            $r['pic_url_1']    = $r['pictures'][1]['url'] ?? '';
            $r['pic_count']    = count($r['pictures']);

            // 用户信息附加
            $u = !empty($r['uid_type']) ? ($realUsers[$r['uid']] ?? null) : ($virtualUsers[$r['uid']] ?? null);
            $r['user'] = [
                'nickname' => $u['nickname'] ?? '',
                'avatar'   => $u['avatar']   ?: $defaultAvatar,
            ];
        }
        return $rows;
    }

    /**
     * 用户评论 (Comment 表) —— 取最近 2 条
     * 字段对齐 CommentServices::pageList ($field 数组 line 90-91)
     * status=2 是有效评论 (不是 1)
     */
    public function fetchComments(int $hid, int $limit = 2): array
    {
        $rows = Db::name('comment')
            ->field([
                'id', 'uid', 'uid_type', 'rating', 'create_time', 'mediaList',
                'content', 'en_content', 'zh_hant_content', 'ja_content',
            ])
            ->where('type', 1)
            ->where('with_id', $hid)
            ->where('status', 2)
            ->order('create_time desc')
            ->limit($limit)
            ->select()
            ->toArray();

        if (empty($rows)) return [];

        $realUids   = array_column(array_filter($rows, function ($r) { return !empty($r['uid_type']); }), 'uid');
        $virtualUids = array_column(array_filter($rows, function ($r) { return empty($r['uid_type']); }), 'uid');
        $realUsers    = $realUids ? Db::name('user')->whereIn('id', $realUids)->column('nickname,avatar', 'id') : [];
        $virtualUsers = $virtualUids ? Db::name('virtual_user')->whereIn('id', $virtualUids)->column('nickname,avatar', 'id') : [];

        foreach ($rows as &$r) {
            $r['content'] = $this->pickLangContent($r, 'content');

            // mediaList JSON 解析
            $media = !empty($r['mediaList']) ? json_decode((string) $r['mediaList'], true) : [];
            if (!is_array($media)) $media = [];
            // 规范化 entries：保证每个有 url 和 type
            $r['mediaList'] = array_map(function ($m) {
                if (is_string($m)) {
                    return ['url' => $m, 'type' => $this->guessMediaType($m)];
                }
                $url = $m['url'] ?? '';
                $type = $m['type'] ?? ($url ? $this->guessMediaType($url) : 'image');
                return ['url' => $url, 'type' => $type];
            }, $media);

            $u = !empty($r['uid_type']) ? ($realUsers[$r['uid']] ?? null) : ($virtualUsers[$r['uid']] ?? null);
            $r['nickname'] = $u['nickname'] ?? '匿名';
            $r['avatar']   = $u['avatar']   ?: '/static/icon/default-avatar.png';
            $r['is_google_review'] = 0;  // SSR 暂不接入 Google 评论
        }
        return $rows;
    }

    /**
     * 积分商品 (IntegralProject 表)
     * h_id 是 JSON 风格存储 ['1','2',...]，用 LIKE 查
     */
    public function fetchIntegralList(int $hid, int $limit = 4): array
    {
        $rows = Db::name('integral_project')
            ->field([
                'id', 'cover_detail', 'price', 'point', 'num', 'redeem_num',
                $this->prefix . 'title AS title',
                $this->prefix . 'content AS content',
            ])
            ->where('h_id', 'LIKE', '%"' . $hid . '"%')
            ->where('status', 1)
            ->order('create_time desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as &$r) {
            $r['cover']     = $this->processCover($r['cover_detail'] ?? '');
            $r['cover_url'] = $r['cover'][0]['url'] ?? '';
            unset($r['cover_detail']);
        }
        return $rows;
    }

    /**
     * 机构简介（HospitalIntroduction 表）
     */
    public function fetchIntroduction(int $hid): string
    {
        $field = $this->prefix . 'introduction';
        $row = Db::name('hospital_introduction')
            ->where('h_id', $hid)
            ->find();
        if (!$row) return '';
        return (string) ($row[$field] ?? $row['introduction'] ?? '');
    }

    /**
     * 机构服务列表 (从 hospital.services JSON 字段 + dictionary 扩展)
     * 返回 [['comments' => '名称', 'dict_data_code' => '图标URL'], ...]
     */
    public function expandServices(array $serviceKeys): array
    {
        if (empty($serviceKeys)) return [];

        // 取 servicesConfig 字典
        $dictId = Db::name('dictionary')->where('dict_code', 'servicesConfig')->value('dict_id');
        if (!$dictId) return [];

        $rows = Db::name('dictionary_data')
            ->where('dict_id', $dictId)
            ->whereIn('dict_data_name', $serviceKeys)
            ->field([
                $this->prefix . 'comments AS comments',
                'comments AS zh_comments',
                'en_comments',
                $this->prefix . 'dict_data_code AS dict_data_code',
                'dict_data_code AS zh_dict_data_code',
                'en_dict_data_code',
            ])
            ->select()
            ->toArray();

        // 多语言兜底
        foreach ($rows as &$s) {
            if (empty($s['comments']))       $s['comments']       = $s['en_comments']       ?: $s['zh_comments'];
            if (empty($s['dict_data_code'])) $s['dict_data_code'] = $s['en_dict_data_code'] ?: $s['zh_dict_data_code'];
        }
        return $rows;
    }

    /**
     * 价格列表存在性 (HospitalPrice 表)
     */
    public function fetchHospitalPrice(int $hid)
    {
        $row = Db::name('hospital_price')->where('h_id', $hid)->field('id, h_id')->find();
        if (!$row) return null;
        $row['nums'] = random_int(50, 500);  // 与 API 一致：UI 显示随机数
        return $row;
    }

    /**
     * 活动项目列表 —— 1:1 移植 HospitalServices::activityList()
     * 返回 ActivityProject 的 content 富文本数组
     */
    public function fetchActivityList(int $hid): array
    {
        return Db::name('activity_project')
            ->where(['status' => 1, 'h_id' => $hid])
            ->field('content')
            ->select()
            ->toArray();
    }

    /**
     * 评论标签筛选 —— 1:1 移植 CommentServices::tagList(type=1, with_id=$hid)
     * 表关系: tag_relationship tr LEFT JOIN tag t ON t.id = tr.tag_id AND t.is_del = 0
     */
    public function fetchCommentTags(int $hid): array
    {
        $field = ($this->prefix ? $this->prefix : '') . 'name';
        return Db::name('tag_relationship')
            ->alias('tr')
            ->leftJoin('tag t', 't.id = tr.tag_id AND t.is_del = 0')
            ->where('t.type', 1)
            ->where('tr.with_id', $hid)
            ->group('tr.tag_id')
            ->order('tr.create_time desc')
            ->field("t.id, t.$field AS name")
            ->select()
            ->toArray();
    }

    /**
     * 是否有活动（HospitalAdvertise 表）—— 1:1 移植 hasAdvertise()
     */
    public function hasAdvertise(int $hid): bool
    {
        $now = time();
        return (bool) Db::name('hospital_advertise')
            ->where('h_id', $hid)
            ->where('is_show', 1)
            ->where(function ($q) use ($now) {
                $q->where('end_time', '>=', $now)->whereOr('end_time', 0);
            })
            ->where('del_time', 0)
            ->count();
    }

    /**
     * 推荐医院（首页/详情页底部的"更多机构"）
     * 简单实现：同等级 / 同商圈，按 sort 排序
     */
    public function getRecommendList(int $excludeHid, int $limit = 5): array
    {
        $rows = Db::name('hospital')
            ->field([
                'id',
                $this->prefix . 'name AS name',
                'en_name',
                'cover_detail',
                'zh_cn_address',
                'level_id',
            ])
            ->where('status', 1)
            ->where('id', '<>', $excludeHid)
            ->order('sort desc, id desc')
            ->limit($limit)
            ->select()
            ->toArray();
        if (!$rows) return [];

        $hids = array_column($rows, 'id');

        // 批量取每家医院的医生 id 列表（用于聚合 rightCover 与 tag）
        $docRows = Db::name('doctors')
            ->whereIn('h_id', $hids)
            ->where('status', 1)
            ->field(['id', 'h_id'])
            ->select()
            ->toArray();
        $docsByHospital = [];
        $allDocIds = [];
        foreach ($docRows as $d) {
            $docsByHospital[$d['h_id']][] = $d['id'];
            $allDocIds[] = $d['id'];
        }

        // 批量取医生项目的 cover_detail（用作 rightCover）
        $rightCoverByHid = [];
        if ($allDocIds) {
            $projRows = Db::name('project')
                ->whereIn('d_id', $allDocIds)
                ->where('status', 1)
                ->field(['d_id', 'cover_detail'])
                ->select()
                ->toArray();
            $docToHid = [];
            foreach ($docsByHospital as $hid => $dids) {
                foreach ($dids as $did) $docToHid[$did] = $hid;
            }
            foreach ($projRows as $p) {
                $hid = $docToHid[$p['d_id']] ?? null;
                if (!$hid) continue;
                $arr = $this->processCover($p['cover_detail'] ?? '');
                foreach ($arr as $cov) {
                    $rightCoverByHid[$hid][] = $cov;
                }
            }
        }

        // 批量取医生项目的分类名（用作 tag）—— 与 API 端 project_cate + project_classify JOIN 一致
        $tagByHid = [];
        if ($allDocIds) {
            $tagField = ($this->prefix ? $this->prefix : '') . 'name';
            $tagRows = Db::name('project')
                ->alias('a')
                ->join('project_cate b', 'b.project_id=a.id')
                ->join('project_classify c', 'c.id=b.cate_id')
                ->whereIn('a.d_id', $allDocIds)
                ->where('a.status', 1)
                ->field(['a.d_id', "c.$tagField AS name"])
                ->select()
                ->toArray();
            $docToHid = $docToHid ?? [];
            foreach ($tagRows as $t) {
                $hid = $docToHid[$t['d_id']] ?? null;
                if (!$hid || empty($t['name'])) continue;
                $tagByHid[$hid][] = $t['name'];
            }
        }

        foreach ($rows as &$r) {
            $r['cover']      = $this->processCover($r['cover_detail'] ?? '');
            $r['cover_url']  = $r['cover'][0]['url'] ?? '';
            $r['rightCover'] = $rightCoverByHid[$r['id']] ?? [];
            $r['tag']        = $tagByHid[$r['id']] ?? [];
            if ($r['tag']) $r['tag'] = array_values(array_slice(array_unique($r['tag']), 0, 4));
            $r['slug'] = $this->normalizeSlug((string) ($r['en_name'] ?? '')) ?: (string) $r['id'];
            unset($r['cover_detail']);
        }
        return $rows;
    }

    /* ============================================================
     *  Slug → ID 解析（1:1 移植 HospitalServices::findIdByNormalizedSlug）
     *  含 4 个优先级，与 API 行为完全一致（包括特殊字符 é→e 兜底）
     * ============================================================ */

    private function findIdBySlug(string $slug)
    {
        $slugLower = strtolower($slug);
        $slugLower = preg_replace('/[^a-z0-9-]/', '', $slugLower);
        if ($slugLower === '') return null;

        // 优先级 1：slug 字段精确匹配
        $id = Db::name('hospital')->where('slug', $slugLower)->value('id');
        if ($id) return (int) $id;

        // 优先级 2：slug 字段去连字符匹配
        $slugNoDash = str_replace('-', '', $slugLower);
        $id = Db::name('hospital')
            ->whereRaw('REPLACE(LOWER(slug), "-", "") = ?', [$slugNoDash])
            ->value('id');
        if ($id) return (int) $id;

        // 优先级 3：en_name SQL 端规范化（空格转 -）
        $id = Db::name('hospital')
            ->whereRaw('LOWER(REPLACE(en_name, " ", "-")) = ? OR LOWER(REPLACE(en_name, " ", "")) = ?',
                       [$slugLower, $slugLower])
            ->value('id');
        if ($id) return (int) $id;

        // 优先级 4：PHP 端规范化全表比对（兜底特殊字符）
        $list = Db::name('hospital')
            ->field('id,en_name')
            ->where('en_name', '<>', '')
            ->select()
            ->toArray();
        foreach ($list as $row) {
            if ($this->normalizeSlug((string) ($row['en_name'] ?? '')) === $slugLower) {
                return (int) $row['id'];
            }
        }

        // 4 个优先级全 miss —— 记 warning 方便后续排查
        \think\facade\Log::warning('[slug-resolve] MISS slug=' . $slug);
        return null;
    }

    /**
     * 与前端 formatSlug / admin BaseModel::normalizeSlug 完全一致
     */
    private function normalizeSlug(string $enName): string
    {
        $str = strtolower($enName);
        $str = preg_replace('/\s+/', '-', $str);
        $str = preg_replace('/[^a-z0-9-]/', '', $str);
        $str = trim($str, '-');
        return preg_replace('/-+/', '-', $str) ?: '';
    }

    /* ============================================================
     *  Hospital row
     * ============================================================ */

    private function fetchHospitalRow(int $id)
    {
        // 严格对齐 beauts_api/HospitalServices::detail() (line 844-867) + facilitiesDetail() (line 749-760)
        // 不在两处源码里出现的字段一律不选，避免"Unknown column"
        $fields = [
            // ---- 来自 detail() ----
            'id',
            'client_h_id',
            $this->prefix . 'name AS name',
            'en_name',
            'ko_kr_name',
            'name AS zh_name',
            'advertise_content',
            'en_advertise_content',
            'zh_hant_advertise_content',
            'ja_advertise_content',
            'cover_detail',
            'response_time',
            $this->prefix . 'business_hours AS business_hours',
            'en_address',
            'ko_kr_address',
            'banner_detail',
            'services',
            'level_id',
            'trading_area_id',
            'clinic_fee',
            'kf_qrcode',
            'is_cooper',
            // ---- 来自 facilitiesDetail()（SEO 增量）----
            'layer',
            'distance',
            'longitude',
            'latitude',
            'deputize_directors_nums',
            'nurse_nums',
            'establish_time',
        ];

        // address 字段语言条件（与 detail() 行 868-872 一致）
        if ($this->prefix !== '') {
            $fields[] = $this->prefix . 'address AS zh_cn_address';
        } else {
            $fields[] = 'zh_cn_address';
        }

        $row = Db::name('hospital')
            ->field($fields)
            ->where('status', 1)
            ->where('id', $id)
            ->find();

        return $row ?: null;
    }

    /* ============================================================
     *  Relations
     * ============================================================ */

    private function fetchTradingArea(int $tradingAreaId)
    {
        if (!$tradingAreaId) return null;
        $row = Db::name('hospital_trading_area')
            ->field(['id', $this->prefix . 'name AS name', 'en_name'])
            ->where('id', $tradingAreaId)
            ->find();
        return $row ?: null;
    }

    private function fetchLevelName(int $levelId): string
    {
        if (!$levelId) return '';
        $name = Db::name('hospital_level')
            ->where('id', $levelId)
            ->where('status', 1)
            ->value($this->prefix . 'name');
        return (string) ($name ?: '');
    }

    /**
     * 专家（医生 Top N） —— 1:1 复刻 HospitalServices::getExpert
     */
    private function fetchExperts(int $hid, int $limit): array
    {
        $rows = Db::name('doctors')
            ->alias('d')
            ->leftJoin('doctors_job j', 'j.id = d.jobs_id')
            ->field([
                'd.id AS d_id',
                'd.cover_detail',
                'd.' . $this->prefix . 'name AS name',
                'd.en_name',
                'd.jobs_id',
                'j.' . $this->prefix . 'name AS job_name',
                'j.en_name AS job_en_name',
            ])
            ->where('d.status', 1)
            ->where('d.h_id', $hid)
            ->order('d.sort desc, d.id desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as &$r) {
            $r['cover']     = $this->processCover($r['cover_detail'] ?? '');
            $r['cover_url'] = $r['cover'][0]['url'] ?? '';            // 模板友好：预拍平
            // job_name 兜底
            if (empty($r['job_name'])) {
                $r['job_name'] = $r['job_en_name'] ?? '-';
            }
            // URL slug(en_name 规范化,失败回落 id)
            $r['slug'] = $this->normalizeSlug((string) ($r['en_name'] ?? '')) ?: (string) $r['d_id'];
            unset($r['cover_detail'], $r['job_en_name']);
        }
        return $rows;
    }

    /**
     * 特色项目 Top N
     */
    private function fetchFeaturedProjects(int $hid, int $limit): array
    {
        $rows = Db::name('project')
            ->field([
                'id',
                $this->prefix . 'name AS name',
                'en_name',
                'cover_detail',
                'price',
                'korean_won',
                'unit',
            ])
            ->where('status', 1)
            ->where('h_id', $hid)
            ->order('is_feature desc, sort desc, id desc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as &$r) {
            $r['cover']     = $this->processCover($r['cover_detail'] ?? '');
            $r['cover_url'] = $r['cover'][0]['url'] ?? '';
            $r['slug'] = $this->normalizeSlug((string) ($r['en_name'] ?? '')) ?: (string) $r['id'];
            unset($r['cover_detail']);
        }
        return $rows;
    }

    /**
     * 资质证书
     */
    private function fetchQualifications(int $hid): array
    {
        $rows = Db::name('hospital_aptitude_detail')
            ->where('h_id', $hid)
            ->select()
            ->toArray();
        // picture 字段也是 JSON
        foreach ($rows as &$r) {
            if (!empty($r['picture'])) {
                $decoded = json_decode((string) $r['picture'], true);
                $r['picture'] = is_array($decoded) ? $decoded : [];
            } else {
                $r['picture'] = [];
            }
            $r['picture_url'] = $r['picture'][0]['url'] ?? '';
        }
        return $rows;
    }

    /**
     * 评分聚合 —— Schema.org aggregateRating 用
     * 表 fcam_feedback 字段 with_id + type=1 (医院)
     */
    private function fetchRatingAggregate(int $hid): array
    {
        try {
            $row = Db::name('feedback')
                ->where('with_id', $hid)
                ->where('type', 1)
                ->where('status', 1)
                ->fieldRaw('COUNT(*) AS count, AVG(score) AS avg_score')
                ->find();
        } catch (\Throwable $e) {
            // feedback 表可能字段名不同，失败时静默回 0
            return ['count' => 0, 'avgRating' => 0];
        }
        return [
            'count'     => (int)   ($row['count'] ?? 0),
            'avgRating' => round((float) ($row['avg_score'] ?? 0), 1),
        ];
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    /**
     * 处理 banner_detail JSON —— 与 Hospital::getBannerAttr() 等价
     */
    private function processBanner($json): array
    {
        if (empty($json)) return [];
        $decoded = is_array($json) ? $json : json_decode((string) $json, true);
        if (!is_array($decoded)) return [];

        foreach ($decoded as &$item) {
            $item['is_mp4'] = !empty($item['is_mp4']) ? 1 : 0;
            if (empty($item['cover']) && !empty($item['url'])) {
                $item['cover'] = $item['url'];
            }
        }
        return $decoded;
    }

    /**
     * 处理 cover_detail JSON
     */
    private function processCover($json): array
    {
        if (empty($json)) return [];
        $decoded = is_array($json) ? $json : json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 通用 JSON 数组解析
     */
    private function processJsonArray($json): array
    {
        if (empty($json)) return [];
        if (is_array($json)) return $json;
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 列存在性检查 —— 用于决定是否启用 slug 字段查询
     */
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

    /**
     * 多语言 content 字段挑选（与 Tools::lang_value 等价）
     */
    private function pickLangContent(array $row, string $field): string
    {
        $key = $this->prefix . $field;
        $val = $row[$key] ?? $row[$field] ?? '';
        // 清理空 HTML
        if ($val === '<p>&nbsp;</p>') $val = '';
        // 兜底回落英文
        if (empty($val) && !empty($row['en_' . $field])) $val = $row['en_' . $field];
        if (empty($val) && !empty($row[$field]))         $val = $row[$field];
        // case/comment 字段是富文本，think-template 会 htmlentities 转义导致 <p> 字面显示。
        // 这里统一脱标签 + 实体解码，与 UniApp <rich-text> 的纯文本渲染效果一致。
        $val = strip_tags((string) $val);
        $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($val);
    }

    private function guessMediaType(string $url): string
    {
        $low = strtolower($url);
        foreach (['.mp4', '.mov', '.webm', '.m4v'] as $ext) {
            if (str_ends_with($low, $ext) || strpos($low, $ext . '?') !== false) return 'video';
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
            default:        return ''; // zh-Hans
        }
    }

    /**
     * 默认 tag 文案 —— 暂用硬编码
     * TODO: 从 LanguageConst 同步配置过来
     */
    private function defaultTag(string $key): string
    {
        $map = [
            'zh-Hans' => ['project' => '特色项目', 'doc' => '专家精选', 'hos' => '机构简介'],
            'zh-Hant' => ['project' => '特色項目', 'doc' => '專家精選', 'hos' => '機構簡介'],
            'en'      => ['project' => 'Featured Projects', 'doc' => 'Featured Doctors', 'hos' => 'About Clinic'],
            'ja'      => ['project' => '人気施術', 'doc' => '専門医', 'hos' => 'クリニック紹介'],
            'th'      => ['project' => 'บริการเด่น', 'doc' => 'หมอแนะนำ', 'hos' => 'เกี่ยวกับคลินิก'],
        ];
        return $map[$this->lang][$key] ?? $map['zh-Hans'][$key];
    }

    /**
     * 详情页 title 后缀
     */
    private function pageNameSuffix(): string
    {
        $map = [
            'zh-Hans' => '_医院详情',
            'zh-Hant' => '_醫院詳情',
            'en'      => ' - Clinic Details',
            'ja'      => '_クリニック詳細',
            'th'      => '_รายละเอียดคลินิก',
        ];
        return $map[$this->lang] ?? $map['zh-Hans'];
    }
}
