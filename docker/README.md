# NiuNiuCMS Docker（PHP 8.5 + nginx + MySQL）

## 启动

```bash
cd /path/to/nncms
cp .env.example .env   # 改掉密码
docker compose up -d --build
```

打开 `http://127.0.0.1:8080/install.php`，数据库主机填 `db`。

## 国内镜像

```bash
PHP_IMAGE=docker.m.daocloud.io/library/php:8.5-fpm \
MYSQL_IMAGE=docker.m.daocloud.io/library/mysql:8.0 \
docker compose up -d --build
```

若无 8.5：`PHP_IMAGE=php:8.4-fpm`。

## 注意

- 端口仅绑 `127.0.0.1:8080`
- `data`/`runtime`/`upload` 为命名卷
- 安装后后台轮换 `auth_key`，并设置 `site_host`