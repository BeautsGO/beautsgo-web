<?php
declare(strict_types=1);

namespace app\controller;

use think\facade\Db;

/**
 * 静态页 / sitemap / robots
 *   /about /terms /privacy /qualifications
 *   /sitemap.xml /robots.txt
 *
 * 注意:类名是 Stat1c(数字 1)避开 PHP `static` 关键字
 */
class Stat1c extends BaseController
{
    public function notFound()
    {
        $this->abort404('Page Not Found');
    }

    public function about()           { return $this->renderStatic('about',  '关于我们 BeautsGO',     '关于 BeautsGO');     }
    public function terms()           { return $this->renderStatic('terms',  '服务条款 - BeautsGO',   '服务条款');           }
    public function privacy()         { return $this->renderStatic('privacy','隐私政策 - BeautsGO',   '隐私政策');           }
    public function qualifications()  { return $this->renderStatic('qual',   '资质规则 - BeautsGO',   '资质规则');           }

    private function renderStatic(string $page, string $title, string $heading)
    {
        $desc = $heading . ' - BeautsGO 韩国医美预约平台';
        $langSeg = (string) (config('seo.lang_path_map')[$this->lang] ?? 'cn');
        $canonical = config('seo.site_url') . '/' . $langSeg . '/' . $page;
        $this->seo->setTdk($title, $desc, $heading)
            ->setCanonical($canonical)
            ->buildOrganization()
            ->buildBreadcrumb([['name' => '首页', 'url' => '/'], ['name' => $heading, 'url' => '/' . $page]]);
        return $this->render('pages/static/' . $page, ['heading' => $heading]);
    }

    /**
     * sitemap.xml —— 全站 URL 索引
     */
    public function sitemap()
    {
        $base    = rtrim((string) config('seo.site_url'), '/');
        $segMap  = (array) config('seo.lang_path_map');   // ['zh-Hans'=>'cn', 'en'=>'en', ...]
        $tagMap  = (array) config('seo.lang_tag_map');    // ['zh-Hans'=>'zh-CN', ...]
        $segs    = array_values($segMap);                  // ['cn','zh','ja','en','th']
        // 主语言段(写入 <loc>),其它语言走 xhtml:link
        $primary    = $segMap['zh-Hans'] ?? 'cn';
        $xDefaultLang = 'en';
        $xDefaultSeg  = $segMap[$xDefaultLang] ?? 'en';

        // 多语言 entry 帮助函数:返回 'cn' 段下的 hreflang 列表
        $altLinks = function (string $tail) use ($base, $segMap, $tagMap, $xDefaultSeg) {
            $out = [];
            foreach ($segMap as $langKey => $seg) {
                $tag = $tagMap[$langKey] ?? $seg;
                $out[$tag] = $base . '/' . $seg . ($tail !== '' ? '/' . $tail : '');
            }
            $out['x-default'] = $base . '/' . $xDefaultSeg . ($tail !== '' ? '/' . $tail : '');
            return $out;
        };

        // 收集所有 tail(去掉 lang 前缀的路径片段)
        $tails = [];

        // 静态页
        foreach (['', 'hospital', 'doctor', 'project', 'case', 'point/shop',
                  'about', 'terms', 'privacy', 'qualifications'] as $s) {
            $tails[] = $s;
        }

        // 医院
        $hids = Db::name('hospital')->where('status', 1)
            ->field([$this->columnExists('hospital','slug') ? 'slug' : 'NULL AS slug', 'en_name', 'id'])
            ->limit(2000)->select()->toArray();
        foreach ($hids as $r) {
            $s = $r['slug'] ?: $this->slugify((string) $r['en_name']) ?: (string) $r['id'];
            $tails[] = 'hospital/' . $s;
        }
        // 医生
        $dids = Db::name('doctors')
            ->field([$this->columnExists('doctors','slug') ? 'slug' : 'NULL AS slug', 'en_name', 'id'])
            ->limit(2000)->select()->toArray();
        foreach ($dids as $r) {
            $s = $r['slug'] ?: $this->slugify((string) $r['en_name']) ?: (string) $r['id'];
            $tails[] = 'doctor/' . $s;
        }
        // 项目
        $pids = Db::name('project')->where('status', 1)
            ->field([$this->columnExists('project','slug') ? 'slug' : 'NULL AS slug', 'en_name', 'id'])
            ->limit(5000)->select()->toArray();
        foreach ($pids as $r) {
            $s = $r['slug'] ?: $this->slugify((string) $r['en_name']) ?: (string) $r['id'];
            $tails[] = 'project/' . $s;
        }
        // 项目分类(首页宫格点击落地的高 SEO 价值页)
        $classes = Db::name('project_classify')
            ->where('status', 1)->where('pid', 0)->where('is_hot', 1)
            ->field(['en_name', 'id'])
            ->select()->toArray();
        foreach ($classes as $c) {
            $s = $this->slugify((string) $c['en_name']) ?: (string) $c['id'];
            $tails[] = 'projects/category/' . $s;
        }

        $today = date('Y-m-d');
        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\""
              . " xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";
        foreach ($tails as $tail) {
            $loc = $base . '/' . $primary . ($tail !== '' ? '/' . $tail : '');
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
            foreach ($altLinks($tail) as $tag => $href) {
                $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"" . htmlspecialchars($tag, ENT_XML1)
                     . "\" href=\"" . htmlspecialchars($href, ENT_XML1) . "\"/>\n";
            }
            $xml .= "    <lastmod>$today</lastmod><changefreq>weekly</changefreq>\n";
            $xml .= "  </url>\n";
        }
        $xml .= "</urlset>\n";
        return response($xml)->contentType('application/xml; charset=utf-8');
    }

    /**
     * robots.txt
     */
    public function robots()
    {
        $base = rtrim((string) config('seo.site_url'), '/');
        $body = "User-agent: *\nAllow: /\n\nSitemap: $base/sitemap.xml\n";
        return response($body)->contentType('text/plain; charset=utf-8');
    }

    private function slugify(string $en): string
    {
        $s = strtolower($en);
        $s = preg_replace('/\s+/', '-', $s);
        $s = preg_replace('/[^a-z0-9-]/', '', $s);
        return trim(preg_replace('/-+/', '-', $s), '-');
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
