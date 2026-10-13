<?php
/**
 * @deprecated 兼容壳 — 全部函数转发到 \App\Service\System\AddonService
 * 新代码禁止调用，应直接使用 AddonService 类。
 *
 * 插件系统改造（R2-1）：官方商店 legacy 整族拆除（addons_OFFICIAL_URL 旧占位域名
 * 旧官方商店占位域名 example.com / addons_official_* / addons_siteid——siteid=md5(auth_key+IP) 明文 HTTP 外发踩
 * auth_key 红线）。该旧占位域名已并入本站域名（配置项 `cloud_host`），不再散写。主题列表入口 addons_list($type=2/3) 因 R3-2 主题注册表收敛到 TemplateService
 * 恒返回空数组；主题请走 TemplateService::templateList()/loadState()。
 */
function _include($srcfile)
{ return \App\Service\System\AddonService::includeFile($srcfile); }
/**
 * @deprecated 兼容壳：转发 AddonService::includeTemplateCallback($m)——模板 <template include> / 
 * {inc:} 编译期展开的 preg_replace_callback。四重安全收口（白名单前缀 template/、plugins/、
 * core/admin/html/ + 拒路径穿越 + 内容闸），任一闸触发即整段替换为空串（fail-closed）。
 * @param array $m 正则捕获组：$m[1]=include 路径、$m[2]=slot 原文
 * @return string 展开后的模板片段
 */
function _include_callback_1($m)
{ return \App\Service\System\AddonService::includeTemplateCallback($m); }
/**
 * @deprecated 兼容壳：转发 AddonService::init()——扫描 plugins 子目录 conf.json 填充插件注册表与
 * hook 文件清单（幂等：本进程已初始化则直接返回）。无返回值。
 * @return void
 */
/**
 * @deprecated 兼容壳：转发 AddonService::dependencies($dir, $type)——取 $dir 插件的**缺失依赖**清单。
 * 依赖未装/未启用/版本不足都算缺失；空数组 = 依赖满足。$type='theme' 分支随 R3-2 主题收敛恒返回空数组。
 * @param string $dir 插件目录名
 * @param string $type 'addons'（保留兼容）|'theme'（恒空）
 * @return array 依赖目录名 => 所需最低版本
 */
/**
 * @deprecated 兼容壳：转发 AddonService::byDependencies($dir, $type)——**反向依赖**：
 * 返回已启用且声明依赖 $dir 的插件清单（禁用它可能让别家坏）。'theme' 分支同 dependencies() 恒空。
 * @param string $dir 被依赖的插件目录名
 * @param string $type 'addons'|'theme'
 * @return array 依赖方目录名 => 其版本号
 */
/**
 * @deprecated 兼容壳：转发 AddonService::enable($dir)——启用插件：目录名/conf.json 校验 →
 * software_version 与 verifyAddonCode 语法闸前置 → 写 enable=1 → overwrite 基线存量补录 →
 * 清临时产物强制重编。任一闸不过即拒绝（fail-closed）。
 * @param string $dir 插件目录名（仅 [A-Za-z0-9_-]）
 * @return bool TRUE=成功；FALSE=失败（原因见 AddonService::$lastError）
 */
// 清空插件的临时目录
/**
 * @deprecated 兼容壳：转发 AddonService::disable($dir)——停用插件：写 conf.json enable=0 →
 * 同步内存标志 → clearTmpDir 强制重编（摘除 hook 内联）。
 * @param string $dir 插件目录名
 * @return bool TRUE=成功；FALSE=目录名非法/conf.json 不存在或写失败（原因见 AddonService::$lastError）
 */
/**
 * @deprecated 兼容壳：转发 AddonService::install($dir)——安装插件：依赖完整性 → software_version →
 * verifyAddonCode 三闸 → 写 installed=1/enable=1 → overwrite 基线落库 → install.php 经 _include
 * 编译管线执行；脚本抛 Throwable 则回滚安装标志（不留半安装态）。插件代码属受信代码（§二.8）。
 * @param string $dir 插件目录名
 * @return bool TRUE=成功；FALSE=失败（原因见 AddonService::$lastError）
 */
// copy from addons_install 修改
// 返回所有开启的插件
// 编译源文件，把插件合并到该文件，不需要递归，执行的过程中 include _include() 自动递归
function addons_compile_srcfile($srcfile)
{ return \App\Service\System\AddonService::compileSourceFile($srcfile); }
/* 在启用插件的 overwrite/ 中挑出**权重最高的一个**替换文件并返回其绝对路径；无命中则原样返回 $srcfile。
 * 权重取插件 conf.json 的 overwrites_rank（键=被覆盖文件的**基名**、值=整数权重，缺省 0），
 * 例："overwrites_rank": {"vod.php": 100}。同权重时后遍历到的插件胜出（比较用 >=）。
 * 仅对 overwrite 白名单前缀生效（AddonService::overwriteAllowed），越白名单一律不替换。
 * 契约与安全口径见 .skills/插件开发.md 与 CLAUDE.md「安全红线·插件代码＝受信代码」。
 * */
/**
 * @deprecated 兼容壳：转发 AddonService::compileSourceFileCallback($m)——编译管线扫描钩子标记时的
 * 内联回调（按启用插件 hook 目录与 hooks_rank 拼接内容；非法钩子名原样回吐标记文本）。
 * @param array $m 正则捕获组：$m[0]=标记原文、$m[1]=钩子名
 * @return string 内联拼接出的钩子内容（可为空串=无匹配文件）
 */
function addons_compile_srcfile_callback($m)
{ return \App\Service\System\AddonService::compileSourceFileCallback($m); }
// -------------------> 本地插件列表（type=1 插件；type=2/3 主题已收敛到 TemplateService）
// 安装，卸载，禁用，更新
// R3-1 运行期引导（index.php 在 SKIP_ROUTE 判定前调用；幂等）
