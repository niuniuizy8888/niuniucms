<?php
/**
 * 表单控件辅助函数（form_*）
 * 从 core/func/misc.php 拆分而来，由 core/core.php 在 misc.php 后加载
 */
function form_radio_yes_no($name, $checked = 0)
{
    $checked = intval($checked);
    return form_radio($name, array(1 => lang('yes'), 0 => lang('no')), $checked);
}
/**
 * 生成一组 Bootstrap 5 单选控件（form-check 结构，横向 flex 排布），返回可直接 echo 的 HTML 串。
 * 转义在函数内部完成：$name、每个选项的键（作为 input value）与值（作为 label 文案）都过
 * htmlspecialchars(ENT_QUOTES,'UTF-8')，调用方**不要再包一层**（会出 &amp;amp; 双重转义，见 §二.4）；
 * 但返回的是 HTML，进模板须按原样输出（后台模板按裸 echo 处理）。
 *
 * @param string $name 表单字段名（同组共用）
 * @param array $arr 选项集：键=input value、值=显示文案；空数组回落 array(lang('no'), lang('yes'))（即 0/1）
 * @param mixed $checked 选中项的键，与 $k 做 == 松散比较（0/'0'/false 会互认，取值请统一用字符串或整数）
 * @param bool|false $disabled 非 FALSE 即整组加 disabled 属性
 * @return string HTML 片段
 */
function form_radio($name, $arr, $checked = 0, $disabled = FALSE)
{
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    empty($arr) && $arr = array(lang('no'), lang('yes'));
    // Bootstrap 5 标记（修复：原 BS4 custom-control 类在 BS5 已删除 → 原生未样式化控件）
    $s = '<div class="d-flex flex-wrap gap-2">';
    foreach ((array)$arr as $k => $v) {
        $e = $name . '-' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $add = $k == $checked ? ' checked="checked"' : '';
        $add .= FALSE !== $disabled ? ' disabled="disabled"' : '';
        $s .= '<div class="form-check"><input class="form-check-input" type="radio" id="' . $e . '" name="' . $name . '" value="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '"' . $add . ' /><label class="form-check-label" for="' . $e . '">' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</label></div>';
    }
    $s .= '</div>';
    return $s;
}
/** 生成单个 Bootstrap 5 复选框（勾选时提交 $val，未勾选则完全不提交——需要「显式 0」请配 form_hidden 打底）。$name/$txt/$val 内部各过 htmlspecialchars(ENT_QUOTES,'UTF-8')，调用方勿再转义。id 取 "$name-$val"，同名字不同值可共存、同值重复渲染会撞 id。@param string $name 字段名 @param mixed $checked 真值即渲染 checked @param string $txt 标签文案 @param string|int $val 提交值 @return string HTML 片段 */
function form_checkbox($name, $checked = 0, $txt = '', $val = 1)
{
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $txt = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
    $val = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    $add = $checked ? ' checked="checked"' : '';
    $s = '<div class="form-check"><input class="form-check-input" type="checkbox" id="' . $name . '-' . $val . '" name="' . $name . '" value="' . $val . '" ' . $add . ' /><label class="form-check-label" for="' . $name . '-' . $val . '">' . $txt . '</label></div>';
    return $s;
}
// form_multi_checkbox('flag', array('k1'=>'v1','k2'=>'v2'), array('k1','k2'))
// name  选项内容  被选中选项(选项内容的键名)
/** 生成 Bootstrap 5 下拉框（内部拼 form_options() 出 <option>），返回 <select>…</select> 串。$arr 为空直接返回空字符串（不渲染空 select）。转义口径同 form_options()：$name 与每个选项键/值都过 htmlspecialchars(ENT_QUOTES,'UTF-8')。@param string $name 字段名 @param array $arr 选项集（键=value、值=文案） @param mixed $checked 选中项键（== 松散比较） @param bool|string $id TRUE=用 $name 作 id，字符串=自定义 id，FALSE=不输出 id @param bool|false $disabled 非 FALSE 即禁用 @param bool|false $multiple 非 FALSE 加 multiple（多选，此时不追加 w-auto） @return string HTML 片段 */
function form_select($name, $arr, $checked = 0, $id = TRUE, $disabled = FALSE, $multiple = FALSE)
{
    $name_e = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    if (empty($arr)) return '';
    $idadd = TRUE === $id ? 'id="' . $name_e . '"' : ($id ? 'id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '');
    $add = FALSE !== $disabled ? ' disabled="disabled"' : '';
    $multiple = FALSE != $multiple ? ' multiple' : '';
    $auto = FALSE == $multiple ? ' w-auto' : '';
    $s = '<select name="' . $name_e . '" class="form-control form-select" ' . $multiple . ' ' . $idadd . ' ' . $add . "> \r\n";
    $s .= form_options($arr, $checked);
    $s .= "</select> \r\n";
    return $s;
}
/** 生成 <option> 列表（不含 <select> 外壳，供 form_select() 复用或模板自拼），每项尾随 "\r\n"。键/值各过 htmlspecialchars(ENT_QUOTES,'UTF-8')；$checked 用 == 松散比较选中项。@param array $arr 选项集（键=value、值=文案） @param mixed $checked 选中项键 @return string HTML 片段 */
function form_options($arr, $checked = 0)
{
    $s = '';
    foreach ((array)$arr as $k => $v) {
        $k_e = htmlspecialchars($k, ENT_QUOTES, 'UTF-8');
        $v_e = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $add = $k == $checked ? ' selected="selected"' : '';
        $s .= '<option value="' . $k_e . '"' . $add . '>' . $v_e . "</option> \r\n";
    }
    return $s;
}
/** 生成 Bootstrap 5 单行文本框（type=text）。$name/$value/$holdplacer 以及 $width 都过 htmlspecialchars(ENT_QUOTES,'UTF-8')——**value 已在此转义，调用方传入原文即可**。@param string $name 字段名（同时用作 id） @param mixed $value 输入值（非字符串会先强转再转义） @param bool|string|int $width FALSE=不设宽度；数字按 px；字符串原样作 CSS 宽度（如 '100%'） @param string $holdplacer placeholder 文案 @return string HTML 片段 */
function form_text($name, $value, $width = FALSE, $holdplacer = '')
{
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $holdplacer = htmlspecialchars($holdplacer, ENT_QUOTES, 'UTF-8');
    $style = '';
    if (FALSE !== $width) {
        is_numeric($width) and $width .= 'px';
        $style = ' style="width: ' . htmlspecialchars($width, ENT_QUOTES, 'UTF-8') . '"';
    }
    $s = '<input type="text" name="' . $name . '" id="' . $name . '" placeholder="' . $holdplacer . '" value="' . $value . '" class="form-control"' . $style . ' />';
    return $s;
}
/** 生成隐藏域（type=hidden）。$name/$value 过 htmlspecialchars(ENT_QUOTES,'UTF-8')，因此适合直接塞 safe_token 一类含特殊字符的令牌值；后台表单的 CSRF 场景令牌由 bull_token_set() 生成后用本函数落到页面。@param string $name 字段名（同时用作 id） @param mixed $value 值 @return string HTML 片段 */
function form_hidden($name, $value)
{
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $s = '<input type="hidden" name="' . $name . '" id="' . $name . '" value="' . $value . '" />';
    return $s;
}
/** 生成 Bootstrap 5 多行文本域：<textarea name id placeholder class form-control [style]>$value</textarea>。$name/$value/$holdplacer/$width/$height 都过 htmlspecialchars(ENT_QUOTES,'UTF-8')；宽高为 FALSE 时不写该条 style，数字自动补 px。@param string $name 字段名（同时用作 id） @param mixed $value 初始内容（放在标签体内，非字符串强转后转义） @param string $holdplacer placeholder 文案 @param bool|string|int $width 宽度口径同 form_text() @param bool|string|int $height 高度口径同上（数字按 px） @return string HTML 片段 */
function form_textarea($name, $value, $holdplacer = '', $width = FALSE, $height = FALSE)
{
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $holdplacer = htmlspecialchars($holdplacer, ENT_QUOTES, 'UTF-8');
    $style = '';
    $styles = array();
    if (FALSE !== $width) {
        is_numeric($width) and $width .= 'px';
        $styles[] = 'width: ' . htmlspecialchars($width, ENT_QUOTES, 'UTF-8');
    }
    if (FALSE !== $height) {
        is_numeric($height) and $height .= 'px';
        $styles[] = 'height: ' . htmlspecialchars($height, ENT_QUOTES, 'UTF-8');
    }
    if ($styles) {
        $style = ' style="' . implode('; ', $styles) . '"';
    }
    $s = '<textarea name="' . $name . '" id="' . $name . '" placeholder="' . $holdplacer . '" class="form-control"' . $style . '>' . $value . '</textarea>';
    return $s;
}
/** 生成密码框（type=password，固定 autocomplete="new-password" 以免浏览器回填旧密码——改密/建号表单的既定口径）。$name/$value 过 htmlspecialchars(ENT_QUOTES,'UTF-8')；$value 正常应传空串，把已有密码回显到表单本身就属泄露。@param string $name 字段名（同时用作 id） @param mixed $value 初始值（建议恒空） @param bool|string|int $width FALSE=不设宽度；数字按 px；字符串原样 @return string HTML 片段 */
function form_password($name, $value, $width = FALSE)
{
    $name_e = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $value_e = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $style = '';
    if (FALSE !== $width) {
        is_numeric($width) and $width .= 'px';
        $style = " style=\"width: " . htmlspecialchars($width, ENT_QUOTES, 'UTF-8') . "\"";
    }
    $s = "<input type=\"password\" name=\"$name_e\" id=\"$name_e\" class=\"form-control\" value=\"$value_e\" autocomplete=\"new-password\" $style />";
    return $s;
}
// form_time('start', '18:00') 为空则当前时间
// form_date('start', '') 为空则当前日期
