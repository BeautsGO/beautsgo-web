<?php
declare(strict_types=1);

namespace app\repository;

use think\facade\Db;

/**
 * 案例数据仓库 —— compare_case 表
 * type: 1=医院 / 2=医生 / 3=项目
 */
class CaseRepository
{
    private $lang;
    private $prefix;

    public function __construct(string $lang = 'zh-Hans')
    {
        $this->lang   = $lang;
        $this->prefix = $this->langPrefix($lang);
    }

    public function fetchList(array $filters, int $page, int $size = 12): array
    {
        $where = [['c.status', '=', 1]];
        if (!empty($filters['type']))    $where[] = ['c.type', '=', (int) $filters['type']];
        if (!empty($filters['with_id'])) $where[] = ['c.with_id', '=', (int) $filters['with_id']];

        $base = Db::name('compare_case')->alias('c')->where($where);
        $total = (clone $base)->count();

        $offset = max(0, ($page - 1) * $size);
        $rows = $base->field([
                'c.id', 'c.with_id', 'c.type', 'c.uid', 'c.uid_type', 'c.pictures',
                'c.content', 'c.en_content', 'c.zh_hant_content', 'c.ja_content',
                'c.create_time',
            ])
            ->order('c.create_time desc')
            ->limit($offset, $size)
            ->select()->toArray();
        if (!$rows) return ['list' => [], 'total' => $total];

        $realUids   = array_column(array_filter($rows, function ($r) { return !empty($r['uid_type']); }), 'uid');
        $virtualUids = array_column(array_filter($rows, function ($r) { return empty($r['uid_type']); }), 'uid');
        $realUsers   = $realUids ? Db::name('user')->whereIn('id', $realUids)->column('nickname,avatar', 'id') : [];
        $virtualUsers = $virtualUids ? Db::name('virtual_user')->whereIn('id', $virtualUids)->column('nickname,avatar', 'id') : [];

        $defaultAvatar = '/static/icon/default-avatar.png';
        foreach ($rows as &$r) {
            $r['content']   = $this->pickLangContent($r, 'content');
            $pics           = $this->jsonArr($r['pictures'] ?? '');
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
        return ['list' => $rows, 'total' => $total];
    }

    public function fetchDetail(int $id)
    {
        $row = Db::name('compare_case')
            ->field(['id', 'with_id', 'type', 'uid', 'uid_type', 'pictures',
                     'content', 'en_content', 'zh_hant_content', 'ja_content',
                     'create_time'])
            ->where('id', $id)
            ->where('status', 1)
            ->find();
        if (!$row) return null;

        $row['content']  = $this->pickLangContent($row, 'content');
        $pics            = $this->jsonArr($row['pictures'] ?? '');
        $row['pictures'] = $pics;

        $u = !empty($row['uid_type'])
            ? Db::name('user')->where('id', $row['uid'])->field('nickname,avatar')->find()
            : Db::name('virtual_user')->where('id', $row['uid'])->field('nickname,avatar')->find();
        $row['user'] = [
            'nickname' => $u['nickname'] ?? '匿名',
            'avatar'   => $u['avatar']   ?: '/static/icon/default-avatar.png',
        ];

        // 关联对象(type=1 hospital, 2 doctor, 3 project)
        $row['related'] = $this->fetchRelatedObject((int) $row['type'], (int) $row['with_id']);

        return $row;
    }

    private function fetchRelatedObject(int $type, int $id): array
    {
        if (!$id) return [];
        if ($type === 1) {
            $r = Db::name('hospital')
                ->field(['id', $this->prefix . 'name AS name', 'en_name', 'name AS zh_name',
                         'cover_detail', 'zh_cn_address',
                         $this->columnExists('hospital','slug') ? 'slug' : 'NULL AS slug'])
                ->where('id', $id)->find();
        } elseif ($type === 2) {
            $r = Db::name('doctors')
                ->field(['id', $this->prefix . 'name AS name', 'en_name', 'name AS zh_name', 'cover_detail',
                         $this->columnExists('doctors','slug') ? 'slug' : 'NULL AS slug'])
                ->where('id', $id)->find();
        } elseif ($type === 3) {
            $r = Db::name('project')
                ->field(['id', $this->prefix . 'name AS name', 'en_name', 'name AS zh_name',
                         'cover_detail', 'korean_won',
                         $this->columnExists('project','slug') ? 'slug' : 'NULL AS slug'])
                ->where('id', $id)->find();
        } else {
            return [];
        }
        if (!$r) return [];
        if (empty($r['name'])) $r['name'] = $r['en_name'] ?: $r['zh_name'];
        $cov = $this->jsonArr($r['cover_detail'] ?? '');
        $r['cover_url'] = $cov[0]['url'] ?? '';
        $r['type']      = $type;
        $r['typeKey']   = ['', 'hospital', 'doctor', 'project'][$type] ?? '';
        if (empty($r['slug'])) $r['slug'] = (string) $r['id'];
        unset($r['cover_detail']);
        return $r;
    }

    /* ============== Helpers ============== */
    private function pickLangContent(array $row, string $field): string
    {
        $key = $this->prefix . $field;
        $val = $row[$key] ?? $row[$field] ?? '';
        if ($val === '<p>&nbsp;</p>') $val = '';
        if (empty($val) && !empty($row['en_' . $field])) $val = $row['en_' . $field];
        if (empty($val) && !empty($row[$field]))         $val = $row[$field];
        $val = strip_tags((string) $val);
        return trim(html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    private function jsonArr($v): array
    {
        if (empty($v)) return [];
        if (is_array($v)) return $v;
        $d = json_decode((string) $v, true);
        return is_array($d) ? $d : [];
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
    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $k = $table . '.' . $column;
        if (isset($cache[$k])) return $cache[$k];
        try {
            return $cache[$k] = in_array($column, (array) Db::name($table)->getTableFields(), true);
        } catch (\Throwable $e) {
            return $cache[$k] = false;
        }
    }
}
