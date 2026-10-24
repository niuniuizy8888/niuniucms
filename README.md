# NiuNiuCMS

NiuNiuCMS 是一个基于 PHP + MySQL 的视频内容管理系统，包含前台展示、后台管理、采集、插件和云端能力。

## 环境要求

- PHP 8.0+（推荐 8.2+；7.x 不再支持，访问时会提示切换版本）
- MySQL 5.7+ / 8.0+
- Nginx 或 Apache
- 必需 PHP 扩展：`pdo`、`pdo_mysql`、`mbstring`、`ctype`、`tokenizer`、`iconv`、`json`、`openssl`、`filter`（安装向导会检测，缺失则无法继续）
- 可选 PHP 扩展：`curl`（采集/云端/在线升级）、`gd`（缩略图）、`zip`（模板/插件安装与升级）、`fileinfo`（上传 MIME 检测）

## 目录概览

- `index.php` / `admin.php` / `api.php`：前台、后台、接口入口
- `core/`：核心代码、安装器、配置模板
- `route/`：前台路由
- `template/`：主题模板
- `plugins/`：插件目录
- `static/`：静态资源
- `runtime/`：运行时目录，仓库只保留空目录骨架

## 安装

1. 将代码部署到站点根目录。
2. 确保 `runtime/`、`upload/`、`template/`、`plugins/`、`core/config/` 对运行 PHP 的用户（如 `www`）可写。`core/` 其余部分（程序代码）建议保持只读，只有 `core/config/` 需要可写（安装锁为 `core/config/install.lock`；旧版本的 `core/install.lock` 仍被识别，并会自动复制到新位置）。后台「在线升级」会改写 `core/` 下的程序文件，使用前需临时放开 `core/` 写权限，升级后再收回。
3. 访问 `install.php`，按向导完成安装（向导会自动生成 `core/config/config.php`，无需手动准备）。
4. 如主机装有安全/杀毒软件（Imunify360、ClamAV 等），请确认 `core/` 下的文件未被隔离；缺失核心文件时程序会提示具体文件名。
5. Apache：目录级防护随包附带在 `core/`、`runtime/`、`tools/`、`.skills/`、`plugins/`、`upload/` 下的 `.htaccess` 中，需要站点开启 `AllowOverride All`（至少 AuthConfig、Limit），安装向导会探测并提示是否生效；根目录 `.htaccess`（伪静态）仍由站点自行维护。Nginx 请参照部署示例配置禁止访问规则（须拒绝 `data/`、`tools/`；`install.php` 的锁文件检测须包含 `data/install.lock`）。

## 升级说明（安全审查第 1 轮，2026-10-24）

- **建议升级后更换 `auth_key`**：旧版本中模板表达式可读取全局配置，`auth_key` 存在被泄露的可能。`auth_key` 用于 Cookie/会话签名、加密链接与 api.php 机器通道令牌，更换后所有用户需要重新登录，已在云端登记的 api 令牌也会失效（需要的话重新上报登记、更新计划任务里的 cron URL）。超级管理员可在后台「设置 → 更换密钥」一键更换（需要 `core/config/config.php` 可写；成功后只显示一次新的 api 令牌）；也可以手动在 `core/config/config.php` 中把 `auth_key` 换成新的 64 位随机串（例如 `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`）。
- 出站内网拦截 `outbound_block_private` 出厂默认改为 `1`（拦截回环/内网/云元数据地址，商店 `cloud_api` 主机仍豁免）。新安装自动生效；存量站点 `config.php` 里显式写了 `0` 的保持不变，建议确认无内网采集源/内网 ES 后改为 `1`。
- 安装向导「上报安装信息」默认不勾选，并写明上报内容与授权范围；管理员密码最少 12 位。
- 模板表达式只能读取经过脱敏的 `$conf` 公开配置，禁止访问 `$GLOBALS`/超全局变量、赋值与 print/echo/exit 等语句，`user_read`、`setting_get` 不再允许在模板中调用。
- 整页缓存（`page_` 前缀）不再写入 MySQL 缓存表，只使用 APCu/Yac/Redis/Memcached；存量站点可清理 `<表前缀>cache` 表中 `page_` 开头的旧行（`page_content_` 开头的版本令牌保留）。

## 开发说明

- 不要直接修改 `runtime/` 下的运行时文件
- 改 PHP 文件后建议先执行 `php -l`
- 公开仓库建议只保留源码、模板、插件和基础说明

### 模板/主题安全闸（token 级白名单）

模板指令表达式（`{if:}`/`{elseif:}`/`{loop:}`/`{@}`/`{$...}`）与主题 `install.php`/`uninstall.php` 钩子由 `LexicalSafetyService` 的「默认拒绝」token 白名单校验（`scanExpression`/`scanTemplate`/`scanThemeHook`），保存、下载、`{inc:}`/`<template include>` 内联、主模板编译各处同口径。源码不再内联任何危险函数名清单，因此无需外置规则数据文件或摘要校验。

## 公共仓库建议忽略

- `runtime/` 下的实际运行内容


这些文件通常包含运行时数据、安装状态、本地环境配置或内部维护信息。

## License

本项目采用 [MIT License](./LICENSE)。

## U-2 运行时数据目录(data/)

为便于升级与权限隔离，运行时可写配置集中存放于项目根目录下的 `data/`：
`config.php`、`domain.php`、`throttle.php`、`install.lock`(以及可选的 `database.php`)。

**解析规则(`core/paths.php`)：**
- 读取 `nncms_runtime_path($name)`：优先 `data/$name`；不存在时回退 `core/config/$name`(`install.lock` 再回退 `core/install.lock`)。读取无副作用。
- 写入 `nncms_runtime_path_w($name)`：确保 `data/` 存在；若 `data/$name` 缺失则从旧位置 `core/config/$name` 自动迁移(复制，保留旧文件)后写入 `data/`。

**升级/迁移说明：**
- 旧版布局(配置仅在 `core/config/`)无需改动即可运行：读取自动回退，首次写入自动迁移到 `data/`。
- 全新安装：安装程序直接把配置写入 `data/`，`install.lock` 也写入 `data/`,重复安装会被拦截。
- 回滚：删除整个 `data/` 目录即恢复到旧布局(旧文件始终保留在 `core/config/`)。

**Nginx 必须禁止对外访问 `data/`(示例)：**
```nginx
# 放入站点 server{} 内(宝塔可置于 vhost 的 extension/<域名>/*.conf)
location ^~ /data/ { return 404; }
location = /data  { return 404; }
```
目录内另附 `data/.htaccess`(Apache 环境 deny all)。两者任选其一生效，Nginx 环境以 `location` 规则为准。
