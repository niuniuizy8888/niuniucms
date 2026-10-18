<?php
// ============================================================================
// 站点对外域名集中配置（改这里即可，勿在各处散写域名）
// ============================================================================
// $nn_cloud_host：牛牛CMS 官方站点 / 应用商店域名（**不带协议前缀**）。
//   统一供以下用途派生，替换域名只改这一处：
//     - 商店服务端 API：https://api.{$nn_cloud_host}/     （cloud_api，见下方）
//     - 安装上报端点：  https://api.{$nn_cloud_host}/index.php?api=install
//     - 前台广告位点击跳转地址（弹幕开始/暂停广告的 ad_url）
//   历史沿革：本项原为三个散写域名——旧商店 API（example.com，已停用）、
//   旧模板作者页（example.com，已停用）、旧官方商店占位域名（example.com，已拆除）——
//   现统一归口到本变量，源码注释里不再保留具体域名。
//   注意：cloud_api 留空即「云端功能整体不可用」（无回退地址，见 CloudOpsService）。
$nn_cloud_host = 'niuniucms.cc';
// 商店服务端 base URL（**须带尾斜杠**）。设置后安装上报/云端采集/插件与模板更新/
// 云端 code 均指向该商店；留空 = 云端功能整体不可用（**无回退**，旧官方云端 example.com
// 官方云端已停用，安装器也不再回退到它）。
// **必须用 https**：本通道承载插件/模板 zip 下载与整包升级（可执行 PHP 落地），
// 明文 HTTP 下 ssl_verify 无效、在途可被篡改替换（供应链投毒面）。
$nn_cloud_api = 'https://api.' . $nn_cloud_host . '/';

return array(
  'cloud_host' => $nn_cloud_host, // 官方站点/商店域名（不带协议）；上列 URL 的域名来源
  'db' => array(
    // 数据库配置层级规则（db_new 归一）：若存在与本文件 'type' 同名的子键（如 'pdo_mysql'），
    // 该子键整体作为驱动配置（rw_* 等键必须写在该子键内、与 master 同级）；无同名子键则用顶层。
    // 连接池/读写分离 overlay 模板见 core/config/database.php.example；
    // 分阶段启用与观察方法见 .skills/读写分离使用说明.md。
    'type' => 'pdo_mysql',
    'mysql' => array(
      'master' => array(
        'host' => '127.0.0.1',
        'user' => '',
        'password' => '',
        'name' => '',
        'tablepre' => 'nncms_',
        'charset' => 'utf8',
        'engine' => 'innodb',
      ),
      'slaves' => array(),
    ),
    'pdo_mysql' => array(
      'master' => array(
        'host' => '127.0.0.1',
        'user' => '',
        'password' => '',
        'name' => '',
        'tablepre' => 'nncms_',
        'charset' => 'utf8',
        'engine' => 'innodb',
      ),
      'slaves' => array(),
      // —— 连接池/读写分离开关（任务4/5+补强；默认全关=零行为变化，回滚=改回 false/0）——
      // 注意：本结构存在 'pdo_mysql' 同名子键，rw_* 必须写在本子键内（与 master 同级）才被驱动读到。
      'rw_split' => false,            // 读写分离总开关：SELECT 且非粘滞窗口走从库
      'rw_shadow' => false,           // 影子模式：只记录「本应路由」不切流量（观察期用，落盘 runtime/log/rw_shadow.log）
      'rw_sticky_seconds' => 0,       // 写后粘滞秒数：写后 N 秒读走主库（跨请求经 HttpOnly cookie nncms_rw_sticky）；0=关闭
      // 'slave_strategy' => 'random', // 从库负载均衡：random(默认)/roundrobin/weight（weight 需各 slave 带 'weight'=>N）
      // 'breaker_seconds' => 30,      // 池熔断冷却秒数（仅 db.type=proxy 时生效）
      // 'rw_shadow_sample' => 100,    // 影子落盘采样 1-100，默认 100=全量（实际采样率≥配置值）
      // 'rw_shadow_sink' => false,    // 影子落盘显式关闭（默认未配=随 rw_shadow 开启）
      // 'rw_shadow_log' => '',        // 影子落盘显式路径（默认 runtime/log/rw_shadow.log；>64MB 自动轮转 .prev）
    ),
  ),
  'cache' => array(
    'enable' => true,
    'multi' => array(
      'enable' => true,
      'layers' => array(
        // 层级：static → apcu → redis → memcached → mysql（审查修复轮 A3：memcached 已装扩展+服务，
        // 加入默认层级，排在 redis 之后——sharedGet 取首个命中的共享层，保持 redis 优先的既有
        // 令牌视图语义不变）。⚠ yac 仍未装，保持裁掉；未装扩展的层会被 createDriver() 的
        // extension_loaded 兜底静默跳过——配置里写了不代表一定在线，排查缓存层级以运行时为准。
        // ⚠ apcu 只在 FPM 侧生效：php.ini 载 apcu.so，php-cli.ini 未载 ⇒ CLI 跑
        // plugin.php / 任务队列时 apcu 层恒 miss，靠 static→redis→memcached→mysql 兜底，属预期不是故障。
        array('driver' => 'static'),
        array('driver' => 'apcu',      'config' => array('cachepre' => 'nncms_')),
        array('driver' => 'redis',     'config' => array('host' => '127.0.0.1', 'port' => 6379, 'cachepre' => 'nncms_')),
        array('driver' => 'memcached', 'config' => array('servers' => array(array('host' => '127.0.0.1', 'port' => 11211)), 'cachepre' => 'nncms_')),
        array('driver' => 'mysql',     'config' => array('cachepre' => 'nncms_')),
      ),
    ),
  ),
  'cache_path' => './runtime/cache/',
  'log_max_size' => 10,
  'log_path' => './runtime/log/',
  'upload_url' => '/upload/',
  'upload_path' => './upload/',
  'sitename' => '牛牛CMS',
  'view_url' => 'template/',
  'path' => './', // 前台路径 "/" 结尾
  'admin_entry' => 'admin.php', // 后台入口文件名（根目录 admin.php，请改名为随机名并同步此配置）
  'logo' => '',
  'logo_m' => '/static/logo.png',
  'logo_pc' => '/static/logo.png',
  'timezone' => 'Asia/Shanghai',  // 时区，默认中国
  'cms_lang' => 'zh_cn',
  'runlevel' => 5,    // 0: 站点关闭; 1: 管理员可读写; 2: 会员可读;  3: 会员可读写; 4：所有人只读; 5: 所有人可读写
  'runlevel_reason' => '网站正在维护中，请稍后访问.',
  'cookie_pre' => 'nncms_', // cookie 前缀
  'cookie_domain' => '',   // cookie使用的域名，为空表示当前域名
  'cookie_path' => '',     // 为空则表示当前目录和子目录
  'cookie_lifetime' => '8640000', // cookie生命期8640000为100天
  'auth_key' => '',
  // 上线前终检（N-03）：API 机器通道是否接受 **legacy 原始 auth_key** 作为 ?token=（0=否，默认）。
  // 派生令牌 api_wire_token() 才是唯一正路；本键留作兼容存量站点的显式开关，**不要在出厂态开**。
  'api_legacy_token' => 0,
  'auth_salt' => '', // **已停用**：原为 get_code() 的云端 code 获取 URL（历史命名，非加密盐），get_code() 已随官方旧云端一并删除；保留本键仅为兼容既有配置文件的键位，代码不再读取
  'pagesize' => 20,
  'postlist_pagesize' => 100,
  'listsize' => 10000,  // 大数据量控制显示100页
  'linksize' => 20,   // 友情链接调用数量 排序数值最大的20个
  'tagsize' => 60,    // 显示tag的数量 
  'tag_num' => 10,   //tag分词数量
  'online_update_span' => 120, // 在线更新频度，大站设置的长一些
  'online_hold_time' => 3600,  // 在线的时间
  'session_delay_update' => 60, // P-9（2026-10-10）：开启 session 延迟更新（秒）——会话数据未变化且 last_date 在窗口内的请求跳过写库（DualSessionHandler 短路），减轻登录态每请求写压力；会导致 useragent/url 等在线列表数据最多延迟本秒数。调 0 关闭。
  'upload_image_width' => 927,    // 上传图片自动缩略的最大宽度
  'upload_resize' => 'thumb',      // 上传图片clip裁切 thumb缩略
  'order_default' => 'lastpid',   // 作为回复排序
  'attach_dir_save_rule' => 'Ymd', // 附件存放规则，附件多用：Ymd，附件少：Ym 
  'user_create_on' => 1,
  'user_resetpw_on' => 0,
  'admin_bind_ip' => 1, // 后台是否绑定 IP 0关闭 1启用
  'login_only' => 0,
  'login_ip' => 0,
  'login_ua' => 0,
  'cdn_on' => 0,
  'cdn_trusted_ips' => array(), // CDN 受信代理/回源 IP 白名单（数组或逗号分隔）；cdn_on=1 时仅这些直连 IP 的转发头被采信，其余回退 REMOTE_ADDR
  'attach_on' => 0, // 附件/云存储分离开关（file_path/admin_access_file/admin_attach_path 读取；1=启用）
  'cloud_url' => '', // 云存储基础 URL（attach_on=1 时生效）
  'user_create_email_on' => 0, // 注册邮箱验证开关（UserService::handleCreate 读取；1=启用）
  'disabled_addons' => 0, // 禁用插件开关（AddonService 读取；1=禁用全部插件）
  'search_cache_time' => 6, // 搜索热词缓存时间（秒，KeywordService 读取）
  'theme' => 'default', // 站点主题目录名（TemplateService 读取）
  'tpl_allow_php' => 0, // 模板内允许原生 PHP（安全开关，默认关闭；view.php 编译期读取）
  'tpl_allow_expr' => 0, // 模板内允许表达式（安全开关，默认关闭；view.php 编译期读取）
  'api_on' => 0, // 默认关闭，打开后前台部分页面可通过api获取数据
  'install_report_on' => 0,
  'install_report' => 0, // 安装时"上报安装信息"勾选结果（1=已上报云端登记，云端异步采集可用；0=未上报）
  'cloud_api' => $nn_cloud_api, // 商店服务端 base URL（域名派生自文件顶部 $nn_cloud_host；须带尾斜杠）。设置后安装上报/云端采集/插件与模板更新/云端 code 均指向该商店；**留空=云端功能整体不可用（无回退）**
  'search_on' => 1, // 1=开启搜索 0=关闭
  'search_use_fulltext' => 0, // P-4（2026-10-10）：搜索走 MySQL ngram 全文索引（1=启用）。启用前必须先建索引：ALTER TABLE nncms_vod ADD FULLTEXT INDEX ft_name (name) WITH PARSER ngram;（MySQL 5.7.6+/8.x；MariaDB 无 ngram 请勿开启）。0=维持 LIKE '%kw%'（兼容 PG/SQLite）
  'ssl_verify' => 1, // curl HTTPS 证书校验：1=启用(安全默认，防 MITM 篡改升级包/模板下载)，0=禁用(仅自签名源兼容需求时开启)
  'outbound_block_private' => 0, // 出站内网/保留段拦截（SSRF 闸）：0=不拦（**默认**，用户裁定——局域网/回环目标一律放行，保障商店推送、内网采集/ES/API 互通），1=恢复拦截（回环/链路本地含云元数据 169.254.169.254/组播/保留段拒绝，cloud_api 主机仍豁免）。scheme 闸、DNS 解析失败 fail-closed 与 CURLOPT_RESOLVE IP 固定始终生效
  'sitemap_play' => 1,
  'sitemap_tag' => 1,
  'sitemap_num' => 1000,
  'force_https' => 0,
  'category_sub_num' => 6,
  'url_rewrite_on' => 0,
  // （历史死键 disabled_plugin 已移除，真实开关为上方 disabled_addons）
  'version' => '1.0.4',
  'installed' => 0,
  'compress' => 1, // 代码压缩 0关闭 1仅压缩php、html代码(不压缩js代码) 2压缩全部代码 如果启用压缩出现错误，请关闭，删除html中的所有注释，并且js代码按照英文分号结束的地方加上分号; 
  'down_pic' => 0,
  'lang' => '国语,英语,粤语,闽南语,韩语,日语,法语,德语,其它',
  'area' => '中国大陆,中国香港,中国台湾,美国,韩国,日本,泰国,新加坡,马来西亚,印度,英国,法国,加拿大,西班牙,俄罗斯,其它',
  'year' => '2026,2025,2024,2023,2022,2021,2020,2019,2018,2017,2016,2015,2014,2013,2012,2011,2010,2009,2008,2007,2006,2005,2004,2003,2002,2001,2000',
  'agg' => '',
  'tg' => 'tg',
  'email' => '',
  'fast_api' => 1,
  'danmu' => 
  array (
    'on' => 0,
    // 'cdn' 已停用（保留键位兼容旧配置）：播放器依赖固定从本站 /static/ 加载，
    // 不引用任何公共 CDN —— 防 CDN 投毒导致前台任意脚本执行。前台模板不再读该键。
    'cdn' => 1,
    'start_ad' => 0,
    'danmu_toggle' => 1,
    'pause_ad' => 1,
    'selec_col' => 1,
    'start_ad_on' => 1,
    'start_img_ad' => '/static/stop.png',
    'start_mp4_ad' => '/static/test1.mp4', // 注意：该示例视频文件未随仓库提供，用视频广告前请先补上
    'start_ad_url' => 'http://' . $nn_cloud_host, // 广告点击跳转（域名派生自文件顶部 $nn_cloud_host）
    'start_all_time' => 4,
    'start_close_time' => 2,
    'stop_ad' => '/static/stop.png',
    'stop_ad_url' => 'http://' . $nn_cloud_host, // 广告点击跳转（域名派生自文件顶部 $nn_cloud_host）
  ),
  'route' =>
  array(
    'cate' => 'cates',
    'list' => 'lists',
    'vod' => 'vodss',
    'play' => 'plays',
    'type' => 'types',
    'tag' => 'tagss',
    'art' => 'arts',
  ),
  'seo' =>
  array(
    'index' =>
    array(
      'seo_title' => '2026年最新电影电视剧无广告免费观看完整未删减-{$sitename}最新网站',
      'seo_des' => '{$sitename}是一家免费VIP影视观看网站，专业全网收集好看的电视剧电影{$sitename}，体验最近热播的犯罪片、家庭片、青春片、综艺片、BD1080P版、极致的观看体验、便捷的高速播放、更多电影高清在线尽在{$sitename}',
      'seo_key' => '{$sitename},免费电影在线影视网站，BD加长，枪战片,字幕组,战争片,推荐短剧',
    ),
    'list' =>
    array(
      'seo_title' => '{$name}推荐-好看的{$name}-最新{$name} - 第{$page}页 - {$sitename}',
      'seo_des' => '{$name}栏目收集整理最新好看的{$name}大全，提供{$name}排行榜前十推荐，{$page}为网友提供高清全集完整版画质的{$name}，同时支持手机无广告在线免费播放',
      'seo_key' => '好看的{$name},{$name},{$name}排行榜,最新{$name},经典{$name}',
    ),
    'art_list' =>
    array(
      'seo_title' => '{$name}推荐-{$name}-最新{$name} - {$sitename}',
      'seo_des' => '{$name}栏目收集整理最新好看的{$name}大全，提供{$name}排行榜前十推荐，{$name}，同时支持手机无广告在线浏览',
      'seo_key' => '好看的{$name},{$name}排行榜,最新{$name},{$name}',
    ),
    'art' =>
    array(
      'seo_title' => '《{$name}》{$cate_name},{$name}-最新资讯- {$sitename}',
      'seo_des' => '{$name}栏目收集整理最新好看的{$name}大全，提供{$name}排行榜前十推荐，{$name}，同时支持手机无广告在线浏览',
      'seo_key' => '{$des}',
    ),
    'type' =>
    array(
      'seo_title' => '{$year}{$area}{$lang}{$name}推荐-好看的{$name}-{$lang}{$area}{$year}最新{$name}  - {$sitename}',
      'seo_key' => '好看的{$name},{$lang}{$area}{$year},{$name},{$name}排行榜,最新{$name},经典{$name}',
      'seo_des' => '{$name}栏目收集整理最新好看的{$year}{$lang}{$area}{$name}大全，提供{$name}排行榜前十推荐，为网友提供高清全集完整版画质的{$name}，同时支持手机无广告在线免费播放',
    ),
    'vod' =>
    array(
      'seo_title' => '《{$name}》{$cate_name}免费在线观看,{$name}-{$remarks}完整版- {$sitename}',
      'seo_key' => '{$name}高清在线观看,{$name}免费完整版,{$name}剧情介绍,{$sitename}',
      'seo_des' => '{$des}',
    ),
    'play' =>
    array(
      'seo_title' => '《{$name}》{$play_name}免费在线观看完整版- {$sitename}',
      'seo_key' => '{$name}{$play_name}高清在线观看,在线观看,{$name}免费播放,{$sitename}',
      'seo_des' => '{$sitename}为您带来《{$name}》{$play_name}高清完整版免费在线观看。',
    ),
    'tag' =>
    array(
      'seo_title' => '{$name}电影推荐-好看的{$name}电影-最新{$name}综艺 - 第{$page}页 - {$sitename}',
      'seo_key' => '好看的{$name}视频,{$name},{$name}电视剧排行榜,最新{$name}电影,经典{$name}综艺',
      'seo_des' => '{$name}栏目收集整理最新好看的{$name}电视剧大全，提供{$name}电影排行榜前十推荐，为网友提供高清全集完整版画质的{$name}，同时支持手机无广告在线免费播放',
    ),
    'tag_list' =>
    array(
      'seo_title' => '2026年最好看的电影，免费观看高清电影在线观看大全_2026年最新热映电影排行榜推荐_高清免费观看电影-{$sitename}',
      'seo_key' => '2026年最好看的电影，免费观看高清电影大全,最近好看的电影在线观看大全,2026年最新热映电影排行榜推荐,{$sitename}',
      'seo_des' => '2026年最好看的电影，免费观看高清电影大全{$sitename}为你提供好看的电影大全，最新热映电影高清免费观看推荐及相关排行榜等影视电影院资源。',
    ),
    'searchs' =>
    array(
      'seo_title' => '{$name} 相关影视电影院资源搜索结果 -{$sitename}',
      'seo_key' => '{$name}相关影视电影院资源搜索结果,{$name}在线观看,{$sitename}',
      'seo_des' => '搜索“{$name}”相关影视，共找到{$num}部 - {$sitename}',
    ),
  ),
  'api_api' =>  array(
    'vod' =>
    array(
      'status' => '1', //审核状态
      'hits_start' => '1', //随机点击
      'hits_end' => '1000', //随机点击
      'updown_start' => '1', //随机顶
      'updown_end' => '1000', //随机顶踩
      'score' => '1', //随机评分
      'pic' => '1', //自动图片下周
      'tag' => '1', //自动tag
      'class_filter' => '0', //将自动过滤扩分类名称里的[片,剧],例如动作片会变为动作;欧美剧会变成欧美;
      'psename' => '0', //自动转换名称同义词，降低重复率。例如:第1季=第一季;
      'psernd' => '0', //详情随机插入语句
      'psesyn' => '0', //详情同义词替换
      'pseplayer' => '0', //播放器同义词替换
      'psearea' => '0', //地区同义词替换
      'pselang' => '0', //语言同义词替换
      'urlrole' => '1', //地址二更规则 二次更新地址遇到同类型播放器。替换：只保留新提交的地址。合并：整合原有地址和新地址去重。
      'uprule' => ',a,c,d', //二次更新规则
      'filter' => '色戒,色即是空', //数据过滤
      'namewords' => '第1季=第一季#第2季=第二季#第3季=第三季#第4季=第四季',
      'thesaurus' => ' =',
      'playerwords' => '',
      'areawords' => '',
      'langwords' => '',
      'words' => '',
      'inrule' => ',a',
    ),
    'art' =>
    array(
      'status' => '1',
      'hits_start' => '1',
      'hits_end' => '1000',
      'updown_start' => '1',
      'updown_end' => '1000',
      'score' => '1',
      'pic' => '0',
      'tag' => '0',
      'psernd' => '0',
      'psesyn' => '0',
      'inrule' => ',b',
      'uprule' => ',a,d',
      'filter' => '无奈的人',
      'thesaurus' => '',
      'words' => '',
    ),
  ),
  'vodplayer' => array(
    'default' =>
    array(
      'status' => '1',
      'from' => 'default',
      'show' => '默认播放器',
      'des' => '默认播放器',
      'target' => '_self',
      'ps' => '1',
      // 空串 = 站内本地播放器（artplayer + 弹幕；形态口径见 core/func/url.php player_parse_kind）。
      // 曾经这里是一个第三方解析站地址（外链别人的解析服务，对方挂掉/插广告/看到本站资源直链）。
      // 要换回第三方解析：后台「播放器设置」在该条目的 URL 里填完整 http(s) 地址即可。
      'parse' => '',
      'sort' => 100,
      'tip' => '站内本地播放器，无需安装任何插件',
    ),
  ),
  'api' => array(),
  'bind' => array(),
  'es_on' => 0,
  'es' =>
  array (
    'host' => '',
    'index' => 'vod_search',
    'user' => '',
    'pass' => '',
    'api_key' => '',
  ),
);
