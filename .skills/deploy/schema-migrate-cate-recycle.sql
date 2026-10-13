-- 存量库结构补齐（复核批次）：cate.content + recycle.data 加宽
-- 新装库（install.sql）已自带这两项，仅**已安装的老库**需要执行本文件。
-- 执行：mysql -u<user> -p <database> < schema-migrate-cate-recycle.sql
-- 幂等性：column/key 已存在会报 1060（Duplicate column name）；重复执行可安全忽略该报错。
-- 表前缀按站点实际替换 <表前缀>。

-- ① cate.content：category=2 单页正文（mediumtext）。
--    缺列的后果：后台保存单页时 SET content=? 触发 1054 → 被 prepare_execute 吞成「0 行影响」
--    → 页面显示「编辑成功」但正文一个字都没写；route/page.php 又取不到正文只能回落 seo_des。
--    （收口批次 A4 新增；复核确认这条存量升级路径此前缺失，CategoryManageService::update 现已加
--      「写入回读校验」把该情形改成显式失败 + cate_error 留痕。）
ALTER TABLE `<表前缀>cate`
  ADD COLUMN `content` MEDIUMTEXT NULL COMMENT '单页(category=2)正文富文本，入库经 xn_html_safe 白名单；其余类型不使用' AFTER `seo_title`;

-- ② recycle.data：回收站快照列。原为 text（上限 64KB），而快照是**整行 JSON**
--    （vod 含 content longtext 与 play_url——审计口径实测 play_url 最大 171KB）⇒ 大行入库必然 1406，
--    C1 的 fail-closed 会让「含大字段的内容」永远删不掉（后台只看到「删除失败」）。
--    改 longtext 后与 install.sql 一致。
ALTER TABLE `<表前缀>recycle`
  MODIFY COLUMN `data` LONGTEXT;
