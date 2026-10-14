# NiuNiuCMS

NiuNiuCMS 是一个基于 PHP + MySQL 的视频内容管理系统，包含前台展示、后台管理、采集、插件和云端能力。

## 环境要求

- PHP 8.0+
- MySQL 5.7+ / 8.0+
- Nginx 或 Apache
- 推荐开启 `pdo_mysql`

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
2. 确保 `runtime/`、`upload/`、`template/`、`plugins/` 可写。
3. 按实际环境准备配置：
   - `core/config/config.php`
   - `core/config/database.php`（如启用独立数据库配置）
   - `core/config/domain.php` 可保留仓库内空模板，再按站群域名补充 `site` 映射
4. 访问 `install.php` 完成安装。

## 开发说明

- 不要直接修改 `runtime/` 下的运行时文件
- 改 PHP 文件后建议先执行 `php -l`
- 公开仓库建议只保留源码、模板、插件和基础说明

## 公共仓库建议忽略

- `runtime/` 下的实际运行内容


这些文件通常包含运行时数据、安装状态、本地环境配置或内部维护信息。

## License

本项目采用 [MIT License](./LICENSE)。
