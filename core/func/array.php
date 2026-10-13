<?php
/**
 * 安全取数组键值（全库最常用的读值 helper）。
 * ⚠ isset 语义：键不存在 **或值为 NULL** 都返回 $default——区分不了「缺失」与「存了 null」。
 * @param mixed $arr 源数组（非数组传入 isset 静默走 default）
 * @param string|int $key 键名
 * @param mixed $default 缺省值（默认空串）
 * @return mixed 命中的值或 $default
 */
function array_value($arr, $key, $default = '')
{
    return isset($arr[$key]) ? $arr[$key] : $default;
}
/**
 * 剔除值为 empty() 的条目（''/0/'0'/null/false/空数组都会丢，键序保持）。
 * 常用于装配查询条件时剥掉未填项（如 TypeService 的 where 组装、sticky 的 cid 过滤）。
 * @param array $arr 输入数组（值拷贝处理，不改引用——必须使用返回值）
 * @return array 过滤后的数组
 */
function array_filter_empty($arr)
{
    foreach ($arr as $k => $v) {
        if (empty($v)) unset($arr[$k]);
    }
    return $arr;
}
/*
*/
function array_addslashes(&$var)
{
    if (is_array($var)) {
        foreach ($var as $k => &$v) {
            array_addslashes($v);
        }
    } else {
        $var = isset($var) ? $var : '';
        $var = addslashes($var);
    }
    return $var;
}
/**
 * 递归去转义 slashes（array_addslashes 的逆操作）：**引用形参就地修改**，同时返回同一 $var，
 * 两种用法皆可。非数组叶子直接 stripslashes。
 * @param mixed $var 数组或标量（必须传变量，不能传字面量——引用形参）
 * @return mixed 修改后的同一值
 */
function array_stripslashes(&$var)
{
    if (is_array($var)) {
        foreach ($var as $k => &$v) {
            array_stripslashes($v);
        }
    } else {
        $var = stripslashes($var);
    }
    return $var;
}
/**
 * 递归 HTML 转义：**引用形参就地修改**并返回。⚠ 手写 str_replace 仅处理 4 个字符
 * （& → &amp;、" → &quot;、< → &lt;、> → &gt;）——**不转义单引号**，与 htmlspecialchars(ENT_QUOTES) 集合不同；且对已含
 * `&amp;` 的值会再叠一层（&amp;amp;，双重转义历史事故同款形态），勿对 param() 结果调用。
 * @param mixed $var 数组或标量（引用形参，须传变量）
 * @return mixed 修改后的同一值
 */
function array_htmlspecialchars(&$var)
{
    if (is_array($var)) {
        foreach ($var as $k => &$v) {
            array_htmlspecialchars($v);
        }
    } else {
        $var = str_replace(array('&', '"', '<', '>'), array('&amp;', '&quot;', '&lt;', '&gt;'), $var);
    }
    return $var;
}
/**
 * 递归 trim 首尾空白：**引用形参就地修改**并返回（叶子对非字符串值 trim 会触发类型强转）。
 * @param mixed $var 数组或标量（引用形参，须传变量）
 * @return mixed 修改后的同一值
 */
function array_trim(&$var)
{
    if (is_array($var)) {
        foreach ($var as $k => &$v) {
            array_trim($v);
        }
    } else {
        $var = trim($var);
    }
    return $var;
}
/**
 * 按键做「值差异」比对（原注释：比较数组的值，如果不相同则保留，以第一个数组为准）：
 * 遍历 $arr1，凡 $arr2 里**存在同名键且值相等**的条目就从结果里 unset，剩下的是「相对 $arr2 有变化的键」。
 * 与内置 array_diff 的差别：array_diff 比的是值集合、不看键；本函数按键对齐，语义等同
 * 「把旧记录里已经一致的字段剔掉」⇒ 调用点正是这个用法：
 *  - SessionService 的会话写库短路（性能项 P-9）：payload 与库中记录逐键比，全等就不发 UPDATE；
 *  - UserManageService 的更新装配：payload 与旧用户记录比，只把真正变化的列写库。
 * ⚠ 比较用松散 ==（PHP 8 起字符串与数字的比较已收紧，但 0 与 ''、null 与 false 之类仍会判等而被剔除），
 *   键缺失的一侧算「不同」而保留。用在「少写一次 UPDATE」的场景是安全的收紧方向，
 *   但**别拿它做安全比对**（凭据/令牌比较一律 hash_equals 恒时，红线 §二.6）。
 * ⚠ 只一维：嵌套数组按 == 整块比，不会逐层比对。
 * @param array $arr1 基准数组（通常是「新值/payload」，其键决定输出结构）
 * @param array $arr2 对照数组（通常是「库里的旧值」）
 * @return array 剔除了与 $arr2 同键同值条目后的 $arr1（可能为空数组 = 无变化）
 */
function array_diff_value($arr1, $arr2)
{
    foreach ($arr1 as $k => $v) {
        if (isset($arr2[$k]) && $arr2[$k] == $v) unset($arr1[$k]);
    }
    return $arr1;
}
/*
	$data = array();
	$data[] = array('volume' => 67, 'edition' => 2);
	$data[] = array('volume' => 86, 'edition' => 1);
	$data[] = array('volume' => 85, 'edition' => 6);
	$data[] = array('volume' => 98, 'edition' => 2);
	$data[] = array('volume' => 86, 'edition' => 6);
	$data[] = array('volume' => 67, 'edition' => 7);
	arrlist_multisort($data, 'edition', TRUE);
*/
/**
 * 对二维数组（记录列表）按某一列排序（原注释：对多维数组排序；用法见上方示例注释块）。
 * 做法是先把 $col 列抽成 `$列值 => 原键` 的辅助数组，再 array_multisort 带动 $arrlist 一起重排。
 * 边界与坑：
 *  - **要求每一行都有该列**：body 里直接取 $arr[$col]，某行缺列会报 Undefined array key 并把 NULL 当排序值；
 *    若列只在部分行存在，抽出的辅助数组与 $arrlist 长度不一致，PHP 8 的 array_multisort 会抛
 *    ValueError（同一条隐患在 array_multisort_key() 里做了防护，本函数没有）；
 *  - 数字键会被 array_multisort 重排（原键序不保留），需要以某列值为键时用 array_multisort_key 的第四参；
 *  - 比较是 PHP 原生标量比较，日期串/数字串混排时注意形态（现有调用点：TypeService 按 cid、
 *    VideoQueryTrait 按 vid 保序、本文件 arrlist_cond_orderby 内部）。
 * @param array $arrlist 二维数组（每行是关联数组）
 * @param string $col 排序列名（必须存在于每一行）
 * @param bool $asc TRUE 升序（默认）/ FALSE 降序
 * @return array 排序后的列表（键序被 array_multisort 重排）
 */
function arrlist_multisort($arrlist, $col, $asc = TRUE)
{
    $colarr = array();
    foreach ($arrlist as $k => $arr) {
        $colarr[$k] = $arr[$col];
    }
    $asc = $asc ? SORT_ASC : SORT_DESC;
    array_multisort($colarr, $asc, $arrlist);
    return $arrlist;
}
/**
 * 在 PHP 内存里对二维数组做「筛选 + 排序 + 分页」三合一（原注释：对数组进行查找，排序，筛选，
 * 支持多种条件排序）。本站分类树/频道列表等小数据集走它，避免为一次过滤再发 SQL。
 *  - $cond 筛选条件：键 = 列名。值是**标量**时做松散等值比较（`!=` 即淘汰）；值是**数组**时按
 *    比较符下标写复合条件，支持 `>`、`<`、`>=`、`<=`、`==`、`LIKE`（LIKE 是 stripos 子串匹配，
 *    不分大小写、也无通配符语义）。任一行缺条件列 → 直接判不匹配（fail-closed，不会报错）。
 *  - $orderby 排序：**只取第一项**（key()/current()），值为 1 升序、其余降序，转交 arrlist_multisort
 *    ⇒ 多列排序不生效，写第二项会被静默忽略；排序列必须在全部行存在（见该函数说明）。
 *  - 分页在最后做，用 array_assoc_slice 保留原有键（关联数组不被重排）。
 * 空输入原样返回（可能是 NULL/空数组，调用方需自行判空）。
 * 调用点示例：CateService 的 `array('cup'=>$cid,'type'=>1,'category'=>0)` + `array('cid'=>-1)`，
 * TypeService 取子分类（pagesize 传 2000 当「全量」用）。
 * @param array $arrlist 待处理二维数组
 * @param array $cond 筛选条件（列名 => 标量 或 列名 => 比较符数组）
 * @param array $orderby 排序（列名 => 1 升序 / 0 或其他 降序；仅首项生效）
 * @param int $page 页码，1 起
 * @param int $pagesize 每页条数，默认 20（调用点常传 1000/2000 表示不分页）
 * @return array 过滤、排序并截页后的数组（保留原键）
 */
function arrlist_cond_orderby($arrlist, $cond = array(), $orderby = array(), $page = 1, $pagesize = 20)
{
    $resultarr = array();
    if (empty($arrlist)) return $arrlist;
    // 根据条件，筛选结果
    if ($cond) {
        foreach ($arrlist as $key => $val) {
            $ok = TRUE;
            foreach ($cond as $k => $v) {
                if (!isset($val[$k])) {
                    $ok = FALSE;
                    break;
                }
                if (!is_array($v)) {
                    if ($val[$k] != $v) {
                        $ok = FALSE;
                        break;
                    }
                } else {
                    foreach ($v as $k3 => $v3) {
                        if (
                            ($k3 == '>' && $val[$k] <= $v3) ||
                            ($k3 == '<' && $val[$k] >= $v3) ||
                            ($k3 == '>=' && $val[$k] < $v3) ||
                            ($k3 == '<=' && $val[$k] > $v3) ||
                            ($k3 == '==' && $val[$k] != $v3) ||
                            ($k3 == 'LIKE' && stripos($val[$k], $v3) === FALSE)
                        ) {
                            $ok = FALSE;
                            break 2;
                        }
                    }
                }
            }
            if ($ok) $resultarr[$key] = $val;
        }
    } else {
        $resultarr = $arrlist;
    }
    if ($orderby) {
        // php 7.2 deprecated each()——用 key()/current() 替代（2026-10-10 清理死注释）
        $k = key($orderby);
        $v = current($orderby);
        $resultarr = arrlist_multisort($resultarr, $k, $v == 1);
    }
    $start = ($page - 1) * $pagesize;
    $resultarr = array_assoc_slice($resultarr, $start, $pagesize);
    return $resultarr;
}
/**
 * 按「条数」截取数组的一段并**保持原有顺序与键**（原注释：取一维或二维数组指定数量的数据，并按之前排序）。
 * 两条路径：
 *  - 数组存在整数下标 0（$arrlist[0] 已设）⇒ 当成列表直接用 array_slice（返回的是重排下标的数组）；
 *  - 否则（纯关联数组）⇒ 先 array_keys 取键序，截出这段键，再回填成 `键 => 行`，
 *    **原键不丢**。arrlist_cond_orderby 的分页步就靠它保住分类的 cid 键。
 * 坑：混合键（既有 0 又有字符串键）会走第一条路从而丢掉关联语义；
 * $length 传 0 时 array_slice/array_keys 截的是「0 条」⇒ 返回空数组，
 * 本函数默认值是 0，所以调用方**必须显式传长度**（arrlist_cond_orderby 传的是 $pagesize）。
 * @param array $arrlist 待截取数组（一维或二维皆可，只截外层）
 * @param int $start 起始偏移（按当前位置数，不是页码；越界返回空数组）
 * @param int $length 截取条数，默认 0（见上：0 = 截空，务必显式传）
 * @return array 截取结果（关联输入保留原键；下标 0 存在的输入被 array_slice 重排下标）
 */
function array_assoc_slice($arrlist, $start, $length = 0)
{
    if (isset($arrlist[0])) return array_slice($arrlist, $start, $length);
    $keys = array_keys($arrlist);
    $keys2 = array_slice($keys, $start, $length);
    $retlist = array();
    foreach ($keys2 as $key) {
        $retlist[$key] = $arrlist[$key];
    }
    return $retlist;
}
/**
 * 从二维数组里抽出一个 `键 => 值` 的一维映射（原注释：从一个二维数组中取出一个 key=>value 格式的一维数组）。
 * 两条分支：
 *  - $key 非空（主用法）：新键 = `$pre` 拼接该行的 $key 列值，值 = 该行的 $value 列值；
 *    **$value 省略时值取外层下标 $k**（做「列值 => 序号」的反查表，如 cid => 名次）；
 *  - $key 为空：退化成「取单列成列表」，返回 `$return[] = 该行 $value 列`，不带键映射。
 * 与内置 array_column 的差别是 **$pre 前缀**（调用点用它区分同名列）与「值可取外层下标」；
 * 单列取名场景优先用本文件的 arrlist_values()（走 array_column，更快且不逐行取键）。
 * ⚠ 缺列会报 Undefined array key 并把 NULL 当键/值用；$key 列值若重复，后写的行覆盖先写的（无告警），
 *   做唯一映射前先确认该列唯一。现有调用点：CateService 的 cid→name、CategoryManageService 的
 *   name→cid（重名会被合并，调用方已按此处理）、UserManageService 的 gid→name。
 * @param array $arrlist 二维数组（内部强制 (array) 转换，非数组入参会先变单元素数组）
 * @param string|null $key 作新键的列名；空值时改走「取列成列表」分支
 * @param string|null $value 作值的列名；省略则值用外层下标
 * @param string $pre 新键的前缀，默认空串
 * @return array 一维映射（或 $key 为空时的单列列表）
 */
function arrlist_key_values($arrlist, $key, $value = NULL, $pre = '')
{
    $return = array();
    if ($key) {
        foreach ((array)$arrlist as $k => $arr) {
            $return[$pre . $arr[$key]] = $value ? $arr[$value] : $k;
        }
    } else {
        foreach ((array)$arrlist as $arr) {
            $return[] = $arr[$value];
        }
    }
    return $return;
}
/**
 * 从二维数组中取一列，成 values() 形态的一维数组（原注释：某一列 $key，$index_key 为数组索引或键的列）：
 * 直接转发内置 array_column($arrlist, $key, $index_key)。
 *  - $index_key 省略（默认 NULL）⇒ 返回**重排下标**的值列表（典型用途：取一批 vid/aid 准备 whereIn）；
 *  - $index_key 给了列名 ⇒ 返回 `该列值 => $key 列值` 的映射，重复键后者覆盖前者。
 * 空输入短路返回**空数组**（不是 NULL，调用方可直接 foreach/array_diff）。
 * 调用点（取证）：art_model 取 aid 列表、CateService 取 vid/aid 分桶，传的都是库表列名字符串。
 * @param array $arrlist 二维数组（行数组，每行含 $key/$index_key 列）
 * @param string|null $key 取值列名；传 NULL 时 array_column 返回整行（等于原数组按键重排）
 * @param string|int|null $index_key 作新键的列名，NULL = 用连续下标
 * @return array 一维数组（值列表或键值映射）
 */
function arrlist_values($arrlist, $key, $index_key = NULL)
{
    if (!$arrlist) return array();
    return array_column($arrlist, $key, $index_key);
}
// 从一个二维数组中对某一列求和
// 从一个二维数组中对某一列求最大值
// 从一个二维数组中对某一列求最大值
// 将 key 更换为某一列的值，在对多维数组排序后，数字key会丢失，需要此函数
// 保留指定的 key
/**
 * 按某一列的值对二维数组分桶（原注释：根据某一列的值进行 chunk）——即 groupBy：
 * 结果形如 `列值 => [该行, 该行, ...]`，同列值的行聚到一组，组内保持原顺序。
 * 典型用法是「先一次性取回全部行，再在 PHP 里按父级/分类归组」，用来替代循环内逐条查询
 * （性能红线 §三.2 的 whereIn + PHP 分桶套路；取证调用点：ApiImportService 按 type_pid 归组导入数据）。
 * 边界：
 *  - 空输入返回**空数组**（不是 NULL），调用方可直接 foreach 或 isset 判定；
 *  - 列名在任一行缺失会报 Undefined array key 并归到 NULL 键下（键变成空串），
 *    分桶列建议取自结构一致的库表结果集；
 *  - 列值本身不可用作数组键（数组、或无 __toString 的对象）时会触发非法偏移告警，结果不可依赖。
 * @param array $arrlist 二维数组（每行是关联数组）
 * @param string|int $key 分桶列名（取证：调用点传 'type_pid' 这类列名字符串）
 * @return array 列值 => 该组行数组
 */
function arrlist_chunk($arrlist, $key)
{
    $r = array();
    if (empty($arrlist)) return $r;
    foreach ($arrlist as &$arr) {
        !isset($r[$arr[$key]]) AND $r[$arr[$key]] = array();
        $r[$arr[$key]][] = $arr;
    }
    return $r;
}
/**
 * 二维数组按某一列的值排序（可保数字键重排后换键）。空输入返回空数组；
 * $colarr 全空（所有行都缺排序列）时原样返回。
 * ⚠ 隐患：仅**部分**行缺 $col 时 colarr 与 arrlist 长度不一致，PHP 8 的 array_multisort
 *   会抛 ValueError「Array sizes are inconsistent」——要求该列在全部行或在无行中存在
 *   （现有调用方传库表列如 create_date，整批行结构一致故安全）。
 * @param array $arrlist 二维数组
 * @param string $col 排序列名
 * @param bool $asc TRUE 升序 / FALSE 降序
 * @param string|null $key 非空则排序后用 array_change_key 以该列值为新键（应对数字键在排序中丢失）
 * @return array 排序（可选换键）后的数组
 */
function array_multisort_key($arrlist, $col, $asc = TRUE, $key = NULL)
{
    if (empty($arrlist)) return array();
    $colarr = array();
    foreach ($arrlist as $k => $v) {
        if (!isset($v[$col])) continue;
        $colarr[$k] = $v[$col];
    }
    if (empty($colarr)) return $arrlist;
    $asc = $asc ? SORT_ASC : SORT_DESC;
    array_multisort($colarr, $asc, $arrlist);
    unset($colarr);
    $key AND $arrlist = array_change_key($arrlist, $key);
    return $arrlist;
}
/**
 * 把二维数组的外层键换成「某一列的值」（原注释：更改二维数组 key）。
 * 设计动机见上方遗留注释「将 key 更换为某一列的值，在对多维数组排序后，数字 key 会丢失，需要此函数」
 * ——array_multisort 会重排数字下标，排完再按业务主键（cid/gid/vid 之类）重建索引，
 * 后续才能用 `$list[$cid]` 直接命中；array_multisort_key() 的第四参就是调它做这一步。
 * 行为与坑：
 *  - $arrlist 或 $key 为空都**原样返回**（不报错、也不改键），调用方别指望它做空值兜底；
 *  - 该列值重复时**后写的行覆盖先写的**（静默丢行，无告警），列必须唯一才可用；
 *  - 列缺失的行会因 Undefined array key 而落到空串键上，多行同时缺列会互相覆盖；
 *  - 与内置 array_column 的第三参相比只多了「整表重建」这一步，单列取值请用 arrlist_values()。
 * @param array $arrlist 二维数组
 * @param string|null $key 作新键的列名；留空 = 不做任何处理
 * @return array 以该列值为外层键的新数组（入参为空或未传列名时为原数组）
 */
function array_change_key($arrlist, $key = NULL)
{
    if (empty($arrlist) || empty($key)) return $arrlist;
    $arr = array();
    foreach ($arrlist as $k => $v) {
        $arr[$v[$key]] = $v;
    }
    return $arr;
}
// 二维数组分页，对排序的整个数组分页获取数据

// 倒叙 二维关联数组整理一维关联数组 col排序列 关联key=>value
// 移除二维数组中的重复的值，并返回结果数组。

// 合并二维数组 如重复 值以第一个数组值为准

/*
 * 对二维数组排序 两个数组必须有一个相同的键值
 * $array1 需要排序数组
 * $array2 按照该数组key排序
 * */
function array2_sort_key($array1, $array2, $key = '')
{
    if (empty($array1) || empty($array2)) return NULL;
    $arr = array();
    foreach ($array2 as $k => $v) {
        if (isset($v[$key]) && $v[$key] == $array1[$v[$key]][$key]) {
            $arr[$v[$key]] = $array1[$v[$key]];
        } else {
            $arr[] = $v;
        }
    }
    return $arr;
}
/**
 * 把行数组整理成「主键值 => 单字段更新数组」形态（专配 Repository/QueryBuilder::bigUpdate 的 $updates 参数）。
 * 行内缺任一列的条目直接跳过（不产生空更新，防误写）。
 * @param array $array 行数组（每行为关联数组）
 * @param string $key_column 作新键的列名（通常是主键/唯一列）
 * @param string $value_column 要更新的列名（值列）
 * @return array key_column 值 => [value_column => 值]
 */
function array_reindex_by($array, $key_column, $value_column) {
    $result = [];
    foreach ($array as $item) {
        if (isset($item[$key_column]) && isset($item[$value_column])) {
            $result[$item[$key_column]] = [$value_column => $item[$value_column]];
        }
    }
    return $result;
}

