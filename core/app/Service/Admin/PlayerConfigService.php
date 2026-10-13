<?php
namespace App\Service\Admin;

class PlayerConfigService
{
    /**
     * 新增/覆盖一个播放器条目（写 KV 键 vodplayer，结构 conf['vodplayer'][code]）。
     *
     * 校验顺序：code/name 为空 → message() 直接渲染提示页（后台 AJAX 走 JSON 分支）并 exit 终止请求，不返回错误数组，调用方别指望 try/catch；
     * 地址形态用前台同款 player_parse_kind() 判定，不另写 scheme 规则——空串/@local=inline（站内内置播放器）、
     * / 开头=same（同源 iframe）、http(s)=remote（第三方解析站）、其余 bad 直接拒绝返回 success=false。
     * remote 分支的 SSRF 闸：仅当 conf['outbound_block_private']=1 时解析 host 并拒私有/保留地址
     * （用户裁定默认关闭，局域网部署下解析站常在 LAN）。inline 会把地址归零成空串存储。
     *
     * 落库 kv_set('vodplayer') 并同步失效缓存，随后 admin_audit_log('player_add') 留痕（记 code/name/kind，不记地址）。
     * 注意这是**按 code 覆盖**写入（同 code 直接改写整条），不做重名确认。
     *
     * @param string $player_code 播放器标识（同时作 from 与数组键）
     * @param string $player_name 显示名（同时作 show/des）
     * @param string $player_url 解析地址（可为空串=站内本地播放器）
     * @param int $player_rank 排序值（越小越前，写入 sort）
     * @return array {success:bool, msg:string}；success=FALSE 的两种情况：地址形态非法、命中内网地址闸
     */
    public static function add(string $player_code, string $player_name, string $player_url, int $player_rank = 0): array
    {
        $conf = _SERVER('conf');

        empty($player_code) && message(1, lang('player_code_is_empty'));
        empty($player_name) && message(1, lang('player_name_is_empty'));

        // 形态口径与前台 PlayService 完全共用 player_parse_kind()，不在此另写一份 scheme 判断：
        //   空串 / @local = 站内本地播放器（artplayer + 弹幕）；/开头 = 站内播放页（同源 iframe）；
        //   http(s) = 第三方解析站。旧实现把「空地址」直接判非法，等于无法选择本站播放器。
        $kind = player_parse_kind($player_url);
        if ('bad' === $kind) {
            return array('success' => false, 'msg' => '播放器解析地址不合法：可留空（用站内本地播放器）、填站内路径（以 / 开头）或填 http/https 完整地址');
        }
        if ('remote' === $kind) {
            // 用户裁定：内网/保留地址限制**默认关闭**（局域网部署下解析站常在 LAN），
            // 仅当 conf['outbound_block_private']=1 时恢复拒绝——与出站闸共用同一开关，保持单一口径。
            $parsed_url = parse_url($player_url);
            if (outbound_block_private() && !empty($parsed_url['host'])) {
                $host = $parsed_url['host'];
                $ip = gethostbyname($host);
                if ($ip !== $host && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return array('success' => false, 'msg' => '禁止使用内网/保留地址作为播放器解析地址');
                }
            }
        }
        $player_url = ('inline' === $kind) ? '' : trim($player_url);
        $tip = ('remote' === $kind) ? '无需安装任何插件' : '站内本地播放器，无需安装任何插件';

        $arr = isset($conf['vodplayer']) && is_array($conf['vodplayer']) ? $conf['vodplayer'] : array();
        $arr[$player_code] = array(
            'status' => '1',
            'from' => $player_code,
            'show' => $player_name,
            'des' => $player_name,
            'target' => '_self',
            'ps' => '1',
            'parse' => $player_url,
            'sort' => $player_rank,
            'tip' => $tip,
        );
        $conf['vodplayer'] = $arr;
        kv_set('vodplayer', $conf['vodplayer']);
        admin_audit_log('player_add', array('code' => $player_code, 'name' => $player_name, 'kind' => $kind));
        return array('success' => true, 'msg' => lang('player_seting'));
    }

    /** 批量调整播放器排序：入参是 [{id:播放器 code, rank:新排序值}, …]（后台表格提交形态），先滤掉空值条目，再逐项写回 conf['vodplayer'][id]['sort']（intval）；不存在的 id 静默跳过（不报错、不新建）。整体 kv_set('vodplayer') + admin_audit_log('player_sort')。⚠ 空 $rows 走 message() 直接终止请求，不返回错误数组。@param array $rows 排序行列表 @return array {success:true, msg:string}（恒定 success=true，不含逐项结果） */
    public static function sort(array $rows): array
    {
        $conf = _SERVER('conf');
        empty($rows) && message(1, lang('data_is_empty'));
        $arrs = array_filter($rows, function($v) { return $v !== '' && $v !== null; });
        $arr = isset($conf['vodplayer']) && is_array($conf['vodplayer']) ? $conf['vodplayer'] : array();
        foreach ($arrs as $val) {
            $id = $val['id'];
            if (!isset($arr[$id])) continue;
            $arr[$id]['sort'] = intval($val['rank']);
        }
        $conf['vodplayer'] = $arr;
        kv_set('vodplayer', $conf['vodplayer']);
        admin_audit_log('player_sort', array('data' => $arrs));
        return array('success' => true, 'msg' => lang('update_successfully'));
    }

    /** 删除播放器条目：从 conf['vodplayer'] 里 unset 该 code 后整体 kv_set 回写并 admin_audit_log('player_delete')。键不存在时不报错、结果仍 success=true（幂等口径），因此调用方不能据此判断是否真删过。⚠ 只动配置不校验是否仍被栏目/模板引用——删掉在用播放器会导致前台取不到解析地址。@param string $from 播放器标识（vodplayer 的数组键） @return array {success:true, msg:string} */
    public static function delete(string $from): array
    {
        $conf = _SERVER('conf');
        $arr = isset($conf['vodplayer']) && is_array($conf['vodplayer']) ? $conf['vodplayer'] : array();
        if (isset($arr[$from])) unset($arr[$from]);
        $conf['vodplayer'] = $arr;
        kv_set('vodplayer', $conf['vodplayer']);
        admin_audit_log('player_delete', array('from' => $from));
        return array('success' => true, 'msg' => lang('delete_successfully'));
    }
}

