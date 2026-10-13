<?php
/**
 * Param 函数
 * 从 misc.php 拆分
 */

// ============================================================================
// [PARAM] 参数获取函数
// ============================================================================

/**
 * 全局主输入面读取入口：从 `$_REQUEST` 取一个键并做「类型归一 + HTML 转义」。
 *
 * ⚠ 转义口径（红线 §二.4，历史上真出过 `&amp;amp;` 双重转义事故）：
 *  - **默认已经 htmlspecialchars(ENT_QUOTES)**，取原始值必须显式传第三参 FALSE
 *    （如 `param('play_url', '', FALSE)`），拿到值后**不要再转一次**；
 *  - 类型契约由 $defval 的**类型**决定，实际归一在 param_force()：字符串→转义串、数字→intval、
 *    数组→按数组口径逐元素处理；
 *  - $addslashes 只是旧兼容位，**不承担注入防护**：SQL 一律走 QueryBuilder / 参数绑定（§二.3）。
 * 键缺失，或「$key 是 0/'0' 且值为空串/null」时短路返回：$defval 是数组 → 空 array；否则原样返回 $defval。
 * 这里的 `0 == $key` 是松散比较，PHP 8 起字符串与 0 不再相等，实际只对 0 与 '0' 生效
 * （写作 `param(0)`/`param(1)` 这类位置参数，即本站的短 URL 形态）。
 * ⚠ 与中间件的关系（技术债 #6）：XssFilterMiddleware 只清洗 `$_GET`/`$_POST`，而本函数读
 * `$_REQUEST`（core/core.php 引导时已构建完毕），所以**该中间件对本函数取到的值不生效**，
 * 别指望它兜底；后台写操作请继续用 admin_param_int/text/ids 一族（§二.6）。
 * @param string|int $key 请求键名或位置参数序号
 * @param mixed $defval 缺省值，其**类型**充当输出类型契约（string / int / array 三形态）
 * @param bool $htmlspecialchars TRUE（默认）= 字符串值做 ENT_QUOTES 转义；FALSE = 取原值
 * @param bool $addslashes TRUE = 先 addslashes（历史兼容位，勿当注入防护）
 * @return mixed 归一/转义后的值，类型随 $defval；键缺失时为 $defval（数组坍缩为空 array）
 */
function param($key, $defval = '', $htmlspecialchars = TRUE, $addslashes = FALSE)
{
    if (!isset($_REQUEST[$key]) || (0 == $key && ($_REQUEST[$key] === '' || $_REQUEST[$key] === null))) {
        if (is_array($defval)) {
            return array();
        } else {
            return $defval;
        }
    }
    $val = $_REQUEST[$key];
    $val = param_force($val, $defval, $htmlspecialchars, $addslashes);
    return $val;
}

/**
 * 安全获取「单词类」参数（原注释口径）：param($key) 取到的字符串再过 xn_safe_word()，
 * 结果**只剩 [A-Za-z0-9_]、并被截到 $len 长**。
 * 适用面是 action 名、场景键、开关标识这类 ASCII 标识符——中文、空格、标点、实体残片全部被剥掉，
 * 所以**不要**用它取标题/正文/URL（内容会被清空或截烂）。
 * 转义口径（红线 §二.4）：内层 param() 默认已 htmlspecialchars 一次，这里紧接着剥非单词字符，
 * 因此正常标识符不会被二次转义污染；若真要原始值请绕开本函数直接用 `param($k, '', FALSE)`。
 * @param string|int $key 请求键名（透传给 param()）
 * @param int $len 结果最大长度，默认 32（按字节截断，substr 非 mb，但结果已只含 ASCII 故无影响）
 * @return string 清洗后的标识符串；键缺失时为 param() 的缺省值（空串）清洗结果，即空串
 */
function param_word($key, $len = 32)
{
    $s = param($key);
    $s = xn_safe_word($s, $len);
    return $s;
}


/**
 * 字符串清洗为「单词字符」（原注释：安全过滤字符串，仅仅保留 a-zA-Z0-9_）：
 * 先用 `preg_replace('#\W+#','')` 剥掉所有非单词字符，再 substr 截到 $len 长。
 * 正则**未开 Unicode 修饰符**，按字节判定 ⇒ 中文/emoji/带重音字母等高位字节一律算非单词字符被剥光，
 * 这正是「只留 ASCII 标识符」的效果；截断因此也是字节级的，对清洗后的 ASCII 无副作用。
 * ⚠ 它是**白名单剔除**、不是转义：产出可以安全拼进标识符位置，但别当 XSS 出口防线
 *   （模板 `{$var}` 裸 echo 的口径下仍需 xn_h()，红线 §二.4）。
 * @param mixed $s 待清洗值，正则按字符串处理（取证：param_word() 透传 param() 的结果，恒字符串；
 *                  调用方直接传非字符串标量会被强转，传数组则触发 preg_replace 弃用并返回 NULL）
 * @param int $len 结果最大长度（字节数）
 * @return string 清洗并截断后的串（全非单词字符入参 ⇒ 空串；正则失败时为 NULL）
 */
function xn_safe_word($s, $len)
{
    // 审查修复轮 C5：数组入参不再走「强转 Array + 正则弃用」路径，直接 fail-closed 空串；
    // 正则失败（返回 NULL）也归空串——旧实现 NULL 会滑过调用方的空值判断（如
    // `'' === $alias` 在 saveRoutes 里），把 NULL 当有效别名写进配置。
    if (is_array($s)) return '';
    $s = preg_replace('#\W+#', '', (string)$s);
    if (NULL === $s) return '';
    $s = substr($s, 0, $len);
    return $s;
}

/**
 * param() 的类型归一 + 转义内核（输入侧防线第一环，§二.4）：
 * 输出形态由 $defval 的类型决定——
 * · $defval 为字符串：值走 htmlspecialchars(ENT_QUOTES)（$htmlspecialchars=FALSE 可关），可选再 addslashes；
 * · $defval 为整型等数字：值 intval；
 * · $defval 为数组：取其 [0] 作为元素类型口径，$val 须同为数组（逐元素处理，非数组返回空 array）。
 * ⚠ 转义发生在此处（入口即转义的历史口径）：取原始值要显式传 $htmlspecialchars=FALSE，且结果勿再转一次（双重转义事故）。
 * @param mixed $val 原始输入值（可标量或数组）
 * @param mixed $defval 默认值（其类型充当输出类型契约）
 * @param bool $htmlspecialchars TRUE=字符串值做 ENT_QUOTES 转义
 * @param bool $addslashes TRUE=先 addslashes（旧转义兼容位，SQL 侧不该依赖——走参数绑定）
 * @return mixed 归一/转义后的值；类型随 $defval（string|int|array）
 */
function param_force($val, $defval, $htmlspecialchars = TRUE, $addslashes = FALSE)
{
    if (is_array($defval)) {
        $defval = empty($defval) ? '' : $defval[0]; // 数组的第一个元素，如果没有则为空字符串
        if (is_array($val)) {
            foreach ($val as &$v) {
                if (is_array($v)) {
                    $v = $defval;
                } else {
                    if (is_string($defval)) {
                        $addslashes && $v = addslashes(isset($v) ? $v : '');
                        $htmlspecialchars and $v = htmlspecialchars($v, ENT_QUOTES);
                    } else {
                        $v = intval($v);
                    }
                }
            }
        } else {
            return array();
        }
    } else {
        if (is_array($val)) {
            $val = $defval;
        } else {
            if (is_string($defval)) {
                $addslashes && $val = addslashes(isset($val) ? $val : '');
                $htmlspecialchars and $val = htmlspecialchars($val, ENT_QUOTES);
            } else {
                $val = intval($val);
            }
        }
    }
    return $val;
}

/**
 * 取语言包文案（全库 i18n 读入口）：命中 $_SERVER('lang') 内存包则做 {占位符} 替换；
 * 缺失键转 LanguageService::get（当前语言包 → zh_cn 回退 → 占位符），永不为空。
 * 注：en_us 是桩包（28 键），未命中键会经 zh_cn 回退。
 * @param string $key 语言键
 * @param array $arr 占位符替换表（{'{name}'} => 值）
 * @return string 文案（含替换后的值；调用方按输出上下文自行转义）
 */
function lang($key, $arr = array())
{
    $lang = _SERVER('lang');
    if (!isset($lang[$key])) {
        // 缺失键走 LanguageService：当前语言包 → zh_cn 回退 → 占位符
        return \App\Service\System\LanguageService::get((string)$key, '', $arr);
    }
    $s = $lang[$key];
    if (!empty($arr)) {
        foreach ($arr as $k => $v) {
            $s = str_replace('{' . $k . '}', $v, $s);
        }
    }
    return $s;
}

/**
 * 数组型批量取参（多维表单）：$_REQUEST[$key] 交给 bull_param_force 逐键过滤。
 * 键缺失（或 0 参空值）时：$defval 为数组返回空 array，否则原样返回 $defval。
 * @param string|int $key 请求键
 * @param mixed $defval 缺省值（数组/标量）
 * @param array $filter 「按字符串处理」的键白名单——列内键做转义，未列出的键一律 intval（fail-safe 默认数字）
 * @param bool $htmlspecialchars 字符串键是否 htmlspecialchars(ENT_QUOTES)
 * @param bool $addslashes 字符串键是否额外 addslashes
 * @return mixed 过滤后的数组（键保留）；键不存在时返回 $defval（数组缺省坍缩为空 array）
 */
/**
 * bull_param 的过滤内核：遍历 $val 的键——嵌套数组递归 bull_mulit_array_safe，
 * 标量按 $filter 口径交 bull_safe（键在 $filter 内=字符串转义形态，否则 intval）。
 * ⚠ 前置 empty($val) 一律坍缩为空 array（含本应是 0/'0' 的标量）；
 *   $val 若为**非数组**标量（bull_param 未强制 array 形态时会到达），foreach 触发 PHP warning 后原样返回。
 * @param mixed $val 待过滤值（预期数组；引用语义经 &$v 就地改写后整体返回）
 * @param array $filter 按字符串处理的键白名单（见 bull_safe_defval）
 * @param bool $htmlspecialchars 透传给 bull_safe/bull_mulit_array_safe
 * @param bool $addslashes 透传
 * @return mixed 过滤后的数组（失败/空输入为空 array）
 */
/**
 * 多维数组安全过滤（原注释：遍历多维数组安全过滤，$filter 一维数组中能找到的一律按字符处理）：
 * 逐键判定——键出现在 $filter 白名单里的按字符串处理（可选 addslashes + htmlspecialchars），
 * **没出现的键一律 intval**（fail-safe：默认收紧成整数，见 bull_safe_defval/bull_safe），
 * 结果累积写进第二个参数 $arr 并整体返回。
 *
 * ⚠ 已知实现缺陷（只记录、未修改）：递归分支 `bull_mulit_array_safe($value, $arr[$key], ...)`
 * **丢弃了返回值**，嵌套层的过滤结果不会被并回 $arr，还会因读未初始化键报 Undefined array key。
 * 实际效果 = **只保留当前层的标量键值**：二维表单在 bull_param_force() 里被调用时传的是
 * `array()`，故二层标量能收进来，**三层及更深的结构会静默丢数据**。
 * 需要真多维请按调用点逐个改造，别指望本函数递归生效。
 * @param mixed $array 待过滤数组（非数组时不处理，直接返回 $arr）
 * @param array $arr 累加器（过滤结果写在这里再返回；调用方一般传空 array）
 * @param array $filter 按字符串处理的键白名单；不在名单内的键被 intval
 * @param bool $htmlspecialchars 白名单键是否 htmlspecialchars(ENT_QUOTES)
 * @param bool $addslashes 白名单键是否额外 addslashes（旧兼容位，非注入防护）
 * @return array 过滤后的当前层键值对（嵌套层丢失，见上）
 */
function bull_mulit_array_safe($array, $arr, $filter, $htmlspecialchars, $addslashes)
{
    if (is_array($array)) {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                bull_mulit_array_safe($value, $arr[$key], $filter, $htmlspecialchars, $addslashes);
            } else {
                $defval = bull_safe_defval($key, $filter);
                $arr[$key] = bull_safe($value, $defval, $htmlspecialchars, $addslashes);
            }
        }
    }
    return $arr;
}

/**
 * 判定某个表单键该按哪种类型过滤（原注释：返回 1 则按照字符串处理）：
 * 键出现在 $filter 白名单里 → 返回 1（字符串口径，走转义/addslashes），否则返回 0（数字口径，走 intval）。
 * $filter 不是数组时一律 0 ⇒ **默认收紧为整数**，属 fail-safe：忘记把新字段列进白名单，
 * 后果是该字段的非数字输入被 intval 成 0（数据丢失）而不是原样入库。
 * in_array 走的是松散比较（未传严格标志），PHP 8 起字符串与 0 不再相等，
 * 正常表单键名场景等价于按键名匹配，但别往 $filter 里塞奇怪标量当名单。
 * @param string|int $key 表单键名（取证：来自 bull_param_force/bull_mulit_array_safe 遍历的数组键）
 * @param array $filter 按字符串处理的键白名单（非数组按空名单处理）
 * @return int 1 = 按字符串处理；0 = 按整数处理
 */
function bull_safe_defval($key, $filter)
{
    $defval = 0;
    if (is_array($filter)) {
        // 限定的 key值 按照字符串处理
        $defval = in_array($key, $filter) ? 1 : 0;
    }
    return $defval;
}

/**
 * 单个值的类型化安全处理（原注释「参数安全处理」），是 bull_param 一族的最小执行体：
 *  - $defval == 1（字符串口径）：NULL 归一成空串，按需先 addslashes 再 htmlspecialchars(ENT_QUOTES)；
 *  - 否则（$defval 为 0 或任何其他值）：直接 intval ⇒ **未知类型一律收紧成整数**（fail-safe）。
 * 口径与 param_force() 一致，转义发生在入口（红线 §二.4）：字符串分支的产物**已经转义过一次**，
 * 输出/入库都别再补转义（双重转义事故）；要原始值就传 $htmlspecialchars=FALSE。
 * addslashes 只是历史兼容位，不承担 SQL 注入防护（SQL 走 QueryBuilder/参数绑定，§二.3）。
 * ⚠ 字符串分支没有显式 (string) 转换：传入数组会让 htmlspecialchars 收到非字符串而在 PHP 8.5 报弃用，
 *   调用方须保证标量。
 * @param mixed $val 待处理值（标量）
 * @param int $defval 类型开关：1 = 按字符串处理，其余 = 按整数处理（由 bull_safe_defval 得出）
 * @param bool $htmlspecialchars 字符串分支是否做 ENT_QUOTES 转义（默认应传 TRUE）
 * @param bool $addslashes 字符串分支是否额外 addslashes
 * @return string|int 字符串口径返回转义（/反斜杠加固）后的串，整数口径返回 intval 结果
 */
function bull_safe($val, $defval, $htmlspecialchars, $addslashes)
{
    // 处理字符串
    if (1 == $defval) {
        $val = isset($val) ? $val : '';
        $addslashes && $val = addslashes($val);
        $htmlspecialchars and $val = htmlspecialchars($val, ENT_QUOTES);
    } else {
        $val = intval($val);
    }
    return $val;
}

/**
 * 表单多维数组的「数字字段」校验器（原注释：专门处理表单多维数组安全过滤，哪些表单限定数字；
 * 原用法示例 `bull_mulit_array_int(array(), array('id','fid'))`）：
 * 遍历数组，键出现在 $filter 名单里而值**非数值**时，直接 message(1, lang('type_error'))
 * 渲染提示页并终止请求 ⇒ **fail-closed 校验**，不返回布尔、也不改写数据，调用方拿不到「通过」的信号，
 * 走到下一行就说明全部合规。
 *  - 与 bull_mulit_array_safe 的分工：那边负责「把值转成目标类型」，这边只负责「类型不对就拦下来」；
 *  - 嵌套数组会递归下去检查（递归返回值被丢弃也无妨，本函数不产出数据）；
 *  - $array 为空（含 0、空串、空数组、null）时**直接 return 什么也不校验**，
 *    调用方别把「空输入」当通过。
 * @param array $array 待校验表单数组（多维亦可）
 * @param array $filter 限定必须是数字的键名单
 * @return void 无返回值（空输入返回 NULL；校验不过由 message() 终止脚本）
 */
function bull_mulit_array_int($array = array(), $filter = array())
{
    if (empty($array)) return;
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            bull_mulit_array_int($value, $filter);
        } else {
            if (in_array($key, $filter) && !is_numeric($value)) message(1, lang('type_error'));
        }
    }
}

