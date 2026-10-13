# 出厂建表 DDL（安装向导用；表前缀 `niuniucms_` 由向导替换）
# 索引口径：与线上库逐表对齐——**不保留被复合索引前缀覆盖的单列索引**
# （vod.cid ⊂ cid_views、vod.area ⊂ idx_filter、art.cid ⊂ cid_views、vod_top.cid ⊂ cid_date、
#  api_import_batch_item.batch_id ⊂ batch_vid；like/favorites.touid_id 全仓零引用已删）
# 会话表列宽与字符集同样以线上为准：session.data=varchar(1024)、session_data.data=mediumtext、utf8mb4
# 唯一性约束（本文件独有的那张）：tag_id 有 UNIQUE(tagid,vid)，用于阻止重复标签绑定（审计缺陷 #4）。
#   ⚠ **已装过的库不能靠本文件补索引**（这里全是 DROP TABLE + CREATE，重跑等于清库）——
#   存量库的去重 + 加索引走一次性迁移工具 `.skills/deploy/tag_id-dedupe-migrate.php`（默认只读体检）。
# 管理员占位行（niuniucms_user uid=1）：password 为**不可用占位散列**（对应一串 64 位随机明文，
# 生成后即丢弃），salt 置空。安装器随后用向导表单填写的密码 db_update 覆盖本行
# （core/install/index.php 安装流程的「管理员密码」段，密码散列覆盖成功后才会继续），覆盖失败即中止安装。故即便有人**手工导入本 SQL、不走安装器**，
# 也拿不到可用凭据——此处历史上是公开已知散列 + salt=123456（任何人可登录）。
# 用户表
DROP TABLE IF EXISTS `niuniucms_user`;
CREATE TABLE `niuniucms_user` (
  `uid` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户编号',
  `gid` smallint(6) unsigned NOT NULL DEFAULT '0' COMMENT '用户组编号',
  `email` char(40) NOT NULL DEFAULT '' COMMENT '邮箱',
  `username` char(32) NOT NULL DEFAULT '' COMMENT '用户名',
  `password` varchar(255) NOT NULL DEFAULT '' COMMENT '密码',
  `salt` varchar(32) NOT NULL DEFAULT '' COMMENT '密码盐',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '注册时间',
  `login_date` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  `login_ip` decimal(39,0) NOT NULL DEFAULT '0' COMMENT '最后登录IP',
  `logins` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '登录次数',
  `create_ip` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '注册IP',
  `likes` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '点赞数',
  `get_likes` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '获赞数',
  `favorites` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '收藏数',
  `get_favorites` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '被收藏数',
  `invitations` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '邀请码数',
  `invitation_useds` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '已用邀请码数',
  PRIMARY KEY (`uid`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `gid` (`gid`),
  KEY `create_date` (`create_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户表';
INSERT INTO `niuniucms_user` SET uid=1, gid=1, email='admin@admin.com', username='admin',`password`='$2y$12$GjMiXCdd1CMSD5xNo9FzCOxgvKDNqW8RCuSS8sF6WhWIPCruVDWhy',salt='';
#会员组
DROP TABLE IF EXISTS `niuniucms_group`;
CREATE TABLE `niuniucms_group` (
 `gid` smallint(6) unsigned NOT NULL DEFAULT 0,
  `name` varchar(20) NOT NULL DEFAULT '',
  `intoadmin` tinyint(1) NOT NULL DEFAULT '0',
  `allowread` tinyint(1) NOT NULL DEFAULT '0',
  `manageuser` int(10) unsigned NOT NULL DEFAULT '0',
  `managecreateuser` int(10) unsigned NOT NULL DEFAULT '0',
  `manageupdateuser` int(10) unsigned NOT NULL DEFAULT '0',
  `managedeleteuser` int(10) unsigned NOT NULL DEFAULT '0',
  `manageupdategroup` int(10) unsigned NOT NULL DEFAULT '0',
  `managegroup` int(10) unsigned NOT NULL DEFAULT '0',
  `managevod` int(10) unsigned NOT NULL DEFAULT '0',
  `manageart` int(10) unsigned NOT NULL DEFAULT '0',
  `managecate` int(10) unsigned NOT NULL DEFAULT '0',
  `managecontent` int(10) unsigned NOT NULL DEFAULT '0',
  `managelink` int(10) unsigned NOT NULL DEFAULT '0',
  `managehot` int(10) unsigned NOT NULL DEFAULT '0',
  `manageapi` int(10) unsigned NOT NULL DEFAULT '0',
  `manageset` int(10) unsigned NOT NULL DEFAULT '0',
  `managetmpl` int(10) unsigned NOT NULL DEFAULT '0',
  `managead` int(10) unsigned NOT NULL DEFAULT '0',
  `manageplugin` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`gid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='会员组表';
INSERT INTO `niuniucms_group` (`gid`, `name`, `intoadmin`,`allowread`) VALUES
(0, '游客组', 0,1),
(1, '管理员', 1,1),
(2, '普通会员',0,1);
DROP TABLE IF EXISTS `niuniucms_cache`;
CREATE TABLE `niuniucms_cache` (
  `k` char(32) NOT NULL default '',
  `v` mediumtext NOT NULL,
  `expiry` int(11) unsigned NOT NULL default '0',
  PRIMARY KEY(`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
DROP TABLE IF EXISTS `niuniucms_kv`;
CREATE TABLE `niuniucms_kv` (
  `k` char(32) NOT NULL default '',
  `v` mediumtext NOT NULL,
  `expiry` int(11) unsigned NOT NULL default '0',
  PRIMARY KEY(`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
INSERT INTO `niuniucms_kv` (`k`, `v`, `expiry`) VALUES ('setting', '{"conf":{"name":"niuniucms","installed":0,"setting":{"website_mode":2,"tpl_mode":0,"map":"map","verify_video":0,"verify_post":0,"verify_special":0,"thumbnail_on":1,"save_image_on":1},"picture_size":{"width":400,"height":280},"theme":"default","shield":[],"index_stickys":0}}', 0);
# 分类
DROP TABLE IF EXISTS `niuniucms_cate`;
CREATE TABLE `niuniucms_cate` (
  `cid` smallint(6) unsigned NOT NULL AUTO_INCREMENT,
  `cup` int(11) unsigned NOT NULL DEFAULT '0',
  `son` int(11) NOT NULL DEFAULT '0',
  `name` varchar(60) NOT NULL DEFAULT '',
  `type` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `model` tinyint(2) unsigned NOT NULL DEFAULT '0',
  `category` tinyint(2) unsigned NOT NULL DEFAULT '0',
  `alias` varchar(80) NOT NULL DEFAULT '',
  `rank` smallint(5) unsigned NOT NULL DEFAULT '0',   # 上线前终检：后台排序是**自由文本框**，原 tinyint(3)（≤255）填 999 即 1264 越界
  `display` tinyint(1) NOT NULL DEFAULT '0',
  `nav_display` tinyint(1) NOT NULL DEFAULT '0',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0',
  `seo_key` varchar(255) NOT NULL DEFAULT '',
  `seo_des` varchar(255) NOT NULL DEFAULT '',
  `seo_title` varchar(255) NOT NULL DEFAULT '',
  `content` mediumtext NULL COMMENT '单页(category=2)正文富文本，入库经 xn_html_safe 白名单；其余类型不使用',
  `videos` int(10) unsigned NOT NULL DEFAULT '0',   # 上线前终检：原 tinyint(1)（≤127）装的是分类视频**总数**，
                                                     # 采集自增 `videos+1` 到第 128 条即 1264 越界（测试站实测一天 3.5 万条）
  `arts` int(10) unsigned NOT NULL DEFAULT '0',       # 上线前终检：与 videos 同类——存的是分类**文章总数**，
                                                     # 采集自增 `arts+1`（ApiExecutionService:737）到第 128 篇即 1264 越界
  PRIMARY KEY (`cid`) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='分类表'; 
DROP TABLE IF EXISTS `niuniucms_session`;
CREATE TABLE `niuniucms_session` (
  `sid` char(32) NOT NULL default '0',  
  `uid` int(11) unsigned NOT NULL default '0', 
  `tid` int(11) unsigned NOT NULL default '0', 
  `cid` int(11) unsigned NOT NULL default '0', 
  `url` varchar(255) NOT NULL default '',  
  `ip` decimal(39,0) unsigned NOT NULL default '0', 
  `useragent` varchar(255) NOT NULL default '', 
  `data` varchar(1024) NOT NULL default '',  
  `bigdata` tinyint(1) NOT NULL default '0',

  `last_date` int(11) unsigned NOT NULL default '0', 
  PRIMARY KEY (`sid`),
  KEY `ip` (`ip`),
  KEY `last_date` (`last_date`),
  KEY `uid_last_date` (`uid`, `last_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
DROP TABLE IF EXISTS `niuniucms_session_data`;
CREATE TABLE `niuniucms_session_data` (
  `sid` char(32) NOT NULL default '0',
  `last_date` int(11) unsigned NOT NULL default '0',
  `data` mediumtext NOT NULL,
  PRIMARY KEY (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#视频
DROP TABLE IF EXISTS `niuniucms_vod`;
CREATE TABLE `niuniucms_vod` (
  `vid` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '视频vid',
  `cid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '分类id',
  `views` int(11) NOT NULL DEFAULT '0' COMMENT '查看次数',
  `sticky` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '发布时间',
  `alias` varchar(32) NOT NULL DEFAULT '' COMMENT 'URL别名',
  `tag` varchar(255) NOT NULL DEFAULT '' COMMENT 'tag',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '0:通过 1~9审核:1待审核 2草稿',
  `uptime` varchar(255) NOT NULL DEFAULT '' COMMENT '更新时间',
  `vod_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT 'MacCms id',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '视频名称',
  `sub` varchar(255) NOT NULL DEFAULT '' COMMENT '副标',
  `en` varchar(255) NOT NULL DEFAULT '' COMMENT '拼音',
  `letter` char(1) NOT NULL DEFAULT '' COMMENT '首字母',
  `pic` varchar(1024) NOT NULL DEFAULT '' COMMENT '图片',
  `pic_slide` varchar(1024) NOT NULL DEFAULT '' COMMENT '海报',
  `actor` varchar(255) NOT NULL DEFAULT '' COMMENT '演员',
  `director` varchar(255) NOT NULL DEFAULT '' COMMENT '导演',
  `writer` varchar(100) NOT NULL DEFAULT '' COMMENT '编剧',
  `behind` varchar(100) NOT NULL DEFAULT '' COMMENT '幕后',
  `blurb` varchar(255) NOT NULL DEFAULT '' COMMENT '简介',
  `remarks` varchar(100) NOT NULL DEFAULT '' COMMENT '备注',
  `pubdate` varchar(100) NOT NULL DEFAULT '' COMMENT '上映日期',
  `total` mediumint(8) unsigned NOT NULL DEFAULT '0' COMMENT '总集数',
  `serial` varchar(20) NOT NULL DEFAULT '0' COMMENT '连载数',
  `area` varchar(20) NOT NULL DEFAULT '' COMMENT '地区',
  `lang` varchar(10) NOT NULL DEFAULT '' COMMENT '语言',
  `year` varchar(10) NOT NULL DEFAULT '' COMMENT '年份',
  `version` varchar(30) NOT NULL DEFAULT '' COMMENT '版本',
  `level` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '推荐等级',
  `douban_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '豆瓣id',
  `douban_score` decimal(3,1) unsigned NOT NULL DEFAULT '0.0' COMMENT '豆瓣评分',
  `content` mediumtext NOT NULL COMMENT '内容',
  `play_from` varchar(255) NOT NULL DEFAULT '' COMMENT '播放器来源',
  `play_server` varchar(255) NOT NULL DEFAULT '' COMMENT '播放服务器',
  `play_note` varchar(255) NOT NULL DEFAULT '' COMMENT '播放说明',
  `play_url` mediumtext NOT NULL COMMENT '播放地址',
  `cup` int(10) unsigned NOT NULL DEFAULT '0',
  `favorites` int(10) unsigned NOT NULL DEFAULT '0',
  `likes` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`vid`),
  KEY `alias` (`alias`),
  KEY `year` (`year`),
  KEY `lang` (`lang`),
  KEY `create_date` (`create_date`),
  KEY `cid_views` (`cid`, `views`),
  KEY `idx_filter` (`area`,`lang`,`year`,`cid`),
  -- 修复（默认 MyISAM 安装报 1071）：utf8mb4 下 varchar(255) 全列索引 = 1020 B，
  -- 既超 MyISAM 的 1000 B 硬限，也超 MySQL 5.6 默认行格式 InnoDB 的 767 B 限。
  -- 改前缀 191 字符（191×4 = 764 B ≤ 767）——采集去重 `WHERE name = ?` 仍走该索引。
  KEY `idx_name` (`name`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='视频表';
DROP TABLE IF EXISTS `niuniucms_vod_sticky`;
CREATE TABLE `niuniucms_vod_sticky` (
  `vid` int(11) unsigned NOT NULL DEFAULT '0',
  `cid` int(11) unsigned NOT NULL DEFAULT '0',
  `sticky` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`vid`),
  KEY `sticky_vid` (`sticky`,`vid`),
  KEY `cid_sticky` (`cid`,`sticky`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='视频推荐表';
DROP TABLE IF EXISTS `niuniucms_vod_vid`;
CREATE TABLE `niuniucms_vod_vid` (
  `vid` int(11) unsigned NOT NULL DEFAULT 0,
  `cid` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`vid`),
  KEY `cid_vid` (`cid`,`vid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci COMMENT='视频vid表';
DROP TABLE IF EXISTS `niuniucms_art`;
CREATE TABLE `niuniucms_art` (
  `aid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cid` smallint(6) unsigned NOT NULL DEFAULT '0' ,
  `name` varchar(255) NOT NULL DEFAULT '' ,
  `tag` varchar(255) NOT NULL DEFAULT '' ,
  `pic` varchar(1024) NOT NULL DEFAULT '' ,
  `views` int(11) NOT NULL DEFAULT '0',
  `content` longtext NOT NULL,
  `create_date` int(11) unsigned NOT NULL DEFAULT '0',
  `favorites` int(10) unsigned NOT NULL DEFAULT '0',
  `likes` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`aid`),
  KEY `create_date` (`create_date`),
  KEY `cid_views` (`cid`, `views`)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文章表';

DROP TABLE IF EXISTS `niuniucms_art_aid`;
CREATE TABLE `niuniucms_art_aid` (
  `aid` int(11) UNSIGNED NOT NULL DEFAULT 0,
  `cid` int(11) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`aid`),
  INDEX `cid_aid`(`cid`, `aid`) 
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文章aid表';


DROP TABLE IF EXISTS `niuniucms_tag`;
CREATE TABLE `niuniucms_tag` (
  `tagid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` char(64) NOT NULL DEFAULT '',
  `en` char(32) NOT NULL DEFAULT '',
  `count` int(11) NOT NULL DEFAULT '0',
  `seo_title` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO标题',
  `seo_key` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO关键词',
  `seo_des` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO描述',
  PRIMARY KEY (`tagid`),
  KEY `name` (`name`),
  KEY `en` (`en`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='标签表';
DROP TABLE IF EXISTS `niuniucms_tag_id`;
# 标签⇒内容 绑定表。四张索引各有职责，别当冗余清掉：
#   PRIMARY(id)              绑定行主键；解绑链路按 id 批量删
#   KEY vid(vid)             按 vid（含 IN 批量）取某内容的全部绑定行——删除内容/换标签的必经之路
#   KEY tagid_id(tagid,id)   标签页 WHERE tagid=? ORDER BY id DESC LIMIT 20 靠它免排序。
#                            实测（35 万绑定）：有此索引读 20 行 0.03ms；删掉后优化器退化成主键倒序
#                            扫描（type=index）读 1935 行 0.19ms。别被"二级索引会自动追加主键、
#                            所以它等价于单列 tagid"的说法误导而删掉——第二列 id 正是排序所需。
#   UNIQUE uk_tagid_vid      完整性约束：同一 (tagid,vid) 只允许一行。缺它时 tag_video_create 的
#                            不带主键 REPLACE 等同 INSERT，重复绑定会累积且让 tag.count 与实际行数
#                            漂移（审计缺陷 #4）。配套改动两处，缺一不可：
#                            ① TagFlowService::process() 插入前先按已绑定集合过滤（保证 tag.count
#                               只在真正新增绑定时 +1）；
#                            ② 该链路的 bigInsert 走 INSERT IGNORE（QueryBuilder::bulkInsert 的
#                               $ignore 形参），使残余撞键（并发保存同一内容）只跳过那一行，
#                               而不是让普通多值 INSERT 整条 1062 失败、把同批其它标签一起丢掉
#                               —— 错误会被 prepare_execute 吞成 rowCount=0，调用方看不到异常。
#                            ⚠ 存量库有历史重复，必须先跑
#                            .skills/deploy/tag_id-dedupe-migrate.php（去重后再 ALTER）才能加此索引。
CREATE TABLE `niuniucms_tag_id` (
`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
`tagid` int(11) unsigned NOT NULL DEFAULT '0',
`vid` int(11) unsigned NOT NULL DEFAULT '0',
PRIMARY KEY (`id`),
KEY `vid` (`vid`),
KEY `tagid_id` (`tagid`,`id`),
UNIQUE KEY `uk_tagid_vid` (`tagid`,`vid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='标签数据表';
DROP TABLE IF EXISTS `niuniucms_link`;
CREATE TABLE `niuniucms_link` (
   `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `rank` smallint(5) unsigned NOT NULL DEFAULT '0',   # 上线前终检：后台排序是**自由文本框**，原 tinyint(3)（≤255）填 999 即 1264 越界
  `name` varchar(24) NOT NULL DEFAULT '',
  `url` varchar(120) NOT NULL DEFAULT '',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `rank` (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='友情链接表';
DROP TABLE IF EXISTS `niuniucms_like`;
CREATE TABLE `niuniucms_like` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL DEFAULT '0',
  `touid` int(11) unsigned NOT NULL DEFAULT '0',
  `vid` int(11) unsigned DEFAULT NULL, # 视频（非适用列为 NULL，唯一索引对 NULL 视为不同值）
  `aid` int(11) unsigned DEFAULT NULL, # 文章（同上）
  `type` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0',
  `model` tinyint(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_vid` (`uid`,`vid`,`model`),
  UNIQUE KEY `uid_aid` (`uid`,`aid`,`model`),
  KEY `vid_uid` (`vid`,`uid`),
  KEY `aid_uid` (`aid`,`uid`),
  KEY `uid_type_id` (`uid`,`type`,`id`)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='点赞表';
DROP TABLE IF EXISTS `niuniucms_favorites`;
CREATE TABLE `niuniucms_favorites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL DEFAULT '0',
  `touid` int(11) unsigned NOT NULL DEFAULT '0',
  `vid` int(11) unsigned DEFAULT NULL,
  `aid` int(11) unsigned DEFAULT NULL,
  `type` tinyint(2) unsigned NOT NULL DEFAULT '0',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0',
  `model` tinyint(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_vid` (`uid`,`vid`,`model`),
  UNIQUE KEY `uid_aid` (`uid`,`aid`,`model`),
  KEY `vid_uid` (`vid`,`uid`),
  KEY `aid_uid` (`aid`,`uid`),
  KEY `uid_id` (`uid`,`id`)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='收藏表';

DROP TABLE IF EXISTS `niuniucms_invitation`;
CREATE TABLE `niuniucms_invitation` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL DEFAULT '0', 
  `touid` int(11) unsigned NOT NULL DEFAULT '0',  
  `code` char(32) NOT NULL default '', 
  `state` tinyint(1) unsigned NOT NULL DEFAULT '0', 
  `use_date` int(11) unsigned NOT NULL default '0', 
  `create_date` int(11) unsigned NOT NULL DEFAULT '0', 
  PRIMARY KEY (`id`),
  KEY `code` (`code`),
  KEY `uid_id` (`uid`,`id`) 
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='邀请码注册';

DROP TABLE IF EXISTS `niuniucms_vod_top`;
CREATE TABLE `niuniucms_vod_top` (
  id INT(11) NOT NULL AUTO_INCREMENT,
  vid INT(11) NOT NULL DEFAULT 0,
  cid INT(11) NOT NULL DEFAULT 0,
  create_date INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY(id),
  KEY(vid),
  KEY(create_date),
  KEY cid_date(cid, create_date)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='视频热门表';

DROP TABLE IF EXISTS `niuniucms_vod_top_ip`;
CREATE TABLE `niuniucms_vod_top_ip` (
  id INT(11) NOT NULL AUTO_INCREMENT,
  vid INT(11) NOT NULL DEFAULT 0,
  ip DECIMAL(39,0) NOT NULL DEFAULT '0',
  create_date INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY(id),
  KEY(vid),
  KEY(ip),
  KEY(create_date)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='视频热门表ip';


DROP TABLE IF EXISTS `niuniucms_art_top`;
CREATE TABLE `niuniucms_art_top` (
  id INT(11) NOT NULL AUTO_INCREMENT,
  aid INT(11) NOT NULL DEFAULT 0,
  cid INT(11) NOT NULL DEFAULT 0,
  create_date INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY(id),
  KEY(aid),
  KEY(cid),
  KEY(create_date) 
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文章热门表';

DROP TABLE IF EXISTS `niuniucms_art_top_ip`;
CREATE TABLE `niuniucms_art_top_ip` (
  id INT(11) NOT NULL AUTO_INCREMENT,
  aid INT(11) NOT NULL DEFAULT 0,
  ip DECIMAL(39,0) NOT NULL DEFAULT '0',
  create_date INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY(id),
  KEY(aid),
  KEY(ip),
  KEY(create_date)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文章热门表ip';

DROP TABLE IF EXISTS `niuniucms_danmu`;
CREATE TABLE `niuniucms_danmu` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '弹幕ID',
  `vid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '视频ID',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户ID',
  `username` varchar(30) NOT NULL DEFAULT '' COMMENT '用户名',
  `text` varchar(255) NOT NULL DEFAULT '' COMMENT '弹幕内容',
  `time` float(10,2) NOT NULL DEFAULT '0.00' COMMENT '弹幕时间(秒)',
  `color` varchar(7) NOT NULL DEFAULT '#FFFFFF' COMMENT '弹幕颜色',
  `mode` int(11) NOT NULL DEFAULT '0' COMMENT '弹幕模式',
  `ip` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户IP',
  `create_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '状态(0=禁用,1=启用)', 
  PRIMARY KEY(id),
  KEY(vid),
  KEY(uid),
  KEY(create_time)
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='弹幕表';

# 广告
DROP TABLE IF EXISTS `niuniucms_ad`;
CREATE TABLE `niuniucms_ad` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '广告ID',
  `name` varchar(128) NOT NULL DEFAULT '' COMMENT '广告名称',
  `url` varchar(255) NOT NULL DEFAULT '' COMMENT '广告链接',
  `img_url` varchar(255) NOT NULL DEFAULT '' COMMENT '广告图片',
  `area` varchar(32) NOT NULL DEFAULT '' COMMENT '广告位标识',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '添加时间',
  `end_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '结束时间',
  PRIMARY KEY (`id`),
  KEY `area` (`area`),
  KEY `add_time` (`add_time`),
  KEY `end_time` (`end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='广告表';

# 采集批次
DROP TABLE IF EXISTS `niuniucms_api_import_batch`;
CREATE TABLE `niuniucms_api_import_batch` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_code` varchar(64) NOT NULL DEFAULT '',
  `source_name` varchar(128) NOT NULL DEFAULT '',
  `uid` int(11) unsigned NOT NULL DEFAULT '0',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '0=running,1=finished,2=rolled_back,3=failed',
  `limit_num` int(11) unsigned NOT NULL DEFAULT '0',
  `total_num` int(11) unsigned NOT NULL DEFAULT '0',
  `processed_num` int(11) unsigned NOT NULL DEFAULT '0',
  `imported_num` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_num` int(11) unsigned NOT NULL DEFAULT '0',
  `duplicate_num` int(11) unsigned NOT NULL DEFAULT '0',
  `rollback_num` int(11) unsigned NOT NULL DEFAULT '0',
  `last_page` int(11) unsigned NOT NULL DEFAULT '0',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0',
  `finish_date` int(11) unsigned NOT NULL DEFAULT '0',
  `rollback_date` int(11) unsigned NOT NULL DEFAULT '0',
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `source_code_create_date` (`source_code`, `create_date`),
  KEY `status_create_date` (`status`, `create_date`),
  KEY `uid_create_date` (`uid`, `create_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='采集导入批次表';

DROP TABLE IF EXISTS `niuniucms_api_import_batch_item`;
CREATE TABLE `niuniucms_api_import_batch_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `vid` int(11) unsigned NOT NULL DEFAULT '0',
  `cid` int(11) unsigned NOT NULL DEFAULT '0',
  `vod_name` varchar(255) NOT NULL DEFAULT '',
  `action_type` varchar(16) NOT NULL DEFAULT 'create',
  `result_status` varchar(24) NOT NULL DEFAULT 'create',
  `diff_fields` text NULL,
  `diff_snapshot` mediumtext NULL,
  `update_scope` varchar(32) NOT NULL DEFAULT '',
  `create_date` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `batch_vid` (`batch_id`,`vid`),
  KEY `batch_result_status` (`batch_id`,`result_status`),
  KEY `vid` (`vid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='采集导入批次明细表';

DROP TABLE IF EXISTS `niuniucms_recycle`;
CREATE TABLE `niuniucms_recycle` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(16) NOT NULL DEFAULT '',
  `target_id` int(10) unsigned NOT NULL DEFAULT '0',
  `data` longtext,   # 整行快照 JSON：含 vod.content(longtext)/play_url(审计口径实测最大 171KB) ⇒ text(64KB) 会 1406 截断失败，删除链因此硬失败（复核修正；存量库见 .skills/deploy/schema-migrate-cate-recycle.sql）
  `uid` int(10) unsigned NOT NULL DEFAULT '0',
  `create_date` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `type_target` (`type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='回收站表';

DROP TABLE IF EXISTS `niuniucms_search_hot`;
CREATE TABLE `niuniucms_search_hot` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `keyword` varchar(128) NOT NULL DEFAULT '',
  `count` int(11) NOT NULL DEFAULT '0',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `keyword_uq` (`keyword`),
  KEY `update_time` (`update_time`),
  KEY `count` (`count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='搜索热词';

DROP TABLE IF EXISTS `niuniucms_scheduler_tasks`;
CREATE TABLE `niuniucms_scheduler_tasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(32) NOT NULL DEFAULT '',
  `param` text,
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=pending 1=running 2=finished 3=failed',
  `retry` tinyint(2) unsigned NOT NULL DEFAULT '0',
  `error` varchar(500) NOT NULL DEFAULT '',
  `create_date` int(10) unsigned NOT NULL DEFAULT '0',
  `run_date` int(10) unsigned NOT NULL DEFAULT '0',
  `finish_date` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `status_id` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='采集任务队列表';

