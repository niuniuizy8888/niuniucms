#!/usr/bin/env bash
# NiuNiuCMS 发布前「主机杀毒误报」预检
#
# 背景：cPanel/宝塔等主机常装 maldet(LMD)、ClamAV、ImunifyAV/Imunify360，它们按字节特征和启发式打分
# 隔离/清空 PHP 文件（实例：b2a6014 之前 core/model/view.php 注释里的示例代码命中 LMD
# php.generic.globals 特征被隔离，导致编译缓存残缺、全站 500）。本脚本在打包前把这类高风险写法拦下来。
#
# 用法：bash tools/av_precheck.sh [目录]      # 默认检查仓库根目录（本脚本的上一级）
# 退出码：0=无命中；1=有命中（逐条打印 文件:行号:规则）；2=参数错误
# 放行：确属必要的行，在该行末尾加注释  av-precheck: allow  并在提交说明里写明理由。
# 依赖：bash + GNU grep。tools/ 目录不进发行包（build.sh 打包时删除）。
set -uo pipefail

ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
[[ -d "$ROOT" ]] || { echo "目录不存在: $ROOT" >&2; exit 2; }
cd "$ROOT" || exit 2

EXCL=(--exclude-dir=.git --exclude-dir=graphify-out --exclude-dir=runtime --exclude-dir=node_modules
      --exclude-dir=vendor --exclude-dir=tools)
CODE=(--include='*.php' --include='*.phtml' --include='*.php[0-9]' --include='*.inc' --include='*.html'
      --include='*.htm' --include='*.tpl' --include='*.js')
PHP=(--include='*.php' --include='*.phtml' --include='*.php[0-9]' --include='*.inc')

HITFILE="$(mktemp)"; trap 'rm -f "$HITFILE"' EXIT
report() {   # $1=规则名，stdin=grep -n 输出（在管道子 shell 里跑，命中数经临时文件汇总）
    local rule="$1" line
    while IFS= read -r line; do
        [[ "$line" == *"av-precheck: allow"* ]] && continue
        printf '%s  [%s]\n' "${line:0:220}" "$rule" | tee -a "$HITFILE"
    done
}
drop_comments() { grep -vE '^[^:]+:[0-9]+:\s*(\*|//|#|/\*)'; }

# 1. PHP 开标签紧跟命令/代码执行函数（LMD php.generic.globals 等字节特征；注释/文档里写示例同样会中）
grep -rnIE "${EXCL[@]}" '<\?(php|=)?\s*(system|exec|eval|passthru|shell_exec|assert|popen|proc_open)\s*\(' . | report php-open-exec

# 2. 解码/解压嵌套或接代码执行（经典混淆链）
grep -rnIE "${EXCL[@]}" "${PHP[@]}" '(eval|assert|include|require|create_function)[^;]{0,40}(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|hex2bin|convert_uudecode)' . | drop_comments | report exec-decode-chain
grep -rnIE "${EXCL[@]}" "${PHP[@]}" '(gzinflate|gzuncompress|gzdecode|str_rot13|base64_decode|convert_uudecode)\s*\(\s*@?\s*(base64_decode|gzinflate|gzuncompress|str_rot13|hex2bin|strrev|convert_uudecode)\s*\(' . | drop_comments | report nested-decode

# 3. 同一文件里同时出现 base64_decode 与解压/rot13（启发式按文件计分）
while IFS= read -r f; do
    if grep -qE '(gzinflate|gzuncompress|gzdecode|str_rot13)\s*\(' "$f"; then
        echo "$f:0:base64_decode + gzinflate/gzuncompress/gzdecode/str_rot13 同文件" | report b64-plus-decompress
    fi
done < <(grep -rlIE "${EXCL[@]}" "${PHP[@]}" 'base64_decode\s*\(' .)

# 4. 动态代码执行
grep -rnIE "${EXCL[@]}" "${PHP[@]}" '(^|[^A-Za-z0-9_$>:\\])(eval|assert|create_function|pcntl_exec)\s*\(' . \
    | drop_comments | grep -vE ':\s*(public |private |protected |static )*function\s' | report dynamic-code

# 5. 进程执行（->exec / ::exec / 方法定义不算）
grep -rnIE "${EXCL[@]}" "${PHP[@]}" '(^|[^A-Za-z0-9_$>:\\])(system|exec|passthru|shell_exec|popen|proc_open)\s*\(' . \
    | drop_comments | grep -vE '(function\s+(system|exec|passthru|popen)\s*\()' | report process-exec
grep -rnIE "${EXCL[@]}" "${PHP[@]}" '=\s*`[^`]*\$' . | drop_comments | report backtick-exec

# 6. preg_replace /e 修饰符
grep -rnIE "${EXCL[@]}" "${PHP[@]}" "preg_replace\s*\(\s*['\"]([^A-Za-z0-9\\\\[:space:]]).*\1[a-zA-Z]*e[a-zA-Z]*['\"]\s*," . | drop_comments | report preg-e

# 7. 超全局直接当函数调用 / 直接喂给执行类函数
grep -rnIE "${EXCL[@]}" "${PHP[@]}" '\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\s*\[[^]]*\]\s*\(' . | report superglobal-call
grep -rnIE "${EXCL[@]}" "${PHP[@]}" '(eval|assert|system|exec|passthru|shell_exec|popen|proc_open|create_function|call_user_func(_array)?|unserialize|include|require)(_once)?\s*\(?\s*@?\$_(GET|POST|REQUEST|COOKIE)' . | report superglobal-exec

# 8. 超长 Base64 样串（≥200 字符，混淆载荷特征）
grep -rnoIE "${EXCL[@]}" "${PHP[@]}" "['\"][A-Za-z0-9+/]{200,}={0,2}['\"]" . | report long-base64

# 9. 注释/文案里的敏感词（部分扫描器按关键词加分）
grep -rniIE "${EXCL[@]}" "${CODE[@]}" 'web ?shell|backdoor|一句话(木马|后门)' . | report sensitive-word

HITS=$(wc -l < "$HITFILE")
if (( HITS > 0 )); then
    echo "av_precheck: ${HITS} 处命中（目录 ${ROOT}）。修改写法，或确属必要时在该行加 'av-precheck: allow' 并说明理由。" >&2
    exit 1
fi
echo "av_precheck: OK（目录 ${ROOT}，无命中）"
exit 0
