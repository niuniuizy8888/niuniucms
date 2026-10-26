<?php
namespace App\Service\Collection;

class ApiSourceService
{
    /**
     * 采集源地址解析：与 ApiExecutionService::fetchJson 同口径——先 core_decrypt（商店旧密文），
     * 解不出则认明文。最终地址必须是带主机名的 http/https，否则返回 ''（file:/gopher:/javascript: 等一律拒）。
     * 内网/元数据地址由出站层 outbound_url_safe 在实际请求时再拦一道，这里只管协议形态。
     * @param string $api 表单/配置里的采集源地址（密文或明文）
     * @return string 可用的明文 http(s) 基址；非法返回 ''
     */
    public static function resolveUrl(string $api): string
    {
        $api = trim($api);
        if ('' === $api) return '';
        $url = function_exists('core_decrypt') ? (string)core_decrypt($api) : '';
        '' === $url and $url = $api;
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) return '';
        if (!in_array(strtolower((string)($p['scheme'] ?? '')), array('http', 'https'), true)) return '';
        return $url;
    }

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
        if ('' === self::resolveUrl($api)) {
            return ['success' => false, 'msg' => '采集源地址必须为 http/https 协议'];
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
