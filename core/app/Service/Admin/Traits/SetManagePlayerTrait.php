<?php
namespace App\Service\Admin\Traits;

use App\Service\Admin\PlayerConfigService;

/**
 * 播放器配置（playerAdd/playerPage/savePlayer）（从 SetManageService 拆分，Trait 组合）
 */
trait SetManagePlayerTrait
{
    /**
     * 后台新增播放器：verifyToken（场景 admin_set_player_add，失败即 message 终止）→ 取参（admin_param_*
     * 带长度钳制）→ 转 PlayerConfigService::add()（写 conf['vodplayer'] 并 kv 持久化；空 player_code/
     * name 在其内部 message 拦截）。成功时记 player_add 审计。
     * @param int $uid 管理员 ID
     * @return array code/message（成功走 jump 跳转提示，失败仅回消息；非布尔裁定，调用方看 code）
     */
    public static function playerAdd(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_player_add');
        $player_code = admin_param_text('player_code', '', 50);
        $result = PlayerConfigService::add(
            $player_code,
            admin_param_text('player_name', '', 100),
            admin_param_text('player_url', '', 1024),
            admin_param_int('player_rank', 0)
        );
        $result['success'] and admin_audit_log('player_add', array('from' => $player_code));
        return array(
            'code' => $result['success'] ? 0 : 1,
            'message' => $result['success'] ? jump($result['msg'], url('set-player', array(), TRUE), 3) : $result['msg'],
        );
    }

    /**
     * 播放器配置页数据装配：直接取 conf['vodplayer'] 映射列表，签发 add/save 两个场景的
     * safe_token（admin_set_player_add / admin_set_player，供页内两类写操作分别校验）。
     * @param array $conf 站点配置（含 vodplayer 表）
     * @param int $uid 管理员 ID
     * @return array header/arrlist/safe_token/safe_token_save
     */
    public static function playerPage(array $conf, int $uid): array
    {
        $add_token = bull_token_set($uid, 'admin_set_player_add');
        $save_token = bull_token_set($uid, 'admin_set_player');
        return array(
            'header' => array(
                'title' => lang('player_set'),
            ),
            'arrlist' => $conf['vodplayer'],
            'safe_token' => $add_token,
            'safe_token_save' => $save_token,
        );
    }

    /**
     * 播放器批量保存（排序/删除二合一）：verifyToken（场景 admin_set_player）后按 type 分流——
     * type==2 走 PlayerConfigService::sort(_POST('data')) 重排，否则按 from 键删除该播放器。
     * 两分支均先记 player_modify 审计再执行；Service 内部失败以 message 终止，故返回恒 code=0。
     * @param int $uid 管理员 ID
     * @return array code/message
     */
    public static function savePlayer(int $uid): array
    {
        self::verifyToken($uid, 'admin_set_player');
        $type = admin_param_int('type');
        admin_audit_log('player_modify', array('type' => $type));
        if (2 == $type) {
            $result = PlayerConfigService::sort(_POST('data'));
            return array('code' => 0, 'message' => $result['msg']);
        }
        $result = PlayerConfigService::delete(admin_param_text('from', '', 50));
        return array('code' => 0, 'message' => $result['msg']);
    }
}
