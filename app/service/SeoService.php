<?php
declare(strict_types=1);

namespace app\service;

/**
 * SEO 元数据 + Schema.org JSON-LD 生成器
 *
 * 1:1 移植自 beauts_app/utils/jsonld.js
 *   - buildHospital()   ↔ buildHospitalJsonLd()
 *   - buildProject()    ↔ buildProjectJsonLd()
 *   - buildDoctor()     ↔ buildDoctorJsonLd()
 *   - buildBreadcrumb() ↔ buildBreadcrumbJsonLd()
 *   - buildWebSite()    ↔ buildWebSiteJsonLd()
 *   - buildOrganization() ↔ buildOrganizationJsonLd()
 *
 * 所有 Schema 字段命名、降级逻辑、语言映射都与 JS 端保持一致，
 * 保证两端 SEO 输出语义等价（避免一次重构两套语义不同的 Schema）。
 */
class SeoService
{
    /** @var string */
    private $lang;
    /** @var string */
    private $siteUrl;

    /** @var array */
    private $meta = [
        'title'       => '',
        'description' => '',
        'keywords'    => '',
        'canonical'   => '',
        'og'          => [],
        'jsonld'      => [],
        'hreflang'    => [],
    ];

    public function __construct(string $lang = 'zh-Hans')
    {
        $this->lang    = $lang;
        $this->siteUrl = rtrim((string) config('seo.site_url'), '/');
    }

    /* ============================================================
     *  TDK
     * ============================================================ */

    public function setTdk(string $title, string $desc, string $keywords = ''): self
    {
        $brand = (string) config('seo.brand_suffix', '');
        $this->meta['title']       = mb_substr(strip_tags($title), 0, 55) . $brand;
        $this->meta['description'] = mb_substr(strip_tags($desc), 0, 155);
        $this->meta['keywords']    = $keywords !== '' ? $keywords : (string) config('seo.default_keywords');
        return $this;
    }

    public function setCanonical(string $url): self
    {
        $this->meta['canonical'] = $url;
        return $this;
    }

    public function setOg(array $og): self
    {
        $this->meta['og'] = array_merge([
            'type'      => 'website',
            'site_name' => (string) config('seo.site_name', 'BeautsGO'),
            'locale'    => $this->langTag(),
            'title'     => $this->meta['title'],
            'description' => $this->meta['description'],
        ], $og);
        return $this;
    }

    public function setHreflang(array $alternates): self
    {
        // ['zh-CN' => 'https://...', 'en' => '...', 'x-default' => '...']
        $this->meta['hreflang'] = $alternates;
        return $this;
    }

    /* ============================================================
     *  JSON-LD —— 站点级
     * ============================================================ */

    public function buildWebSite(): self
    {
        $this->meta['jsonld'][] = [
            '@context'   => 'https://schema.org',
            '@type'      => 'WebSite',
            'name'       => (string) config('seo.site_name', 'BeautsGO'),
            'url'        => $this->siteUrl,
            'inLanguage' => $this->langTag(),
            'potentialAction' => [
                '@type'  => 'SearchAction',
                'target' => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $this->siteUrl . '/{lang}/pages/search/search?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
        return $this;
    }

    public function buildOrganization(): self
    {
        $this->meta['jsonld'][] = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Organization',
            '@id'         => $this->siteUrl . '/#organization',
            'name'        => (string) config('seo.site_name', 'BeautsGO'),
            'url'         => $this->siteUrl,
            'logo'        => (string) config('seo.org_logo'),
            'description' => (string) config('seo.org_description'),
            'address'     => [
                '@type'           => 'PostalAddress',
                'addressLocality' => '首尔',
                'addressCountry'  => 'KR',
            ],
        ];
        return $this;
    }

    /* ============================================================
     *  JSON-LD —— 实体页
     * ============================================================ */

    /**
     * 医院详情 —— MedicalOrganization + LocalBusiness
     * @param array $detail     Hospital/detail API 返回的 data.info
     * @param array|null $rating ['count'=>评论数, 'avgRating'=>平均评分]，可选
     */
    public function buildHospital(array $detail, ?array $rating = null): self
    {
        if (empty($detail)) return $this;

        $name    = $detail['name']         ?? $detail['zh_cn_name']    ?? '';
        $address = $detail['address']      ?? $detail['zh_cn_address'] ?? '';
        $cover   = $detail['banner'][0]['cover'] ?? '';
        // 优先用 Repository 已规范化的 slug_url，回退 en_name/id（SEO 安全）
        $slug    = $detail['slug_url'] ?? $detail['en_name'] ?? $detail['h_id'] ?? ($detail['id'] ?? '');

        $ld = [
            '@context' => 'https://schema.org',
            '@type'    => ['MedicalOrganization', 'LocalBusiness'],
            '@id'      => $this->siteUrl . $this->langPrefix() . '/hospital/' . $slug,
            'name'     => $name,
            'url'      => $this->currentUrl(),
            'image'    => $cover,
            'parentOrganization' => ['@id' => $this->siteUrl . '/#organization'],
            'address'  => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => $address,
                'addressLocality' => '首尔',
                'addressCountry'  => 'KR',
            ],
            'description' => $detail['page_name'] ?? $name,
        ];

        // 真实评分优先；否则降级到 5 星 + 4-5 条（与 jsonld.js 行为一致）
        if (!empty($rating) && (int) ($rating['count'] ?? 0) > 0) {
            $ld['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $rating['avgRating'],
                'bestRating'  => '5',
                'worstRating' => '1',
                'reviewCount' => (string) $rating['count'],
            ];
        } else {
            $ld['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => '5',
                'bestRating'  => '5',
                'worstRating' => '1',
                'reviewCount' => (string) random_int(4, 5),
            ];
        }

        $this->meta['jsonld'][] = $ld;
        return $this;
    }

    /**
     * 项目详情 —— MedicalProcedure
     */
    public function buildProject(array $detail): self
    {
        if (empty($detail)) return $this;

        $name  = $detail['name'] ?? '';
        $cover = $detail['banner'][0]['cover'] ?? '';

        $ld = [
            '@context'    => 'https://schema.org',
            '@type'       => 'MedicalProcedure',
            'name'        => $name,
            'url'         => $this->currentUrl(),
            'image'       => $cover,
            'description' => $detail['page_name'] ?? $name,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id'   => $this->currentUrl(),
            ],
        ];

        if (!empty($detail['korean_won'])) {
            $price = (string) $detail['korean_won'];
            $ld['offers'] = [
                '@type'         => 'Offer',
                'price'         => $price,
                'priceCurrency' => 'KRW',
                'priceSpecification' => [
                    '@type'         => 'PriceSpecification',
                    'price'         => $price,
                    'priceCurrency' => 'KRW',
                ],
            ];
        }

        if (!empty($detail['hospital'])) {
            $h = $detail['hospital'];
            $ld['provider'] = [
                '@type' => 'MedicalOrganization',
                'name'  => $h['name'] ?? '',
                'url'   => $this->siteUrl . '/cn/hospital/' . ($h['en_name'] ?? $h['id'] ?? ''),
            ];
        }

        if (!empty($detail['doctors'])) {
            $ld['performer'] = [
                '@type' => 'Physician',
                'name'  => $detail['doctors']['name'] ?? '',
            ];
        }

        $this->meta['jsonld'][] = $ld;
        return $this;
    }

    /**
     * 医生详情 —— Physician
     * @param array $detail Doctors/detail data.info
     * @param array|null $resume Doctors/resume data.info (可选)
     */
    public function buildDoctor(array $detail, ?array $resume = null): self
    {
        if (empty($detail)) return $this;

        $name  = $detail['name'] ?? '';
        $cover = $detail['banner'][0]['cover'] ?? '';

        $ld = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Physician',
            'name'        => $name,
            'url'         => $this->currentUrl(),
            'image'       => $cover,
            'jobTitle'    => $resume['job_name'] ?? ($detail['job_name'] ?? ''),
            'description' => $detail['page_name'] ?? $name,
        ];

        if (!empty($detail['hospital'])) {
            $h = $detail['hospital'];
            $worksFor = [
                '@type' => 'MedicalOrganization',
                'name'  => $h['name'] ?? '',
                'url'   => $this->siteUrl . '/cn/hospital/' . ($h['en_name'] ?? $h['h_id'] ?? ''),
            ];
            if (!empty($h['zh_cn_address'])) {
                $worksFor['address'] = [
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $h['zh_cn_address'],
                    'addressLocality' => '首尔',
                    'addressCountry'  => 'KR',
                ];
            }
            $ld['worksFor'] = $worksFor;
        }

        if (!empty($resume['project']) && is_array($resume['project'])) {
            $ld['knowsAbout'] = $resume['project'];
        }

        $this->meta['jsonld'][] = $ld;
        return $this;
    }

    /**
     * 面包屑
     * @param array $items [['name' => '首页', 'url' => '/'], ...]
     */
    public function buildBreadcrumb(array $items): self
    {
        if (empty($items)) return $this;
        $prefix = $this->langPrefix();
        $list = [];
        foreach (array_values($items) as $i => $item) {
            $url = (string) ($item['url'] ?? '');
            if (!str_starts_with($url, 'http')) {
                $url = $this->siteUrl . $prefix . $url;
            }
            $list[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => (string) ($item['name'] ?? ''),
                'item'     => $url,
            ];
        }
        $this->meta['jsonld'][] = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $list,
        ];
        return $this;
    }

    /**
     * 追加任意一段 JSON-LD(用于 ItemList / FAQ 等非内置 schema)
     */
    public function addJsonLd(array $schema): self
    {
        if (!empty($schema)) $this->meta['jsonld'][] = $schema;
        return $this;
    }

    public function getBreadcrumbI18n(): array
    {
        $map = (array) config('seo.breadcrumb_i18n');
        return $map[$this->lang] ?? $map['zh-Hans'] ?? [
            'home' => 'Home', 'hospital' => 'Clinic', 'doctor' => 'Doctor', 'project' => 'Procedure',
        ];
    }

    public function toArray(): array
    {
        return $this->meta;
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    private function langPrefix(): string
    {
        $map = (array) config('seo.lang_path_map');
        return '/' . ($map[$this->lang] ?? 'cn');
    }

    private function langTag(): string
    {
        $map = (array) config('seo.lang_tag_map');
        return $map[$this->lang] ?? 'zh-CN';
    }

    private function currentUrl(): string
    {
        try {
            $url = request()->url(true);
            if (is_string($url) && $url !== '') return $url;
        } catch (\Throwable $e) {
            // ignore
        }
        return $this->siteUrl;
    }
}
