# BeautsGO Web (PHP SSR)

`i.beautsgo.com` 的 PHP 服务端渲染版本。从 `beauts_app`（UniApp H5）1:1 还原页面结构与类名，**首屏 100% HTML 直出**，让搜索引擎可以完整抓取内容；用户交互行为（收藏/分享/客服）保留 AJAX，不影响 SEO。

## 技术栈

| 层 | 选型 |
|---|---|
| 语言 | PHP 7.2.5+（兼容你现有的 ServBay/PHP 7.2 环境） |
| 框架 | ThinkPHP 6.x（与 `beautsgo_api` 同栈） |
| 模板 | think-view 1.x |
| 数据来源 | `beautsgo_api`（独立 ThinkPHP 后端，跨域 cURL 调用） |
| 缓存 | File（默认） / Redis（生产推荐） |

## 目录结构

```
beautsgo_web/
├── app/
│   ├── controller/
│   │   ├── BaseController.php   控制器基类（注入 ApiClient + SeoService + 语言解析）
│   │   └── Index.php            首页（冒烟测试用）
│   └── service/
│       ├── ApiClient.php        cURL 单请求 + multiGet 并发批量
│       └── SeoService.php       TDK + Schema.org JSON-LD 生成器
├── view/
│   ├── layout/
│   │   ├── base.html            主布局（注入 TDK / OG / hreflang / JSON-LD / 静态资源）
│   │   ├── header.html
│   │   └── footer.html
│   └── pages/
│       └── index.html           冒烟测试页
├── public/
│   ├── index.php                Web 入口
│   ├── router.php               php-cli server 路由
│   └── static/
│       ├── css/uni.css          ⚠️ UniApp 编译产物，原样保留
│       ├── css/iconfont.css     图标字体
│       ├── css/app.css          PHP 端项目样式
│       └── fonts/               图标字体文件
├── route/app.php                路由定义
├── config/                      配置（api / seo / cache / view / route / app）
├── composer.json
└── think                        CLI 入口
```

## 快速启动

```bash
cd beautsgo_web

# 1. 安装依赖
composer install

# 2. 复制环境变量
cp .env.example .env
# 编辑 .env：把 api.base_url 改成实际后端地址

# 3. 启动开发服务
php think run
# 默认监听 http://localhost:8000

# 4. 验证 SSR
curl -s http://localhost:8000/ | head -50
# 看到 <title>、<meta name="description">、<script type="application/ld+json"> 即成功
```

## 路由约定

与 `beauts_app` H5 端 URL 完全对齐，方便外链/收录平滑迁移：

| URL | 控制器 | 用途 |
|---|---|---|
| `/` | Index@index | 默认首页 |
| `/{lang}/` | Index@index | 多语言首页（lang ∈ cn/zh/en/ja/th） |
| `/{lang}/hospital/{slug}` | Hospital@detail | 医院详情 |
| `/{lang}/project/{slug}` | Project@detail | 项目详情 |
| `/{lang}/doctor/{slug}` | Doctor@detail | 医生详情 |

## 核心架构原则

### 1. 数据聚合：禁止循环请求

控制器层必须用 `ApiClient::multiGet()` 一次性并发拉取所有依赖接口。

```php
$data = $this->api->multiGet([
    'hospital' => ['/Hospital/detail/' . $id],
    'cases'    => ['/Compared/caseList/1/' . $id],
    'doctors'  => ['/Hospital/' . $id . '/doctors', ['limit' => 10]],
], cacheTtl: 600);
```

### 2. SEO 数据全部来自 API

TDK 严禁硬编码。所有页面调用 `$this->seo->setTdk(...)->buildXxx(...)` 链式注入。Schema.org JSON-LD 生成逻辑与 `beauts_app/utils/jsonld.js` 保持一致，输出完全等价的结构化数据。

### 3. 首屏纯 HTML，无 JS 渲染主内容

所有列表分页用 `?page=N` 链接形式，禁止"加载更多" AJAX；筛选/排序用独立 URL（`?sort=hot`），让爬虫可以索引每个状态。仅以下场景保留 AJAX：

- 用户登录、收藏、点赞、分享
- 客服 IM、表单提交
- 图片懒加载（用原生 `loading="lazy"` 而非 IntersectionObserver）

### 4. CSS 三层组织

- `uni.css` — UniApp 编译产物，**保留以确保类名一致**。⚠️ 注意：包含 Vue scoped 选择器（`[data-v-xxxx]`），SSR 直出 HTML 时这些选择器不会命中，需要在 `app.css` 里重写关键页面样式或在还原模板时去掉 scoped 限定。
- `iconfont.css` — 图标字体，原样保留
- `app.css` — PHP 端项目样式（页面具体样式都加这里）

## 后续 Roadmap

| 阶段 | 内容 |
|---|---|
| ✅ 第 0 阶段 | 项目骨架、ApiClient、SeoService、Base 模板 |
| ⏳ 第 1 阶段 | Hospital 详情页 1:1 还原（最高 SEO 价值） |
| ⏳ 第 2 阶段 | Project 详情页 + Doctor 详情页 |
| ⏳ 第 3 阶段 | 列表页（医院/项目/医生）+ 首页 |
| ⏳ 第 4 阶段 | sitemap.xml 生成器 + robots.txt + 页面级 HTML 缓存 |

## 已知风险与待办

- **uni.css 包含 Vue scoped 选择器**：SSR 不会有 `data-v-xxxx` attribute，需逐页检查样式命中情况
- **后端字段缺口**（详见 `../ssr-audit-report.md`）：医院 phone/坐标、医生履历、项目时长等待后端补

## PHP 7.2 兼容性说明

本骨架已为 PHP 7.2.5+ 优化：
- `app/common.php` 内置 `str_starts_with` / `str_contains` / `str_ends_with` polyfill（PHP 8 函数）
- 没有使用 typed properties（PHP 7.4+）、`match` 表达式（PHP 8.0+）、命名参数（PHP 8.0+）
- 所有类成员属性用 `@var` PHPDoc 注释类型，便于 IDE 提示

如果将来 ServBay 升级到 PHP 8.x，可以把 `composer.json` 改回 `topthink/framework: ^8.0` + `topthink/think-view: ^2.0`，再把 `@var` PHPDoc 升级为 typed properties 即可。
