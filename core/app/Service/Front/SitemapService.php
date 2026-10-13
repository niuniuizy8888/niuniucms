<?php
namespace App\Service\Front;

class SitemapService
{
    /**
     * 文章索引表（art_aid）总行数——sitemap 分页数计算用。
     * @return int 计数（QueryBuilder::count 语义：查询失败按 0）
     */
    public static function artCount(): int
    {
        return \App\Service\Content\ArtService::indexCount();
    }

    /**
     * sitemap 响应装配（route/sitemap.php 唯一调用点，整页缓存 1800s、Content-Type: text/plain）。
     * $action='list'：输出视频分片 sitemap-N + 文章分片 sitemap-art-N 索引清单
     * （文章计数包 try/catch——表缺失静默跳过；注意分片页分支的同名计数**未**包保护，见批次汇报）。
     * 否则按分片页取行拼 URL：param 页码钳制到合法最大页（VULN-H04 深分页资源耗尽修复，注释在案）；
     * sitemap_tag/sitemap_play=1 时追加标签页/播放页 URL。伪静态档位 >1 时站点基址去尾斜杠。
     * @param string $action 路由首段（param(1)，默认转义形态；'list'/ 'art...' / 'N'）
     * @param array $conf 站点配置（sitemap_num/url_rewrite_on/sitemap_tag/sitemap_play）
     * @return array{content_type:'text/plain', body:string} CRLF 分隔的 URL 清单
     */
    public static function build($action, $conf)
    {
        $http = http_url_path();
        $pagesize = max(1, intval($conf['sitemap_num']));
        1 < $conf['url_rewrite_on'] and $http = rtrim($http, '/');

        if ('list' == $action) {
            $count = \App\Service\Content\VideoService::indexCount();
            $total = (int)ceil($count / $pagesize);
            $urls = array();
            for ($i = 0; $i < $total; $i++) {
                $urls[] = $http . url_sitemap('sitemap-' . $i);
            }
            // 文章分片（若存在文章内容）
            try {
                $artCount = self::artCount();
                $artTotal = (int)ceil($artCount / $pagesize);
                for ($i = 0; $i < $artTotal; $i++) {
                    $urls[] = $http . url_sitemap('sitemap-art-' . $i);
                }
            } catch (\Throwable $e) {
                // 表缺失/无文章时静默跳过
            }
            return array(
                'content_type' => 'text/plain',
                'body' => implode("\r\n", $urls),
            );
        }

        // 上线前安全审计修复（VULN-H04）：sitemap 深分页钳制 + 去掉主动抬升内存上限。
        // 原实现 `$page = intval(param(...)) + 1` 无上界：未认证请求可请求任意深页，每页 1000 行
        // 再展开为 tag/play 多条 URL（单响应可达数百 KB–数 MB），叠加 ini_set('memory_limit','256M')
        // 放大内存占用 ⇒ 资源耗尽。合法最大页码由内容总量推出，超出即返回空片。
        $maxPage = max(1, (int)ceil(max(1, \App\Service\Content\VideoService::indexCount()) / $pagesize) + 1);
        $maxArtPage = max(1, (int)ceil(max(1, self::artCount()) / $pagesize) + 1);

        // 分片页码：视频 sitemap-N（param1=N）；文章 sitemap-art-N（param1=art、param2=N）
        if (str_starts_with($action, 'art')) {
            $page = min(max(1, intval(param(2, 0)) + 1), $maxArtPage);
            $artlist = \App\Service\Content\ArtService::sitemapRows($page, $pagesize);
            $urls = array();
            foreach ((array)$artlist as $_art) {
                if (!isset($_art['url'])) {
                    continue;
                }
                $urls[] = $http . $_art['url'];
            }
            return array(
                'content_type' => 'text/plain',
                'body' => implode("\r\n", $urls),
            );
        }

        $page = min(max(1, intval(param(1, 0)) + 1), $maxPage);

        $videolist = \App\Service\Content\VideoService::sitemapRows(
            $page,
            $pagesize,
            1 == intval($conf['sitemap_tag']),
            1 == intval($conf['sitemap_play'])
        );
        $urls = array();
        foreach ($videolist as $_video) {
            $urls[] = $http . $_video['url'];
            if ($conf['sitemap_tag'] == 1 && isset($_video['tag_url'])) {
                foreach ($_video['tag_url'] as $_tag) {
                    $urls[] = $http . $_tag['url'];
                }
            }
            if ($conf['sitemap_play'] == 1 && isset($_video['play_urls'])) {
                foreach ($_video['play_urls'] as $_playUrl) {
                    $urls[] = $http . $_playUrl;
                }
            }
        }

        return array(
            'content_type' => 'text/plain',
            'body' => implode("\r\n", $urls),
        );
    }
}
