# 2026-10-19 types 筛选页索引（已装站点手动执行一次；新装已并入 install.sql）
# 用途：idx_cid_vid      —— 栏目内按 vid 倒序分页（避免 filesort，数据量上万后生效）
#       idx_cid_lang_vid —— 栏目 + 语言 IN 列表筛选/计数/语言分布统计（覆盖索引）
# 在线 DDL，不锁表；表前缀非 nncms_ 时请替换。重复执行会报 Duplicate key name，可忽略。
ALTER TABLE `nncms_vod`
  ADD INDEX `idx_cid_vid` (`cid`, `vid`),
  ADD INDEX `idx_cid_lang_vid` (`cid`, `lang`, `vid`),
  ALGORITHM=INPLACE, LOCK=NONE;
