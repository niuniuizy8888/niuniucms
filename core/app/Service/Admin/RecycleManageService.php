<?php
namespace App\Service\Admin;

/**
 * 后台回收站服务：列表装配 / 恢复 / 清空（token + 审计）
 */
class RecycleManageService
{
    /**
     * 回收站列表页数据装配：admin_param_* 取 type/page，RecycleService::list()（R10 起列清单不含 data 大列）
     * + count()，逐行拼 type_name/create_date_fmt/title。
     * ⚠ title 来源已修：原实现直接读 $row['data']，而 list() 按 R10 根本不取该列 ⇒
     *   每行抛 `Undefined array key "data"` 且标题恒为空串。现改为对本页 id 调
     *   RecycleService::snapshotsByIds()（一次 IN 查询、只取 id+data），
     *   既显示得出标题，又不把大字段带回列表主查询（R10 的收益保留）。
     * 副作用：bull_token_set 发放 'admin_recycle' 场景指纹环，并把 safe_token 一并交给模板（写操作门禁配对）。
     * @param int $uid 当前管理员 uid（签名已声明）
     * @return array 模板变量集：input/arrlist/pagination/safe_token/header（签名声明 array）
     */
    public static function listPage(int $uid): array
    {
        $type = admin_param_text('type', '', 32);   // ⚠ 第二参是**默认值**、第三参才是长度上限；误写成 32 会让无 type 参数时按 '32' 过滤 ⇒ 首次进入恒空列表（审计修复）
        $page = max(1, admin_param_int('page', 1));
        $pagesize = 20;
        $arrlist = \App\Service\Content\RecycleService::list($type, $page, $pagesize);
        $total = \App\Service\Content\RecycleService::count($type);
        // ⚠ 标题来源修正：RecycleService::list() 按 R10 故意不取 data(text) 大字段，
        //   而旧代码在此直接读 $row['data'] ⇒ PHP 8 抛 `Undefined array key "data"`，
        //   且 $row['title'] 恒为空串（回收站列表看不出删的是什么）。
        //   现按本页 id 定点回取快照（一次 IN 查询，仍不把大字段带进列表主查询）。
        $ids = array();
        foreach ($arrlist as $row) { $ids[] = intval($row['id'] ?? 0); }
        $snaps = \App\Service\Content\RecycleService::snapshotsByIds($ids);
        // 装配展示字段
        foreach ($arrlist as &$row) {
            $row['type_name'] = ('art' === $row['type']) ? '文章' : (('vod' === $row['type']) ? '视频' : $row['type']);
            $row['create_date_fmt'] = date('Y-m-d H:i', $row['create_date']);
            $row['title'] = '';
            $data = isset($snaps[intval($row['id'])]) ? xn_json_decode($snaps[intval($row['id'])]) : null;
            if (is_array($data) && !empty($data['row'])) {
                $row['title'] = $data['row']['name'] ?? '';
            }
        }
        bull_token_set($uid, 'admin_recycle');
        return array(
            'input' => array('type' => $type, 'page' => $page),
            'arrlist' => $arrlist,
            // ⚠ 分页链接已修：原传字符串字面量 "url('recycle-list')" ⇒ 链接 href 全是字面文本、不可点；
            //   现走 url() 真值并带 type 筛选与 {page} 占位（ArtManageService 同款形态）。
            'pagination' => pagination(url('recycle-list', array('type' => $type, 'page' => '{page}'), TRUE), $total, $page, $pagesize),
            'safe_token' => bull_token_get($uid, 'admin_recycle'),
            'header' => array('title' => '回收站'),
        );
    }

    /**
     * 恢复单条回收记录：admin_bull_token_guard 校验场景令牌（fail-closed，不过即拒），
     * 转 RecycleService::restore()（快照回放原行）并写 admin_audit_log('recycle_restore')，
     * 最后 message() 输出响应并终止请求。
     * ⚠ 成功码已修：原实现恒 `message(0, $result['msg'])`，而底层成功是 code=1、失败是 code=-1
     *   （记录不存在/快照损坏/主键冲突）⇒ 失败也回 type=success，界面提示"已恢复"但数据没回来。
     *   现按底层 code 映射：1 → message(0) 成功；其余 → message(-1) 失败（msg 原样透出）。
     * @param int $uid 当前管理员 uid（签名已声明）
     * @return void 正常路径由 message() exit，不会返回
     */
    public static function restore(int $uid)
    {
        // 发布前检查修复（E-4）：admin_param_text 第二参为默认值——原误传 64
        $safe_token = admin_param_text('safe_token', '');
        admin_bull_token_guard($uid, 'admin_recycle', $safe_token);
        $id = admin_param_int('id');
        $result = \App\Service\Content\RecycleService::restore($id);
        admin_audit_log('recycle_restore', array('id' => $id, 'result' => $result));
        // 只有 code 严格等于 1 才算成功（勿写成 !$code 之类的反向判断，底层从不返回 0）
        $ok = is_array($result) && 1 === (int)($result['code'] ?? -1);
        message($ok ? 0 : -1, is_array($result) ? (string)($result['msg'] ?? '') : '');
    }

    /**
     * 物理删除回收记录：同 restore 的令牌门禁 + admin_audit_log('recycle_purge')，随后 message() 终止。
     * id>0 删单条；id<=0（缺参/0）走 RecycleService::purge() 的清空全表分支——对应后台"清空回收站"。
     * ⚠ 结果口径（原先恒报成功）：底层 purge() 返回的是 `(bool)rowCount`，而 QueryBuilder 在
     *   「没命中任何行」与「语句失败」两种情况下都给 0 ⇒ 返回值本身有歧义，故按语义分开处理：
     *   单条删（id>0）报 false 时按「记录不存在或删除失败」提示，宁保守；
     *   清空全表（id<=0）本就允许"回收站已经是空的"，恒报成功，避免把空回收站误报成故障。
     * @param int $uid 当前管理员 uid（签名已声明）
     * @return void
     */
    public static function purge(int $uid)
    {
        $safe_token = admin_param_text('safe_token', '');
        admin_bull_token_guard($uid, 'admin_recycle', $safe_token);
        $id = admin_param_int('id');
        $done = \App\Service\Content\RecycleService::purge($id);
        admin_audit_log('recycle_purge', array('id' => $id, 'result' => $done));
        if ($id > 0 && !$done) {
            message(-1, '回收记录不存在或删除失败');
        }
        message(0, lang('delete_successfully'));
    }
}
