<?php
namespace App\Service\Collection;

class ApiSourceService
{
    /**
     * 保存或更新采集源
     * 
     * @param string $code 采集源编码
     * @param string $api API地址
     * @param string $name 名称
     * @param string $play 播放器解析地址
     * @return array ['success' => bool, 'msg' => string]
     */
    public static function set(string $code, string $api, string $name, string $play): array
    {
        $conf = _SERVER('conf');

        // 上线前审计修复（H-A1）：code 字符集白名单（缓存键与 KV 键共用此值，
        // 历史表单仅 htmlspecialchars 未限字符集；缓存键前缀 api_cls_ 已在读侧隔离，此为写入侧纵深）。
        if ('' === $code || !preg_match('#^[A-Za-z0-9_.-]{1,32}$#', $code)) {
            return ['success' => false, 'msg' => '采集源编码仅允许字母/数字/下划线/点/短横线（1-32 位）'];
        }
        
        // 播放器解析地址 scheme 白名单校验
        if (!empty($play)) {
            $parsed_play = parse_url($play);
            $scheme_play = strtolower($parsed_play['scheme'] ?? '');
            if (!in_array($scheme_play, ['http', 'https'], true)) {
                return ['success' => false, 'msg' => '播放器解析地址必须为 http/https 协议'];
            }
        }
        
        $conf['api'][$code] = [
            'api' => $api,
            'code' => $code,
        ];
        
        if (isset($conf['vodplayer'])) {
            unset($conf['vodplayer'][$code]);
        }
        $conf['vodplayer'][$code] = [
            'status' => '1',
            'from' => $code,
            'show' => $name,
            'des' => $name,
            'target' => '_self',
            'ps' => '1',
            'parse' => $play,
            'sort' => '1000',
            'tip' => '无需安装任何插件',
        ];
        
        kv_set('api', $conf['api'] ?? array());
        kv_set('vodplayer', $conf['vodplayer'] ?? array());
        
        admin_audit_log('api_source_set', ['code' => $code]);
        
        return ['success' => true, 'msg' => '设置采集源、播放器成功'];
    }

    /**
     * 删除采集源
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
        
        kv_set('api', $conf['api'] ?? array());
        kv_set('bind', $conf['bind'] ?? array());
        
        admin_audit_log('api_source_del', ['code' => $code]);
        
        return ['success' => true, 'msg' => '移除采集源成功'];
    }
}
