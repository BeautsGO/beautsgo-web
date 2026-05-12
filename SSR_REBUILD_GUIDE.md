# BeautsGO SSR 页面重构避坑指南

> 来源:把 `beauts_app`(UniApp H5)的页面 1:1 还原到 PHP SSR 时踩过的坑,做新页面前先读这一份。
> 不重复 `README.md` 的架构介绍,只列具体陷阱与解法。

## 1. think-template 默认 `htmlentities` 会把富文本字面化

**症状**:页面显示 `<p>下巴注射前后照</p>` 这种带尖括号的文字,不是渲染后的段落。

**原因**:`vendor/topthink/think-template/src/Template.php` 默认 `'default_filter' => 'htmlentities'`,所有 `{$xxx}` 都会被转义。富文本字段(`introduction` / `case.content` / `comment.content` 等)显示成字面 HTML 文字。

**解法**:
- **可信富文本**(后台审核过、要保留排版):模板里写 `{$xxx|raw}`
- **不可信富文本**(用户输入、不需要排版):Repository 层 `strip_tags() + html_entity_decode()` 处理后传给模板
- 永远不要全局关 `default_filter`,会引入 XSS

**已修例子**:
- `view/pages/hospital/detail.html` 的 `{$introduction|raw}`
- `HospitalRepository::pickLangContent()` 的 `strip_tags + html_entity_decode`

## 2. 富文本里 `<img width="1100">` 死宽度撑爆容器

**症状**:富文本介绍区图片溢出,页面横向滚动,docHeight 是真实页 2-3 倍。

**原因**:管理员后台编辑器导出的 `<img>` 带 `width="1100" height="3232"` 这种 attr。

**解法**:
```css
.text img { max-width: 100% !important; height: auto !important; display: block; margin: 0 auto; }
```
`!important` 是必需的,要压过 attr。

## 3. UniApp 设计稿 750rpx 与 SSR 单位适配

**症状**:桌面端访问字号巨大 / 间距错乱;mobile 看起来正常。

**原因**:UniApp 编译 `rpx → vw`(`750rpx = 100vw`),mobile viewport ≈ 设计稿宽度时正确。SSR 桌面端 viewport 是 1918,`1vw = 19.18px`,字号炸成 80px+。

**解法**:**用 CSS Container Queries (`cqw`) 替代 `vw`**:
1. 在 `app.css` 给主容器声明:
   ```css
   .page-main {
       max-width: 640px;
       margin: 0 auto;
       container-type: inline-size;
       container-name: page;
   }
   ```
2. **所有 CSS / 模板 inline style 里的 `vw` 全部换成 `cqw`**:
   ```bash
   perl -i -pe 's/(\d(?:\.\d+)?)vw\b/${1}cqw/g' file.css file.html
   ```
3. 移植 UniApp scoped scss 时,`rpx → cqw` 换算:`1rpx = 100/750 cqw ≈ 0.1333cqw`
4. 兼容性:Chrome 105+ / Safari 16+ / Firefox 110+(2022 起)。需要老浏览器加 `@supports` fallback

**注意**:`fixed` 元素 cqw 也 work —— 单位查询的是 DOM 上最近的 `container-type` 祖先,不是 containing block。

## 4. 桌面端 640 居中是全站规则

参考 `1.png` 真实站点桌面截图:**两侧白底、中间 640px 居中**,内容流和 mobile 完全一致(无桌面专属布局)。

```css
body { background: #fff; }
.page-main { max-width: 640px; margin: 0 auto; container-type: inline-size; }
.site-header { display: none; }  /* 真实 H5 无 PC 全站 nav */

/* 所有 fixed 元素都要居中限宽,否则桌面下铺满 1918 viewport */
.btns, .other-fixed-bar {
    left: 50% !important;
    right: auto !important;
    transform: translateX(-50%);
    max-width: 640px;
}
```

## 5. UniApp 组件 scoped scss 必须手动移植

**症状**:某段(如评价区、机构简介)是裸排版,字号/边距全乱。

**原因**:真实页是组件化的(`<introduce>` / `<FeedbackList>` / `<pointProduct>` …),每个组件有自己的 `<style scoped>`。`hospital-detail.css` 只编译了 `detail.vue` 自身的样式,**子组件 scoped 样式没被收集**。

**解法**:
1. 在 `beauts_app/components/<ComponentName>/<ComponentName>.vue` 找到 `<style lang="scss" scoped>` 段
2. 整段转 CSS(展开嵌套 + `rpx → cqw`)
3. 加到 `public/static/css/app.css`,选择器前缀用组件根 class(scoped 等价)

**已迁好的**:
- `FeedbackList` → app.css 的 `.feedback-list-component` 树

**还没迁的**(下次做时记得查):
- `introduce` 组件(`pages/detail/detail.vue` 的"机构简介"段当前是 inline style 应急写的)
- `pointProduct`(积分商品)
- 其他子页面 / 子组件

## 6. `php think run` 不直通静态文件

**症状**:`/static/*.css` `/static/img.png` 全 404 或 500,Playwright console 一片红。

**原因**:内置 PHP server 把所有请求都丢给 `index.php` ThinkPHP 路由 → 没匹配 → 错误。

**解法**:`public/router.php`:
```php
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;  // 让内置 server 直接 serve 该文件
}
require __DIR__ . '/index.php';
```

## 7. 静态资源拷贝是冷启动必做的一步

**症状**:模板引用 `/static/detail/play.png` `/static/icon/list-address.png` 等都 404。

**原因**:SSR 项目 `public/static/` 不包含 `beauts_app/static/` 的子目录。

**解法**(新页面开始前):
```bash
# 检查模板用到了哪些静态资源
grep -roh 'src="/static/[^"]*"' view/pages/<page>/ | sort -u

# 从 beauts_app 拷过来(整目录拷比单文件方便)
cp -r /path/to/beauts_app/static/{detail,icon,introduce,…} public/static/
```

## 8. i18n 文案以后端 `getLanguageConfig` 为权威

**症状**:模板渲染出来的按钮文案、tab 角标和真实页对不上(如真实"预约面诊",SSR 显示"立即预约")。

**原因**:真实运行时 SPA 调 `POST /api/getLanguageConfig?lang=zh-Hans` 拉文案。`beauts_app/locale/<lang>.json` 只是 fallback,优先级低。SSR 的 `config/lang.php` 是把 fallback json 抄过来的,可能与后端权威源不一致。

**解法**(任选):
- **短期**:用 `curl` 调一次 getLanguageConfig 比对差异 key,手动同步到 `config/lang.php`(适合差异少时)
- **中期**:在 `BaseController::__construct` 用 `ApiClient` 拉一次 getLanguageConfig 缓存 5-10 分钟,merge 进 `$tt`(适合多语言、文案频繁改时)

**已知差异**(本次手动同步过):`index.common.BookNow / chatNow`、`hospitalDetails.tabs.news / month`

## 9. 后端 detail 接口是分散的,要并发拉多个

**症状**:从 `detail` 接口拿不到代表院长、合作医院、活动列表等数据。

**原因**:`HospitalServices::detail()` 只返回主信息 + Expert + FeaturedProjects。"机构简介"、推荐、活动、积分、评论是独立接口:

| 接口 | service 方法 | 用途 |
|---|---|---|
| `Hospital/detail/:id` | `detail()` | 主信息、轮播、Expert、FeaturedProjects、is_collect、has_chat |
| `Hospital/facilitiesDetail/:id` | `facilitiesDetail()` | 机构简介(introduction 富文本)、layer/proportion/院长数、services、qualification、经纬度 |
| `Hospital/getEnvironment/:id` | `getEnvironmentList()` | 环境图(`environment` 字段,通常已被 introduction 替代) |
| `Hospital/getRecommend/:id` | `getRecommend()` | 推荐医院(带 tag + rightCover) |
| `Hospital/activityList/:id` | `activityList()` | 活动列表 |
| `Hospital/integralList/:id` | `integralList()` | 积分商品 |
| `Comment/pageList` (POST) | `pageList()` | 评论 |
| `Comment/tagList` (POST) | `tagList()` | 评论标签筛选 |

**解法**:SSR controller 一次性聚合 —— `ApiClient::multiGet()` 并发拉 / 或 Repository 各自方法并行调用。

## 10. Repository 直连 DB 取代 cURL ApiClient

**架构注记**:README 写"跨域 cURL 调用 beautsgo_api",**实际已经改成 Repository 直连 DB**(`config/database.php` 跑 ThinkORM)。

**好处**:省一跳 HTTP、可以加索引、SQL 调优自由
**代价**:SQL 字段要与 `services/api/*Services.php` 的 SELECT 严格对齐

**对齐方法**:
1. 找到 API 端对应 service 方法(如 `HospitalServices::detail`)
2. 抄 `field()` 列表 + `with()` 关系 + 后续字段后处理(`pickLangContent`、media JSON 解析、多语言 fallback 等)
3. **不要**自己想字段名 —— 后端有 `cover_detail / banner_detail / ko_kr_name` 这种历史命名,猜不对

**已知聚合陷阱**:
- 推荐医院的 `tag[]`:不是字段,是 `hospital → doctors → project → project_cate → project_classify` 五表 JOIN,取每家前 4 个 classify name
- 推荐医院的 `rightCover[]`:是医生的 `project.cover_detail` 累计平铺
- 评论 `tags[]`:`tag_relationship LEFT JOIN tag ON ... GROUP BY tr.tag_id`,而不是 comment 表自己的字段

## 11. slug 解析必须 4 级 fallback

**症状**:URL 里的 slug 找不到医院,但直接用 id 能访问。

**原因**:slug 与 `en_name` / `slug` 字段不一定 1:1 匹配,有特殊字符(`é→e`)、空格、连字符差异。

**解法**(已实现在 `HospitalRepository::findIdBySlug`):
1. `slug` 字段精确匹配
2. `slug` 去连字符匹配
3. `en_name` SQL 端规范化(空格转 `-`)
4. `en_name` PHP 端 normalize 全表比对(兜底特殊字符)

新页面(医生 / 项目)的 slug 解析必须复刻同样四级链路。

## 12. 本地开发 `api.yestokr.com → 127.0.0.1` 劫持

**症状**:Playwright 跑真实站点白屏,console 一堆 CORS / PNA / loopback 错误。

**原因**:`/etc/hosts` 把 `api.yestokr.com` 指到 127.0.0.1(本机 ServBay 部署的后端)。Chrome 的 Private Network Access 把 SPA 的 XHR 全拦了。

**解法**(只影响 Playwright 抓真实站,不影响 SSR 本机开发):
```bash
chromium --host-resolver-rules='MAP api.yestokr.com 104.21.68.110, EXCLUDE localhost' \
         --disable-features=BlockInsecurePrivateNetworkRequests
```
或者 Playwright launch args:
```js
args: [
  '--host-resolver-rules=MAP api.yestokr.com 104.21.68.110, EXCLUDE localhost',
  '--disable-features=BlockInsecurePrivateNetworkRequests',
]
```

## 13. SEO 注入是 SSR 的存在意义

每个详情/列表页 controller **必须**通过 `SeoService` 注入完整 TDK + Canonical + OG + JSON-LD:

```php
$this->seo
    ->setTdk($title, $desc, $keywords)
    ->setCanonical($canonical)
    ->setOg([...])
    ->buildOrganization()
    ->buildHospital($hospital, $rating)        // 视页面类型换 build* 方法
    ->buildBreadcrumb([...]);
```

- **TDK 不准硬编码**,所有字段来自 API/Repository
- **JSON-LD 与 `beauts_app/utils/jsonld.js` 保持等价输出**(参考 `Hospital::injectSeo` 的写法)
- description 控制在 ≤ 155 字符
- 兜底:数据不足时用 `advertise_content` 拼接

## 14. 链接化(避免死链)

**SSR 第一原则**:所有可以变成 URL 的交互都做成 `<a>` 链接,**不要**用 `<div onclick>` 或保留空 `<a>` 占位。蜘蛛抓不到 = SEO 价值归零。

| UniApp 真实 | SSR 应做 |
|---|---|
| `@click="toCaseList"` "查看全部" | `<a href="/{lang_seg}/case?h={hid}">查看全部</a>` |
| `@click="openMap"` 地址 | `<a href="https://map.kakao.com/?q={addr}">{addr}</a>` |
| `@click="goToAreaSearch"` 商圈 | `<a href="/{lang_seg}/hospital?area={id}">{name}</a>` |
| 分页"加载更多" | `?page=N` 链接形式 |
| 筛选/排序 tab | `?sort=hot` 等独立 URL |
| 项目卡片点击 | `<a href="/{lang_seg}/project/{slug}">` |

仅这些场景允许 AJAX:登录、收藏、点赞、分享、客服 IM、表单提交、图片懒加载。

## 15. 交互优先用 CSS,JS 是兜底

SSR 不应该依赖 JS 渲染主内容。常见交互的纯 CSS 实现:

| 交互 | 实现 |
|---|---|
| 吸顶导航 | `position: sticky; top: 0;` (祖先无 `overflow:hidden`) |
| 锚点不被吸顶遮挡 | `scroll-margin-top: <stickyHeight>` |
| Tab 切换 | `<input type="radio">` + `:checked` + `~` 兄弟选择器 |
| 弹窗显隐 | `<a href="#popup">` + `.popup:target { display: flex }` |
| 折叠/展开 | `<details><summary>` |
| 图片懒加载 | 原生 `loading="lazy"`,**不要**用 IntersectionObserver |

**实在做不到的**(自动轮播、滚动 spy 高亮)再加少量 JS,但不要超过 5KB。

## 16. 锚点与吸顶遮挡

**症状**:点 `<a href="#section">` 跳转后,目标 section 被顶部 sticky nav 遮挡一半。

**解法**:目标元素加 `scroll-margin-top: <stickyHeight>` 或 `padding-top + margin-top` 负偏移。

## 17. 接口数据形态有"列表+总数"的 wrapper

真实接口 `getRecommend` 返回 `{list: [...], count: N}`,**不是直接数组**。Repository 复刻时如果省略 wrapper,前端期待 `data[0]` 会拿不到。

提醒:抓 API 看真实形态 (`curl ... | jq`)再写 Repository,不要凭直觉。

## 18. media JSON 字段需要解析

`comment.mediaList` / `compare_case.pictures` 等是 JSON 字符串,Repository 必须 `json_decode` 并规范化:

```php
$media = !empty($r['mediaList']) ? json_decode((string) $r['mediaList'], true) : [];
$r['mediaList'] = array_map(function ($m) {
    if (is_string($m)) return ['url' => $m, 'type' => $this->guessMediaType($m)];
    return [
        'url' => $m['url'] ?? '',
        'type' => $m['type'] ?? ($m['url'] ? $this->guessMediaType($m['url']) : 'image'),
    ];
}, is_array($media) ? $media : []);
```

否则模板 `{$m.url}` 会拿到 `null` 或 JSON 字符串字面。

## 19. 多语言字段的 fallback 链

每个支持多语言的字段(name / content / address / introduction …)在数据库里有 4-5 套列:
- `name`(默认中文)
- `zh_hant_name` / `en_name` / `ja_name` / `th_name` / `ko_kr_name`

**取值规则**(已实现在 `pickLangContent`):
1. 优先取 `{prefix}name`(prefix 由 `BaseController::fieldLangPrefix()` 决定)
2. 空就回落 `en_name`
3. 还空就回落 `name`(中文)

**陷阱**:`$this->prefix` 在 zh-Hans 是**空字符串**,所以 `prefix . 'name' = 'name'`(直接是默认列),不要加错前缀。

## 20. SourceType / 服务费 / 黑名单 / 客服在线状态

这些字段 SSR 端可以**不还原**(都是登录态业务逻辑),但要在控制器里**显式置默认值**避免模板崩溃:
- `is_collect` = 0
- `has_chat` = false
- `service_fee` = 默认值(配置常量)
- `ChatInfo` = `{is_online: false}`

否则模板里 `{$hospital.ChatInfo.is_online}` 会触发空属性访问错误。

---

## 新页面开发 checklist

每开一个新页面 (`Doctor`, `Project`, `Index`, 列表页 …),按这个顺序走:

- [ ] **数据层**:对照 `app/services/api/<X>Services.php` 抄 SELECT,在 `app/repository/<X>Repository.php` 1:1 复刻;聚合数据查 §9 的接口列表,不要漏
- [ ] **slug 解析**:复刻 §11 的 4 级 fallback
- [ ] **多语言字段**:用 `$this->prefix` 取列,失败回落英文/中文
- [ ] **富文本**:`{$xxx|raw}` 或 Repository 端 `strip_tags`
- [ ] **媒体 JSON**:`json_decode` + 规范化
- [ ] **SeoService**:TDK + Canonical + OG + JSON-LD + Breadcrumb
- [ ] **静态资源**:`cp -r beauts_app/static/<xxx> public/static/`
- [ ] **scoped 子组件 CSS**:在 `beauts_app/components/` 里找,转 CSS 加到 `app.css`
- [ ] **单位**:模板 / 新 CSS 一律用 `cqw`,**不要**用 `vw`;`rpx` 按 0.1333 换算
- [ ] **链接化**:所有"查看全部 / 跳转 / 筛选 / 分页"用 `<a href>`
- [ ] **交互**:CSS 优先(`sticky / :target / :checked / details`),JS 兜底
- [ ] **fixed 元素**:`left: 50%; transform: translateX(-50%); max-width: 640px;`
- [ ] **i18n key**:用到的 key 确认 `config/lang.php` 有值,差异 key 与 `getLanguageConfig` 接口对齐
- [ ] **冷启动测试**:`php think run` → curl `/cn/<page>/<slug>` → grep `<title>` / Schema.org JSON-LD → Playwright 桌面 1918 + mobile 390 截图各跑一次
