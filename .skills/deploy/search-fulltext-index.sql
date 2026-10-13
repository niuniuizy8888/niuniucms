-- 可选性能项：MySQL ngram 全文搜索索引（审查修复轮 B5；对应 CLAUDE.md §三.2 的 P-4 opt-in）
-- 用途：站点配置 search_use_fulltext=1 时，vod 搜索走 FULLTEXT ngram（替代前导通配 LIKE '%kw%'
--   的全表扫）。**先建索引再开开关**，否则 ContentQueryRepository 自动回退 LIKE（不报错）。
-- 限制：仅 MySQL 5.7.6+ / 8.x（InnoDB ngram 解析器）；**MariaDB 不支持**，请勿执行（开了也会回退 LIKE）。
-- 表前缀按站点实际替换 <表前缀>；索引已存在报 1061 可忽略。
-- 执行：mysql -u<user> -p <database> < search-fulltext-index.sql

ALTER TABLE `<表前缀>vod` ADD FULLTEXT INDEX `ft_name` (`name`) WITH PARSER ngram;
