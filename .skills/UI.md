---
name: ui
description: >-
  NiuNiuCMS UI/模板说明：一键创建前台主题、标签与 {block:} 块位、后台视图注意点。
  用户说「做模板」「新建主题」「一键模板」「ui」「block」「改前台/后台页面」时使用；
  优先只读本文件，勿大范围翻现有主题源码。
---

# UI 与模板说明

本文件位于 `.skills/UI.md`。创建/改前台主题、查 `{block:}` 时**优先只读本文件**；不要为了「参考」递归翻几十套主题源码。

对照实现若不够：只允许再打开 `template/default/` 单套，或 `core/model/block/*.php` 查块位签名。

---

## 0. 总览（前台 / 后台 / 资源 / 排障）

### 前台模板

- 链路：`route/` + `App\Service\Front` + `template/`
- 模板变量默认**不自动转义**，输出前先确认边界
- 改模板后优先看真实渲染结果，不只看源码

### 后台视图

- 链路：`core/admin/route/` + `App\Service\Admin` + `core/admin/html/`
- 卡片、表格、表单结构要保持闭合，避免版式错位
- 表格内表单尽量用表格外 `form` + `form="<id>"` 关联

### 资源使用

- 静态资源优先站内文件；不新增公共 CDN
- 上传、图标、按钮、弹窗等改动，尽量复用现有样式体系
- 前台主题路径用 `{$path_tpl}...`

### 常见问题

- 页面空白：先看 `runtime/log/`，再看编译产物
- 样式错位：先查容器闭合和表单嵌套
- 模板改了没生效：清对应缓存产物，再重载 PHP-FPM

### 验证建议

- 前台：首页 → 列表 → 详情/播放至少一条链路
- 后台：对应页能渲染、提交、回显
- 弹窗/表格/筛选条优先做一次浏览器实测

---

## 何时用（一键主题）

- 新建一套前台主题：`template/<dir>/`
- 改首页区块、列表卡片、播放侧栏等版式
- 用户提到：一键模板、新建主题、ui、`{block:}`

## 硬规则

1. **只读本文件**（必要时再读 `template/default/`）。不要扫其它主题大目录。
2. **落盘**：`template/<dir>/`（`<dir>` = `[A-Za-z0-9_-]+`，建议小写短名）。缺页会回落 `default`，仍应写齐必备页。
3. **主题包内不要写 PHP 业务**；用 `{inc:}` / `{block:}` / `{loop:}` / `{if:}`。
4. **资源本地化**：CSS/JS/图放主题目录，路径用 `{$path_tpl}...`；不新加公共 CDN。
5. **改完验证**：清模板缓存后看真实渲染（首页 → 列表 → 详情/播放一条链路）。

## 一键流程

```
Task:
- [ ] 1. 确认 dir 名、中文名、视觉方向
- [ ] 2. 建目录树（见「目录骨架」）
- [ ] 3. 写 conf.json + icon.png
- [ ] 4. 按 stub 写出必备 html（保留标签/变量/{block:}，改 class 与 CSS）
- [ ] 5. 写 css/js（本地）
- [ ] 6. 后台启用该主题；清 runtime 模板缓存
- [ ] 7. 回报路径与必备页清单
```

默认：`dir=myui`，名称用用户中文名或「新主题」。

## 交付检查

- [ ] `conf.json` 可解析
- [ ] 必备页齐全；播放页含 `iframe src="{$player}"`
- [ ] 列表主数据用页内 `$videolist`；侧栏/首页区块用 `{block:...}...{/block}`
- [ ] 分页用 `{@$index_page}`（或主题统一的 page 片段）
- [ ] 资源 `{$path_tpl}`，站名 `{$nncms.site_name}`
- [ ] 无原生 `<?php` 业务逻辑

---

## 1. 目录骨架

以官方 `template/default/` 为准：

```
template/<dir>/
├── conf.json
├── icon.png
├── css/          # style.css / theme.css / component.css …
├── js/
├── images/       # 或 fonts/
└── html/
    ├── public/
    │   ├── include.html      # CSS/JS
    │   ├── head.html / foot.html
    │   ├── slide.html        # 首页轮播（常用 sticky_index_video）
    │   ├── vod_box.html      # 列表卡片片段
    │   ├── vod_screen.html   # 筛选条
    │   ├── page.html         # 分页 {@$index_page}
    │   ├── vod_cid.html / vod_cate.html / vod_list.html / like.html …
    │   └── …
    ├── user/                 # 用户中心（可先沿用 default）
    ├── index.html
    ├── list.html / type.html / category.html / search.html
    ├── video.html / player.html
    ├── art.html / art_list.html / art_category.html
    ├── tag.html / tag_list.html / page.html
    ├── 404.html / message.html / danmu.html
```

资源前缀统一：`{$path_tpl}css/...`、`{$path_tpl}js/...`。

---

## 2. conf.json

```json
{
  "name": "主题中文名",
  "brief": "一句话介绍",
  "version": "1.0.0",
  "software_version": "1.0.0",
  "mode": 0,
  "type": 1,
  "installed": 0,
  "author": "",
  "domain": "",
  "qq": "",
  "price": ""
}
```

后台「模板」里启用；`installed` 等字段由系统维护，新建时按上表即可。

---

## 3. 模板标签

| 语法 | 含义 |
|------|------|
| `{inc:public/head.html}` | 引入相对 `html/` 的片段；缺文件回落 `default` |
| `{$var}` / `{$a.b}` | 输出变量（点号）；`{$v[name]}` 方括号下标 |
| `{if:cond}...{else}...{/if}` | 条件 |
| `{loop:$list $v}...{/loop}` | 遍历 |
| `{@$expr}` | 输出 PHP 表达式（如分页 `{@$index_page}`；需配置允许 expr） |
| `{block:名称 参数}...{/block}` | 见下节 |

**禁止**在主题 html 里写业务 `<?php`。播放器：

```html
<iframe src="{$player}" allowfullscreen
  style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>
```

---

## 4. `{block:}` 块位（必读）

### 4.1 语法与 `$data`

编译规则（`core/model/view.php`）：

```text
{block:名称 k="v" n="8"}
  … 内部用 $data …
{/block}
```

展开为：`$data = block_名称(参数数组);` → 渲染中间 HTML → `unset($data)`。

要点：

1. **必须成对**：`{block:xxx}...{/block}`，不能只写开标签。
2. **结果一律进 `$data`**，块结束后销毁；嵌套块会互相覆盖，避免嵌套。
3. **参数**：`key="value"` 空格分隔；调用 `block_<名称>($arr)`。
4. **返回形态分两类**：
   - **扁平列表**：`$data` 直接可 `{loop:$data $v}`（如 `vod_top`、`vod_rand`、`sticky_index_video`）
   - **结构化**：`$data` 是关联数组，再取字段（如 `vod_cid` → `$data['videolist']`、`$data['cate_name']`）

### 4.2 扁平列表示例

```html
{block:vod_top cid="" n="15"}
  {loop:$data $v}
  <a href="{$v.url}"><span>{$v.n}</span>{$v.name}</a>
  {/loop}
{/block}

{block:vod_rand n="6"}
  {loop:$data $v}
  <a href="{$v.url}"><img src="{$v.pic}">{$v.name}</a>
  {/loop}
{/block}

{block:sticky_index_video n="6"}
  {loop:$data $v}
  <a href="{$v.url}"><img src="{$v.pic}">{$v.name}</a>
  {/loop}
{/block}
```

### 4.3 结构化示例

```html
{block:vod_cid cid="2" n="8"}
  <h2>{$data['cate_name']}</h2>
  <a href="{$data['cate_url']}">更多</a>
  {loop:$data['videolist'] $v}
  <a href="{$v.url}"><img src="{$v.pic}">{$v.name}</a>
  {/loop}
{/block}

{block:vod_cate cid="1" n="18"}
  <h2>{$data['cate']['name']}</h2>
  {loop:$data['cate']['sonlist'] $c}<a href="{$c.url}">{$c.name}</a>{/loop}
  {loop:$data['videolist'] $v}
  <a href="{$v.url}"><img src="{$v.pic}">{$v.name}</a>
  {/loop}
{/block}
```

### 4.4 视频块位速查

| 标签 | 常用参数 | `$data` 形态 | 用途 |
|------|----------|--------------|------|
| `vod_index_new` | **n** | 扁平列表 | 切当前页 `$videolist` 前 N（不查库） |
| `vod_list_new` | **n** | 扁平列表 | 当前栏目 `$videolist` 前 N |
| `vod_top` | cid, n | 扁平（含排名 `n`） | 累计热度榜；cid 空=全站 |
| `vod_rand` | cid, n≤20 | 扁平或 NULL | 随机推荐（缓存 1h 固定） |
| `vod_list_rand` | n | 扁平或 NULL | 当前栏目随机 |
| `vod_list` | n | `{videolist,cate_name,cate_url,cid}` | 当前栏目最新（看会话 cid） |
| `vod_cid` | **cid**, n | 同上 | 指定分类最新 |
| `index_cid` / `index_type` | cid, n | 同上 | 首页栏目位（缓存键不同） |
| `list_type` | n | 同上 | 列表页类型聚合（会话 cid） |
| `vod_list_top` | n | 结构化+榜 | 当前栏目总榜（非日周月） |
| `vod_cate` | **cid**, **n** | `{videolist,top,cate,videos}` | 频道页（含子类） |
| `vod_cates` | type=vod\|art, n, top | cid→结构映射 | 多分类嵌套大页 |
| `index_cids` | **cid**=`1,2,3`, **n** | cid→单分类结构 | 首页多栏一次取 |
| `vod_dwm` | **type**=d\|w\|m, **n** | 扁平 | 全站日/周/月热 |
| `vod_dwm_cid` | type, n, cid | 扁平 | 分类日/周/月热 |
| `poster_rand` | cid, n | 扁平或 NULL | 置顶池随机海报 |
| `sticky_index_video` | n | 扁平（可空数组） | 首页轮播（顺序稳定） |

**务必传 `n`**：多处未传会变成空串/`intval=0`，取不到数据。

### 4.5 文章块位速查

| 标签 | 参数 | `$data` | 说明 |
|------|------|---------|------|
| `art_list` | n | `{artlist,cate_name,cate_url,cid}` | 当前栏目最新文 |
| `art_list_top` | n | 同上 | 当前栏目文章榜 |
| `art_list_rand` | n | `{list,cate_name}` 或 NULL | 随机（候选偏全站最新窗） |
| `art_dwm` | type, n | 扁平 | 全站文章日周月 |
| `art_dwm_cid` | type, n, cid | 扁平 | 分类文章日周月 |

```html
{block:art_list n="10"}
  {loop:$data['artlist'] $v}<a href="{$v.url}">{$v.name}</a>{/loop}
{/block}
```

### 4.6 页内主列表 vs block

| 场景 | 用法 |
|------|------|
| 列表页/搜索页主网格 | 直接 `{loop:$videolist $v}`（路由已装载） |
| 首页轮播/分栏/侧栏榜 | `{block:...}` |
| 详情/播放侧栏热榜、猜你喜欢 | `vod_top` / `vod_rand` |
| 分页 | `{inc:public/page.html}` 内 `{@$index_page}` |

---

## 5. 常用页面变量

- `{$path_tpl}` `{$nncms.site_name}` `{$nncms.site_url}`
- `{$seo.t}` `{$seo.k}` `{$seo.d}`
- `{$cid}` `{$cate}` `{$cate_nav}` `{$sonlist}`
- `{$videolist}` / `$v`：`url name pic actor remarks year area cate_name blurb hits …`
- `{$video}`：详情；`play_data` → `{loop:$video[play_data] $p}` / `{loop:$p[info] $i}`
- `{$player}`：播放地址
- `{$msg}`：提示页
- `{$sticky_list}`：若路由已给轮播，可优先用；否则 `sticky_index_video`

筛选：`{$type_urls[year|area|lang]}`、`{$req_year}` 等（见 list/screen 片段）。

---

## 6. 页面 stub（改皮保留标签）

### public/include.html

```html
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$seo.t}</title>
<meta name="keywords" content="{$seo.k}">
<meta name="description" content="{$seo.d}">
<link rel="stylesheet" href="{$path_tpl}css/style.css">
<script src="{$path_tpl}js/common.js"></script>
```

### public/head.html / foot.html

```html
<header>
  <a href="{$nncms.site_url}">{$nncms.site_name}</a>
  {loop:$cate_nav $n}<a href="{$n.url}">{$n.name}</a>{/loop}
</header>
```

```html
<footer><p>&copy; {$nncms.site_name}</p></footer>
```

### index.html

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>{inc:public/include.html}</head>
<body>
{inc:public/head.html}
{inc:public/slide.html}
{inc:public/vod_cate.html}
{inc:public/vod_cid.html}
{inc:public/foot.html}
</body>
</html>
```

### public/slide.html（轮播）

```html
{if:!empty($sticky_list)}
  {loop:$sticky_list $v}
  <a href="{$v.url}"><img src="{$v.pic}" alt="{$v.name}"><span>{$v.name}</span></a>
  {/loop}
{else}
  {block:sticky_index_video n="6"}
  {loop:$data $v}
  <a href="{$v.url}"><img src="{$v.pic}" alt="{$v.name}"><span>{$v.name}</span></a>
  {/loop}
  {/block}
{/if}
```

### list.html

```html
<!DOCTYPE html>
<html lang="zh-CN">
<head>{inc:public/include.html}</head>
<body>
{inc:public/head.html}
{inc:public/vod_screen.html}
<div class="vod-grid">{inc:public/vod_box.html}</div>
{inc:public/page.html}
{inc:public/foot.html}
</body>
</html>
```

### public/vod_box.html

```html
{loop:$videolist $v}
<a href="{$v.url}" title="{$v.name}">
  <img src="{$v.pic}" alt="{$v.name}" loading="lazy">
  <span>{$v.name}</span><em>{$v.remarks}</em>
</a>
{/loop}
```

### public/page.html

```html
<div class="pager">{@$index_page}</div>
```

### video.html（侧栏块位）

```html
{inc:public/head.html}
<article>
  <img src="{$video.pic}" alt="{$video.name}">
  <h1>{$video.name}</h1>
  <p>{$video.actor} · {$video.year} · {$video.area}</p>
  <div>{$video.content}</div>
  {loop:$video[play_data] $p}
  <h3>{$p.name}</h3>
  <ul>{loop:$p[info] $i}<li><a href="{$i.url}">{$i.name}</a></li>{/loop}</ul>
  {/loop}
</article>
<aside>
  <h3>热搜</h3>
  {block:vod_top cid="" n="15"}
  {loop:$data $v}<a href="{$v.url}">{$v.n}. {$v.name}</a>{/loop}
  {/block}
  <h3>猜你喜欢</h3>
  {block:vod_rand n="6"}
  {loop:$data $v}<a href="{$v.url}"><img src="{$v.pic}">{$v.name}</a>{/loop}
  {/block}
</aside>
{inc:public/foot.html}
```

### player.html

```html
{inc:public/head.html}
<div class="player-frame" style="position:relative;padding-bottom:56.25%;background:#000">
  <iframe src="{$player}" allowfullscreen
    style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>
</div>
{loop:$video[play_data] $p}
  <h3>{$p.name}</h3>
  <ul>{loop:$p[info] $i}<li><a href="{$i.url}">{$i.name}</a></li>{/loop}</ul>
{/loop}
{inc:public/foot.html}
```

### 404 / message

```html
{inc:public/head.html}
<div class="empty"><h1>页面不存在</h1><a href="/">回首页</a></div>
{inc:public/foot.html}
```

```html
{inc:public/head.html}
<div class="empty">{$msg}<p><a href="/">回首页</a></p></div>
{inc:public/foot.html}
```

其余 `type/search/tag/art_*`：主列表照 `vod_box` / `artlist` 循环；结构对齐 `default` 同名页即可。

---

## 7. CSS 起步

定一套方向（深色或浅色），用 CSS 变量；避免千篇一律的紫白渐变套路。卡片仅用于可点列表项。

```css
:root {
  --bg: #0f1419;
  --surface: #1a222c;
  --text: #e8eef4;
  --muted: #8b9aab;
  --accent: #3d9cf0;
  --font: "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
}
body { margin: 0; font-family: var(--font); background: var(--bg); color: var(--text); }
.vod-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
```

---

## 8. 启用与验证

1. 主题目录放到 `template/<dir>/`
2. 后台启用该主题
3. 清模板编译缓存（`runtime/cache/` 下对应产物；或后台清缓存）后强刷
4. 验证：首页轮播/分栏 → 列表筛选+分页 → 详情侧栏 block → 播放 iframe

排障：页面空白先看 `runtime/log/`；block 无数据检查是否漏传 `n`、结构化是否误写成 `{loop:$data}`。

---

## 9. 不要做

- 不要为了「参考」递归读大量第三方主题源码（本文件 + 必要时 `default` 已够）
- 不要漏写 `{/block}`，不要嵌套 block
- 不要把结构化块当成扁平列表 loop（`vod_cid` 必须 loop `$data['videolist']`）
- 不要在主题里堆业务 PHP、外链未本地化 CDN
- 不要只改 html 不清缓存就断定「没生效」

---

## 10. 一句话 → 动作

| 用户说 | 你做 |
|--------|------|
| 「做一套深色模板 aurora」 | `template/aurora/` 按 stub 全量生成 |
| 「首页要多栏分类」 | `index_cids` 或多个 `vod_cid` / `vod_cate` |
| 「侧栏热榜」 | `vod_top` + 扁平 loop |
| 「照 default 改」 | 才允许读 `template/default/` |
| 「block 怎么写」 | 只看本文件 §4 |
