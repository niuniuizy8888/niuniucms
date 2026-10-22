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
2. 确保 `runtime/`、`upload/`、`template/`、`plugins/`、`core/config/`、`core/` 对运行 PHP 的用户（如 `www`）可写。
3. 访问 `install.php`，按向导完成安装（向导会自动生成 `core/config/config.php`，无需手动准备）。
4. 如主机装有安全/杀毒软件（Imunify360、ClamAV 等），请确认 `core/` 下的文件未被隔离；缺失核心文件时程序会提示具体文件名。

## 开发说明

- 不要直接修改 `runtime/` 下的运行时文件
- 改 PHP 文件后建议先执行 `php -l`
- 公开仓库建议只保留源码、模板、插件和基础说明

### 模板安全规则数据（`core/config/template_guard.json`）

模板/主题安全闸的黑名单（语句关键字、高危函数、主题钩子禁用构造）存放在该文件中，每项为名字的十六进制编码（避免主机杀毒软件把 PHP 源码误判为后门）。`TemplateService::GUARD_DATA_SHA256` 固定了解码后内容的摘要：文件缺失、损坏或摘要不符时，所有模板保存/渲染安全检查一律按「拒绝」处理（fail-closed）。

修改规则时数据文件与摘要常量必须一起更新：

1. 新增一项的编码：`php -r 'echo bin2hex("函数名"), PHP_EOL;'`，追加到 `funcs`（或 `keywords`）数组；`hook_alt` 是整条正则多选串（`a|b|c`）的编码。
2. 在站点根目录重算摘要，替换 `core/app/Service/System/TemplateService.php` 中的 `GUARD_DATA_SHA256`：

   ```bash
   php -r '$j=json_decode(file_get_contents("core/config/template_guard.json"),true);$d=function($h){return pack("H*",$h);};echo hash("sha256",implode("\n",array_map($d,$j["keywords"]))."\n\n".implode("\n",array_map($d,$j["funcs"]))."\n\n".$d($j["hook_alt"])),PHP_EOL;'
   ```
3. 若开启了 OPcache，改完后重载 PHP-FPM。

## 公共仓库建议忽略

- `runtime/` 下的实际运行内容


这些文件通常包含运行时数据、安装状态、本地环境配置或内部维护信息。

## License

本项目采用 [MIT License](./LICENSE)。
