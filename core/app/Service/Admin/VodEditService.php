<?php
namespace App\Service\Admin;

class VodEditService
{
    /**
     * 豆瓣海报批量同步：从商店云 code 的 hb 列表（cloudUrl 归一后 https_request 整包拉取）
     * 建 douban_id => pic_slide 映射，单条 CASE WHEN 批量 UPDATE vod 主表。
     * 守卫（历史事故「海报误刷全站」修复注释在案）：滤空 douban_id/无海报条目，空集直接返回 0。
     * @return int 受影响行数（0=云端不可达、无有效数据或全部未命中）
     */
    public static function posterSync(): int
    {
        $res = \App\Service\System\CloudOpsService::codes();
        if (!is_array($res) || empty($res['hb'])) return 0;
        // 海报列表地址来源：商店云 code 的 `hb` 字段（**商店下发绝对 URL**，
        // 实测 `http://api.example.com/?hb`；旧版为站内相对 `"/?hb"`，两种都必须兼容）。
        // legacy 云端下发**密文**（core_decrypt 对明文返回空串，实测），故统一走 cloudUrl() 归一：
        // 绝对 URL 原样放行 / 相对路径补 `{cloud_api}` 基址 / 商店主机 URL 改写为 cloud_api 基址。
        // 真机实测：hb 绝对地址 → 归一后不变 → 出站闸放行(pin 192.0.2.100) →
        // 实拉 567,302 B / 5629 条（4378 条有 douban_id，其余 1251 条同步时被滤掉）。
        $decodeurl = \App\Service\System\CloudOpsService::cloudUrl($res['hb']);
        if ('' === $decodeurl) return 0;
        $hb = https_request((string)$decodeurl, '', '', 30, 0, 8 * 1048576);
        $hbarr = xn_json_decode($hb)['hb'] ?? array();
        if (empty($hbarr) || !is_array($hbarr)) return 0;
        // 修复（海报误刷全站）：商店 `?hb` 里**有 1251 条 douban_id 为空**（实测 5629 条中），
        // 旧实现直接 `array_column($hbarr,'douban_id')` 入条件 ⇒ 条件里含 ''，把**所有 douban_id 为空
        // 的视频**（本站 90 条）全部匹配上，并统一刷成"最后一条空 douban_id 条目的海报"——实测一次点
        // 「更新海报」把 90 条无关视频刷了同一张错图。现**先滤掉 douban_id 为空/无海报的条目**。
        $hbarr = array_values(array_filter($hbarr, function ($row) {
            return is_array($row)
                && '' !== trim((string)($row['douban_id'] ?? ''))
                && '' !== trim((string)($row['pic_slide'] ?? ''));
        }));
        if (empty($hbarr)) return 0;
        $douban_ids = array_values(array_unique(array_column($hbarr, 'douban_id')));
        $cond = array('douban_id' => $douban_ids);
        $update = array_reindex_by($hbarr, 'douban_id', 'pic_slide');
        return intval(\App\Repository\RepositoryFactory::vod()->bigUpdate($cond, $update, NULL));
    }

    /**
     * 统计有幻灯海报（pic_slide 非空）的视频数，供海报列表分页。
     * 注意：$d 形参只用作「连接存在」的守卫（builder() 并未透传 $d，查询恒走默认连接）。
     * @param mixed $d 连接选择器（现网调用点均缺省；无连接时返回 0，fail-closed）
     * @return int 计数；连接缺失或查询失败为 0
     */
    public static function posterCount($d = NULL): int
    {
        $db = _SERVER('db') ?? NULL;
        $d = $d ?: $db;
        if (!$d) return 0;
        $rows = \App\Repository\RepositoryFactory::vod()->builder()->select(array('COUNT(*) AS n'))->where('pic_slide', '!=', '')->first();
        return $rows ? intval($rows['n']) : 0;
    }

    /**
     * 分页取带海报的视频（vid 倒序、LIMIT offset 深分页形态）并逐行 VideoService::format 补展示字段。
     * 注意：builder() 未限定列 = 实际 SELECT *（vod 含 play_url 大字段），且 R10 的 sql_star_select 哨兵不覆盖直连 builder 路径（已上报）。
     * @param int $page 页码（max(1,intval)）
     * @param int $pagesize 每页条数（route 固定传 20）
     * @param mixed $d 连接选择器（仅作存在性守卫，builder() 未透传，同 posterCount）
     * @return array|false 已格式化的 vid => 行列表（键由 arrlist_multisort 按 vid 重建）；无连接返回 FALSE；无命中返回 get() 的空数组
     */
    public static function posterList(int $page, int $pagesize, $d = NULL)
    {
        $db = _SERVER('db') ?? NULL;
        $d = $d ?: $db;
        if (!$d) return FALSE;
        $page = max(1, intval($page));
        $pagesize = intval($pagesize);
        $start = ($page - 1) * $pagesize;
        $videolist = \App\Repository\RepositoryFactory::vod()->builder()->where('pic_slide', '!=', '')->orderBy('vid', 'DESC')->limit($pagesize, $start)->get();
        if ($videolist) {
            $videolist = arrlist_multisort($videolist, 'vid', FALSE);
            foreach ($videolist as &$video) {
                \App\Service\Content\VideoService::format($video);
            }
            unset($video);
        }
        return $videolist;
    }

    /**
     * 海报列表页数据装配（route/vod.php case 'posterlist'，权限位 managevod；该 case 无 method 分支、只读）。
     * safe_token 属 'admin_vod_batch' 场景（供页面内 vod-del 批量删除校验；「更新海报」按钮的
     * 'admin_vod_poster' 场景 token 由 VodManageService::listPage 下发，不在本页）。
     * @param int $page 页码
     * @param int $pagesize 每页条数
     * @param int $uid 当前管理员 uid
     * @return array 模板变量表（header/videolist/pagination/safe_token/...）
     */
    public static function posterListPage(int $page, int $pagesize, int $uid): array
    {
        $n = self::posterCount();
        $videolist = self::posterList($page, $pagesize);

        return array(
            'header' => array(
                'title' => lang('vod_page'),
            ),
            'cid' => 0,
            'videolist' => $videolist ?: NULL,
            'pagination' => pagination(url('vod-posterlist', array('page' => '{page}'), TRUE), $n, $page, $pagesize),
            'safe_token' => bull_token_set($uid, 'admin_vod_batch'),
            'keyword' => '',
        );
    }

    /**
     * 「更新海报」写操作入口（route case 'poster'：POST 门禁 + 本方法内 CSRF）：
     * admin_bull_token_guard 场景 'admin_vod_poster' fail-closed → posterSync() → 审计 vod_poster_update。
     * @param int $uid 当前管理员 uid（路由层注入）
     * @return array{code:int, message:string} 供 message() 输出的响应体
     */
    public static function posterSyncAction(int $uid): array
    {
        $safeToken = param('safe_token');
        admin_bull_token_guard($uid, 'admin_vod_poster', $safeToken);
        $count = self::posterSync();
        admin_audit_log('vod_poster_update', array('count' => intval($count)));
        return array('code' => 0, 'message' => jump("更新海报 $count 条", url('vod-posterlist', array(), TRUE), 3));
    }

    /**
     * 海报图上传校验与落盘（route case 'uploadposterpic'：POST + 本方法内 CSRF 场景 'admin_vod_uploadposter'）。
     * 防线依次为：CSRF → ThrottleService 同用户 30 次/分钟（超限 message 拒）→ UPLOAD_ERR 映射报错 →
     * 5MB 体积闸 → 扩展名白名单（无扩展名时按 MIME 推断）→ StorageService 真实 MIME 校验并落盘
     * upload/poster/{Ym}/。**只落文件不写 vod 表**——回写 pic_slide 由前端拿返回 URL 再提交编辑。
     * @param array $conf 站点配置（当前实现仅签名占位，未读取）
     * @param int $uid 当前管理员 uid
     * @return array{vid:int, url:string, path:string} 上传结果；各校验失败均 message(-1) 中断
     */
    public static function uploadPosterPic(array $conf, int $uid): array
    {
        $safe_token = param('safe_token');
        admin_bull_token_guard($uid, 'admin_vod_uploadposter', $safe_token);
        // 上传限流：同用户 30 次/分钟
        if (!\App\Service\System\ThrottleService::check('upload_poster', (string)$uid, 30, 60)) {
            message(-1, '上传过于频繁，请稍后再试');
        }
        empty($_FILES['poster_pic']) and message(-1, lang('file_not_exists'));

        $file = $_FILES['poster_pic'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = array(
                UPLOAD_ERR_INI_SIZE => '上传的文件超过了 php.ini 中 upload_max_filesize 指令限制的大小',
                UPLOAD_ERR_FORM_SIZE => '上传的文件超过了 HTML 表单中指定的 MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                UPLOAD_ERR_NO_FILE => '没有文件被上传',
                UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                UPLOAD_ERR_EXTENSION => 'PHP 扩展停止了文件上传',
            );
            $error_msg = $errors[$file['error']] ?? '未知上传错误';
            message(-1, $error_msg);
        }
        empty($file['tmp_name']) and message(-1, lang('file_not_exists'));

        $size = intval($file['size']);
        $size > 5 * 1024 * 1024 and message(-1, '图片太大，最大支持 5MB');

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (empty($ext)) {
            $mime_map = array(
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            );
            $ext = $mime_map[$file['type']] ?? '';
        }

        $allowed_types = array('jpg', 'jpeg', 'png', 'webp', 'gif');
        !in_array($ext, $allowed_types, TRUE) and message(-1, '仅支持 jpg/jpeg/png/webp/gif 格式的图片');

        $vid = intval(param('vid', 0));
        // 统一存储抽象：校验（真实 MIME）+ 保存 upload/poster/{Ym}/{rand}.{ext}
        $stored = \App\Service\System\StorageService::saveUploadedFile($file, 'poster');
        empty($stored) and message(-1, lang('upload_failed'));

        return array(
            'vid' => $vid,
            'url' => $stored['url'],
            'path' => $stored['path'],
        );
    }

    /**
     * 视频编辑页装配（route case 'update' GET，权限位 managevod）：VideoService::read 校验存在
     * （fail-closed message），预登记 'admin_vod_uploadposter' 场景 token 供海报弹窗使用，
     * 并下发 columnlist（分类下拉，历史上漏发导致无法选分类——注释在案）。
     * @param int $vid 视频 id
     * @param int $page 来源列表页码（返回时带回，保持翻页上下文）
     * @param int $uid 当前管理员 uid
     * @return array 模板变量表（header/video/cid/columnlist/extra/referer/safe_token）
     */
    public static function editPage(int $vid, int $page, int $uid): array
    {
        empty($vid) and message(1, lang('data_malformation'));
        $video = \App\Service\Content\VideoService::read($vid);
        empty($video) and message(1, lang('video_not_exists'));

        bull_token_set($uid, 'admin_vod_uploadposter');
        return array(
            'header' => array(
                'title' => lang('edit'),
                'mobile_title' => lang('edit'),
            ),
            'vid' => $vid,
            'page' => $page,
            'video' => $video,
            'cid' => $video['cid'],
            // 修复：vod_update.html 分类下拉遍历 $columnlist，此前 editPage 未下发致下拉恒空无法选分类
            'columnlist' => category_list(cate_list_cache()),
            'extra' => array('vid' => $vid, 'page' => $page),
            'referer' => http_referer(),
            'safe_token' => bull_token_set($uid, 'admin_vod_update'),
        );
    }

    /**
     * 视频编辑保存（route case 'update' POST）——后台写操作三件套中的 Service 侧：
     * admin_bull_token_guard('admin_vod_update', 用路由 $uid 而非会话 uid，失配修复注释在案) →
     * admin_param_int/text 取参 → **入库转义**（纯文本 htmlspecialchars、content 走 xn_html_safe、
     * play_url 控制符清洗 + xn_url_strip；前台 {$player} 裸 echo 依赖此处）→ VideoService::update。
     * 成功后同步 vod_vid 索引 cid（改分类滞留旧类问题修复）、维护置顶（sticky=1 重建，否则删除）。
     * @param int $vid 视频 id
     * @param int $uid 路由层管理员 uid（token 生成/校验必须同源）
     * @return bool FALSE=VideoService::update 写主表失败；TRUE=已写主表（索引/置顶维护为尽力而为，不反馈到返回值）
     */
    public static function update(int $vid, int $uid = 0): bool
    {
        // 修复：改用路由 $uid 校验（与 editPage 生成 token 的 uid 同一来源）。
        // 此前读会话 uid，与会话过期后 user_rest() 走 user_token cookie 兜底的
        // 路由 $uid 可能不一致（uid 失配 → bull_token_decrypt 失败 → 非法操作）；
        // 全后台其余 token 校验（admin_require_bull_token / posterSyncAction / uploadPosterPic）均用路由 $uid。
        $safe_token = param('safe_token');
        admin_bull_token_guard($uid, 'admin_vod_update', $safe_token);

        $update = array(
            // 纯文本展示字段入库转义（与采集导入面同口径，防管理端输入 → 前台存储型 XSS；审计修复）
            'name' => htmlspecialchars(admin_param_text('name', '', 255), ENT_QUOTES, 'UTF-8'),
            'sub' => htmlspecialchars(admin_param_text('sub', '', 255), ENT_QUOTES, 'UTF-8'),
            'alias' => admin_param_text('alias', '', 32),
            'cid' => admin_param_int('cid', 0),
            'sticky' => admin_param_int('sticky', 0, 0, 1),
            'status' => admin_param_int('status', 0, 0, 9),
            'area' => htmlspecialchars(admin_param_text('area', '', 20), ENT_QUOTES, 'UTF-8'),
            'lang' => htmlspecialchars(admin_param_text('lang', '', 10), ENT_QUOTES, 'UTF-8'),
            'year' => htmlspecialchars(admin_param_text('year', '', 10), ENT_QUOTES, 'UTF-8'),
            'version' => htmlspecialchars(admin_param_text('version', '', 30), ENT_QUOTES, 'UTF-8'),
            'en' => htmlspecialchars(admin_param_text('en', '', 255), ENT_QUOTES, 'UTF-8'),
            'letter' => admin_param_text('letter', '', 1),
            'views' => admin_param_int('views', 0),
            'favorites' => admin_param_int('favorites', 0),
            'likes' => admin_param_int('likes', 0),
            'douban_id' => admin_param_int('douban_id', 0),
            'douban_score' => min(9.9, max(0, floatval(param('douban_score', 0)))),
            'actor' => htmlspecialchars(admin_param_text('actor', '', 255), ENT_QUOTES, 'UTF-8'),
            'director' => htmlspecialchars(admin_param_text('director', '', 255), ENT_QUOTES, 'UTF-8'),
            'writer' => htmlspecialchars(admin_param_text('writer', '', 100), ENT_QUOTES, 'UTF-8'),
            'behind' => htmlspecialchars(admin_param_text('behind', '', 100), ENT_QUOTES, 'UTF-8'),
            'pic' => htmlspecialchars(admin_param_text('pic', '', 1024), ENT_QUOTES, 'UTF-8'),
            'pic_slide' => htmlspecialchars(admin_param_text('pic_slide', '', 1024), ENT_QUOTES, 'UTF-8'),
            'play_from' => admin_param_text('play_from', '', 255),
            'blurb' => htmlspecialchars(admin_param_text('blurb', '', 255), ENT_QUOTES, 'UTF-8'),
            'remarks' => htmlspecialchars(admin_param_text('remarks', '', 100), ENT_QUOTES, 'UTF-8'),
            'content' => ($content = param('content', '', FALSE)) ? xn_html_safe($content) : '',
            // play_url 为「名$址#名$址」拼接串且前台以 {$player} 裸 echo 进 iframe src——控制符清洗之上叠加 HTML 危险字符闸（红线：URL 仅 http/https + 输出必须转义）
            'play_url' => ($play_url = param('play_url', '', FALSE)) ? xn_url_strip(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $play_url)) : '',
            'uptime' => date('Y-m-d H:i:s'),
        );

        $result = \App\Service\Content\VideoService::update($vid, $update);
        if (FALSE === $result) {
            return FALSE;
        }

        // 同步 vod_vid 索引的 cid（前台分类页/计数按 vod_vid 走；此前改分类只写主表，视频滞留旧分类，审计修复）
        if (!empty($update['cid'])) {
            \App\Repository\RepositoryFactory::vodVid()->update(array('vid' => intval($vid)), array('cid' => intval($update['cid'])));
        }

        if (1 == intval($update['sticky'])) {
            $_video = \App\Service\Content\VideoService::read($vid);
            if ($_video) {
                \App\Service\Content\VideoService::stickyChange($vid, intval($update['sticky']), $_video);
            }
        } else {
            \App\Service\Content\VideoService::stickyDelete($vid);
        }
        return TRUE;
    }

    /**
     * update() 的响应包装：失败即 message(-1) 中断（fail-closed），成功记审计 vod_update 并返回跳转体。
     * @param int $vid 视频 id
     * @param int $uid 路由层管理员 uid
     * @return array{code:int, message:string}
     */
    public static function updateAction(int $vid, int $uid = 0): array
    {
        $result = self::update($vid, $uid);
        FALSE === $result and message(-1, lang('update_failed'));
        admin_audit_log('vod_update', array('vid' => $vid));
        return array('code' => 0, 'message' => jump('更新成功', url('vod-list', array(), TRUE), 3));
    }

    /**
     * uploadPosterPic() 的响应包装：记审计 vod_poster_upload（vid+落盘路径）后输出 JSON 友好结构。
     * @param array $conf 站点配置（透传给 uploadPosterPic）
     * @param int $uid 当前管理员 uid
     * @return array{code:int, message:string, data:{url:string, path:string}}
     */
    public static function uploadPosterPicAction(array $conf, int $uid): array
    {
        $data = self::uploadPosterPic($conf, $uid);
        admin_audit_log('vod_poster_upload', array('vid' => $data['vid'], 'file' => $data['path']));
        return array(
            'code' => 0,
            'message' => '上传成功',
            'data' => array('url' => $data['url'], 'path' => $data['path']),
        );
    }
}
