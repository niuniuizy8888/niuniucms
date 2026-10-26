<?php
namespace App\Service\Collection;

class ApiArtService
{
    /**
     * 设置文章采集源
     * 
     * @param string $code 采集源编码
     * @param string $api API地址
     * @param string $name 名称
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function set(string $code, string $api, string $name): array
    {
        $conf = _SERVER('conf');

        // 上线前审计修复（H-A1）：code 字符集白名单（与 ApiSourceService::set 同口径；
        // 缓存键前缀 api_cls_ 已在读侧隔离，此为写入侧纵深）。
        if ('' === $code || !preg_match('#^[A-Za-z0-9_.-]{1,32}$#', $code)) {
            return ['success' => false, 'msg' => '采集源编码仅允许字母/数字/下划线/点/短横线（1-32 位）'];
        }
        if ('' === ApiSourceService::resolveUrl($api)) {
            return ['success' => false, 'msg' => '采集源地址必须为 http/https 协议'];
        }

        $conf['api'][$code] = [
            'api' => $api,
            'code' => $code,
        ];
        
        kv_set('api', $conf['api']);
        
        admin_audit_log('api_set_art', ['code' => $code]);
        
        return ['success' => true, 'msg' => '设置采集源成功'];
    }

    /**
     * 删除文章采集源
     * 
     * @param string $code 采集源编码
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function delete(string $code): array
    {
        $conf = _SERVER('conf');
        
        if (isset($conf['api'][$code])) {
            unset($conf['api'][$code]);
        }
        if (isset($conf['bind'][$code])) {
            unset($conf['bind'][$code]);
        }
        
        kv_set('api', $conf['api']);
        kv_set('bind', $conf['bind']);
        
        admin_audit_log('api_artdel', ['code' => $code]);
        
        return ['success' => true, 'msg' => '移除采集源成功'];
    }

    /**
     * 文章自动绑定所有同名分类
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
        
        // 新商店对接修复：与 ApiBindService::autoBindAll 同口径（明文直通 + 空结果不缓存），
        // 并 fail-closed —— 取不到源分类绝不整条替换 kv('bind')（旧实现会静默清空绑定）。
        $apiarr = \App\Service\Collection\ApiExecutionService::sourceClassList($code, $api);
        if (empty($apiarr)) {
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
            return ['success' => false, 'msg' => '源站 ' . count($apiarr) . ' 个分类在本地无同名分类，未做任何绑定（请先执行「一键采集」或「创建分类」）'];
        }

        if (isset($conf['bind'][$code])) {
            unset($conf['bind'][$code]);
        }
        $conf['bind'][$code] = $catelistarr;
        kv_set('bind', $conf['bind']);
        
        admin_audit_log('api_art_auto', array('code' => $code));
        
        return ['success' => true, 'msg' => '绑定已有分类成功：绑定 ' . count($catelistarr) . ' 个（源站 ' . count($apiarr) . ' 个）'];
    }
}