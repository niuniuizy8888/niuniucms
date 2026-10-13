<?php
namespace App\Service\Collection;

class ApiBindService
{
    /**
     * 绑定分类
     * 
     * @param string $code 采集源编码
     * @param string $name 分类名称
     * @param int $localId 本地分类ID
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function bind(string $code, string $name, int $localId): array
    {
        $conf = _SERVER('conf');
        if (empty($code) || empty($name)) {
            return ['success' => false, 'msg' => '参数不完整'];
        }
        if (empty($localId)) {
            return ['success' => false, 'msg' => '绑定ID不能为空'];
        }
        // 发布前检查修复（A-3）：code 字符集白名单（ApiSourceService 写入侧同口径）——
        // 绑定键会进 kv 'bind' 数组键与后台属性位输出，纵深防键逃逸
        if (1 !== preg_match('#^[A-Za-z0-9_.-]{1,32}$#', $code)) {
            return ['success' => false, 'msg' => 'code invalid'];
        }
        
        $conf['bind'][$code][$name] = $localId; 
        kv_set('bind', $conf['bind']);
        
        admin_audit_log('api_cate_bind', array(
            'code' => $code, 
            'name' => $name, 
            'binstatus' => 'bind', 
            'local_id' => $localId
        ));
        
        return ['success' => true];
    }

    /**
     * 解绑分类
     * 
     * @param string $code 采集源编码
     * @param string $name 分类名称
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function unbind(string $code, string $name): array
    {
        $conf = _SERVER('conf');
        if (empty($code) || empty($name)) {
            return ['success' => false, 'msg' => '参数不完整'];
        }
        
        if (isset($conf['bind'][$code][$name])) {
            unset($conf['bind'][$code][$name]); 
            kv_set('bind', $conf['bind']);
        }
        
        admin_audit_log('api_cate_bind', array(
            'code' => $code, 
            'name' => $name, 
            'binstatus' => 'unbind', 
            'local_id' => 0
        ));
        
        return ['success' => true];
    }

    /**
     * 自动绑定所有同名分类
     * 
     * @param string $code 采集源编码
     * @param string $api API地址
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function autoBindAll(string $code, string $api): array
    {
        $conf = _SERVER('conf');
        
        if (empty($code) || empty($api)) {
            return ['success' => false, 'msg' => '参数不完整'];
        }
        
        // 新商店对接修复：取源分类统一走 ApiExecutionService::sourceClassList()
        // （缓存键 api_cls_ 前缀保留；真凶是商店**明文** URL 被 core_decrypt 判死 ⇒ class 恒空；
        // 另修掉"空结果被缓存 1 小时"）。
        $apiarr = \App\Service\Collection\ApiExecutionService::sourceClassList($code, $api);
        if (empty($apiarr)) {
            // fail-closed：取不到源分类时**绝不能**走到下面那句整条替换——旧实现会把 kv('bind') 写成
            // 空数组并回「操作成功」，实测把已有 44 条绑定静默清空（故障被伪装成成功，最难排查）。
            return ['success' => false, 'msg' => '未取到采集源分类（源站不可达或接口无 class 字段），已保留原有绑定'];
        }

        $catelist = cate_list_cache(); //本地分类
        $arrlista = arrlist_key_values($catelist, 'name', 'cid');
        
        $catelistarr = array();
        foreach ($apiarr as $key => $value) {
            $sitetype = $value['type_name'] ?? '';
            // 采集自动建类现按转义名入库，查本地分类时原名/转义名双查兼容存量
            $tname = htmlspecialchars((string)$sitetype, ENT_QUOTES, 'UTF-8');
            $match_cid = $arrlista[$sitetype] ?? $arrlista[$tname] ?? '';
            if (!empty($sitetype) && !empty($match_cid)) {
                $catelistarr[] = array(
                    'cid' => $match_cid,
                    'name' => $sitetype,
                );
            }
        }
        $catelistarr = arrlist_key_values($catelistarr, 'name', 'cid');

        if (empty($catelistarr)) {
            // 源有分类但本地一个同名都没有：同样不能把绑定清空（先「一键采集/创建分类」再来绑定）
            return ['success' => false, 'msg' => '源站 ' . count($apiarr) . ' 个分类在本地无同名分类，未做任何绑定（请先执行「一键采集」或「创建分类」）'];
        }

        if (isset($conf['bind'][$code])) {
            unset($conf['bind'][$code]);
        }
        $conf['bind'][$code] = $catelistarr;
        kv_set('bind', $conf['bind']);
        
        admin_audit_log('api_bind_auto_all', array('code' => $code));
        
        return ['success' => true, 'msg' => '自动绑定分类完成：绑定 ' . count($catelistarr) . ' 个（源站 ' . count($apiarr) . ' 个）'];
    }
}
