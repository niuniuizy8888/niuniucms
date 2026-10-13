-- forum 插件存量库索引补建迁移（审查修复轮 B3）
-- 背景：plugins/forum/install.php 的建表脚本已带 forum_thread.KEY(uid) 与
-- forum_post.tid_status(tid,status,pid)，但**只对新建表生效**（CREATE TABLE IF NOT EXISTS
-- 不补已存在表的索引）——存量库无 ALTER 路径。本文件补齐。
-- 执行条件：已安装 forum 插件且表已存在（表前缀按站点实际替换 <表前缀>）。
-- 幂等性：下列 ALTER 用 ADD KEY，索引已存在时 MySQL 会报 1061（Duplicate key name）——
--   可安全忽略；或先 SHOW INDEX FROM <表前缀>forum_thread 确认缺哪个再执行对应行。
-- 执行方式：mysql -u<user> -p <database> < forum-index-migrate.sql
--   （宝塔面板也可直接在 phpMyAdmin/数据库-执行 粘贴下面两句）

-- 主题表：按 uid 查「我发过的帖子」的索引（原脚本缺）
ALTER TABLE `<表前缀>forum_thread` ADD KEY `uid` (`uid`);

-- 回复表：按帖子分页（WHERE tid=? AND status=? ORDER BY pid）的索引（原脚本缺）
ALTER TABLE `<表前缀>forum_post` ADD KEY `tid_status` (`tid`,`status`,`pid`);
