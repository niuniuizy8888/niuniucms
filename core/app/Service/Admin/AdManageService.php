<?php
namespace App\Service\Admin;

class AdManageService
{
    const AREA_OPTIONS = array(
        'all' => '全站',
        'index' => '首页',
        'cate' => '分类',
        'art' => '文章',
        'art_detail' => '文章详情页',
        'list' => '列表',
        'detail' => '详情页',
        'player' => '播放页',
        'search' => '搜索',
        'tag' => '标签',
    );

    const ALLOW_EXT = array('jpg' => 1, 'jpeg' => 1, 'png' => 1, 'gif' => 1, 'webp' => 1);

    /**
     * 广告管理列表页数据装配：按 id 倒序分页（页码钳制 1..10000，VULN-M10 防无界 OFFSET），
     * 组装表单 input、列表 arrlist、pagination、header 与安全令牌（场景 admin_ad，供写操作校验）。
     * 数据读取走兼容壳 ad_find()/ad_count()（底层 RepositoryFactory::table('ad','id')）。
     * @param int $uid 当前管理员 ID（签发 safe_token 用）
     * @return array 视图数据（input/arrlist/pagination/header/safe_token）
     */
    public static function listPage(int $uid): array
    {
        $page = max(1, intval(param('page', 1)));
        $pagesize = 20;
        // 上线前审计修复（VULN-M10）：分页上界钳制——消除无界 OFFSET 索引行走
        $page = min($page, 10000);
        $extra = array('page' => '{page}');
        $input = array(
            'name' => form_text('name', '', FALSE, '名称'),
            'url' => form_text('url', '', FALSE, '跳转链接'),
            'img_url' => form_text('img_url', '', FALSE, '图片地址'),
            'days' => form_text('days', '30', FALSE, '结束天数(默认30天，0为不过期)'),
            'area_options' => self::AREA_OPTIONS,
            'area_default' => 'all',
        );

        return array(
            'input' => $input,
            'arrlist' => ad_find($page, $pagesize),
            'pagination' => pagination(url('ad', $extra, TRUE), ad_count(), $page, $pagesize),
            'header' => array(
                'title' => lang('ad_manage'),
                'mobile_title' => lang('ad_manage'),
                'mobile_link' => url('ad', '', TRUE),
            ),
            'safe_token' => bull_token_set($uid, 'admin_ad'),
        );
    }

    /**
     * 新建广告：先 verifyToken（admin_ad 场景，失败即 message 终止）→ 取参（admin_param_* 已带长度钳制）
     * → name/area 过 filter_all_html，url/img_url 过 safeUrl（仅放行 http/https，防 javascript: 注入）
     * → days>0 折算 end_time，0=不过期；ad_create 返回 FALSE 即 message(-1)。成功写 ad_create 审计。
     * @param int $uid 管理员 ID
     * @param int $time 服务器时间戳（add_time/end_time 计算基准）
     * @return void 失败经 message 终止；本函数无成功返回值
     */
    public static function create(int $uid, int $time): void
    {
        self::verifyToken($uid);

        $name = filter_all_html(admin_param_text('name', '', 100));
        $url = self::safeUrl(admin_param_text('url', '', 255));
        $img_url = self::safeImgUrl(admin_param_text('img_url', '', 255));
        $area = filter_all_html(admin_param_text('area', '', 32));
        $days = admin_param_int('days', 30, 0, 3650);
        $arr = array(
            'name' => $name,
            'url' => $url,
            'img_url' => $img_url,
            'area' => $area,
            'add_time' => $time,
            'end_time' => $days > 0 ? ($time + $days * 86400) : 0,
        );
        FALSE === ad_create($arr) and message(-1, lang('create_failed'));

        admin_audit_log('ad_create', array('name' => $name, 'area' => $area));
    }

    /**
     * 更新广告：verifyToken 后按字段非空增量组装 update 数组（空串视为不修改；days 传空不动 end_time，
     * 传 0 置过期时间清零=不过期）；全空 message(1) 提示，ad_update 返回 FALSE 即 message(-1)。
     * URL 同样过 safeUrl 白名单。成功写 ad_update 审计（只记变更字段名）。
     * @param int $uid 管理员 ID
     * @param int $time 服务器时间戳（重算 end_time 基准）
     * @return void
     */
    public static function update(int $uid, int $time): void
    {
        self::verifyToken($uid);

        $id = admin_param_int('id');
        $update = array();
        $name = admin_param_text('name', '', 100);
        $url = self::safeUrl(admin_param_text('url', '', 255));
        $img_url = self::safeImgUrl(admin_param_text('img_url', '', 255)); // 列宽 255；允许本地 upload/ 相对路径
        $area = admin_param_text('area', '', 32);
        $days = param('days', '');
        $name !== '' and $update['name'] = filter_all_html($name);
        $url !== '' and $update['url'] = $url;
        $img_url !== '' and $update['img_url'] = $img_url;
        $area !== '' and $update['area'] = filter_all_html($area);
        $days !== '' and $update['end_time'] = intval($days) > 0 ? ($time + intval($days) * 86400) : 0;
        empty($update) and message(1, lang('data_is_empty'));
        FALSE === ad_update($id, $update) and message(-1, lang('update_failed'));

        admin_audit_log('ad_update', array('id' => $id, 'fields' => array_keys($update)));
    }

    /**
     * 广告图 base64 上传：verifyToken → 上传限流（同 uid 20 次/分钟，ThrottleService）→ 取 data:
     * 原始参数（param 第三参 FALSE 不转义）→ 扩展名取 mimeExt 的 MIME 映射，取不到回退 filename 后缀，
     * 须命中 ALLOW_EXT（jpg/jpeg/png/gif/webp）→ base64 解码后交 StorageService::saveBinary
     * （含真实 MIME 锚定 image/ 前缀且拒 svg、体积上限）。任一步失败即 message 终止。
     * @param int $uid 管理员 ID
     * @param int $time 服务器时间戳（未直接使用，签名与兄弟方法对齐）
     * @param array $conf 站点配置（未直接使用，同上）
     * @return array 形如 array('url'=>访问地址)，回给前端填入 img_url 表单
     */
    public static function uploadImage(int $uid, int $time, array $conf): array
    {
        self::verifyToken($uid);
        // 上传限流：同用户 20 次/分钟
        if (!\App\Service\System\ThrottleService::check('upload_ad', (string)$uid, 20, 60)) {
            message(-1, '上传过于频繁，请稍后再试');
        }

        $data = param('data', '', FALSE);
        empty($data) and message(-1, '请选择图片');
        $filename = param('filename', '');
        $ext = self::mimeExt($data);
        empty($ext) and $ext = file_ext($filename);
        !isset(self::ALLOW_EXT[$ext]) and message(-1, '不支持的图片格式');

        $bin = base64_decode_file_data($data);
        empty($bin) and message(-1, '图片解析失败');

        // 统一存储抽象
        $stored = \App\Service\System\StorageService::saveBinary($bin, $ext, 'ad');
        empty($stored) and message(-1, '保存失败');

        admin_audit_log('ad_upload', array('file' => $stored['path'], 'size' => strlen($bin)));
        return array('url' => $stored['url']);
    }

    /**
     * 删除广告：verifyToken 后取 id（admin_param_int）交兼容壳 ad_delete；返回 FALSE 即 message(-1)。
     * 成功写 ad_delete 审计。
     * @param int $uid 管理员 ID
     * @return void
     */
    public static function delete(int $uid): void
    {
        self::verifyToken($uid);

        $id = admin_param_int('id');
        FALSE === ad_delete($id) and message(-1, lang('delete_failed'));

        admin_audit_log('ad_delete', array('id' => $id));
    }

    // 链接仅允许 http/https（与 LinkManageService::normalizeUrl 同口径，防 javascript: 注入）
    /**
     * 广告链接/图片地址的 scheme 白名单闸：**只放行以 http:// 或 https:// 开头的串**，
     * 其余（javascript: / data: / vbscript: / 协议相对双斜杠 / 站内相对路径）一律返回**空串**
     * ——是「静默改空」而不是拒绝报错，保存链路照常成功，只是该列变成空值，
     * 运营侧表现为「链接填了却没生效」，排查时先看这里而不是查库。
     * 空串入参按「未填」对待，原样返回空串（不视为非法）。
     * ⚠ 边界口径（红线 §二.4）：本闸**只判前缀**，不校验后续字符、也**不做输出转义**——
     *   与 player_parse_kind() 那套「scheme + 字符闸」的严格口径不同（那边还会拦空白/引号/尖括号/反斜杠），
     *   两处刻意保持各自的适用范围，别互相抄；广告位输出到模板前仍需按上下文转义（模板裸 echo）。
     * @param string $url 后台表单传来的地址（签名已声明 string，取参侧是 admin_param_text，长度限 255 与列宽对齐）
     * @return string 合法的 http/https 地址原文，或空串（非法/未填）
     */
    private static function safeUrl(string $url): string
    {
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return $url;
    }

    /**
     * 广告图片地址：http/https 外，额外放行 StorageService 产出的本地 upload/ 相对路径
     * （upload_url 常为 upload/，无 scheme；跳转链接仍走 safeUrl，禁止 javascript:）。
     * @param string $url
     * @return string
     */
    private static function safeImgUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $path = str_replace('\\', '/', $url);
        if (strpos($path, '..') !== false) {
            return '';
        }
        $path = ltrim($path, '/');
        if (preg_match('#^upload/[A-Za-z0-9._/-]+$#', $path)) {
            return $path;
        }
        return '';
    }

    /**
     * 本服务全部写操作的 CSRF/会话门禁：取 param('safe_token') 交 admin_bull_token_guard
     * （场景 admin_ad，绑定 uid/IP/UA/时效指纹环，fail-closed——校验不过即 message 终止，不返回）。
     * @param int $uid 管理员 ID
     * @return void
     */
    private static function verifyToken(int $uid): void
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, 'admin_ad', $safeToken);
    }

    /**
     * 从 data:image/...;base64 前缀解析 MIME 并映射为允许的图片扩展名（签名信任的映射，
     * 非通用 MIME 表；jpeg 归一 jpg）。前缀不符、缺分号或不在映射内返回空串，
     * 调用方据此回退文件名后缀或拒绝。
     * @param string $data 原始 base64 data-URI 字符串
     * @return string 扩展名（jpg/png/gif/webp）或空串
     */
    private static function mimeExt(string $data): string
    {
        if (strpos($data, 'data:image/') !== 0) return '';
        $semi = strpos($data, ';');
        if ($semi === FALSE) return '';
        $mime = substr($data, 5, $semi - 5);
        $map = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp');
        return isset($map[$mime]) ? $map[$mime] : '';
    }
}
