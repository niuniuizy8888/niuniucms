/*!
 * app.js — 牛牛CMS 前端 JavaScript（公共库与站点脚本合并为单文件）
 * 仅保留实际使用的函数，移除冗余代码
 * 合并日期:
 */

var xn = {};
var body = $('body');

/********************* 工具函数 ************************/

xn.intval = function (s) {
    var i = parseInt(s);
    return isNaN(i) ? 0 : i;
};

xn.htmlspecialchars = function (s) {
    return s.replace(/</g, "&lt;").replace(/>/g, "&gt;");
};

xn.strtolower = function (s) {
    return (s + '').toLowerCase();
};

xn.empty = function (s) {
    if (s == '0') return true;
    if (!s) return true;
    if (s.constructor === Object) return Object.keys(s).length == 0;
    if (s.constructor === Array) return s.length == 0;
    return false;
};

xn.is_number = function (obj) {
    return Object.prototype.toString.apply(obj) == '[object Number]' || /^\d+$/.test(obj);
};

xn.str_replace = function (s, d, str) {
    return str.replace(new RegExp(s, 'g'), d);
};

xn.substr = function (str, start, len) {
    if (!str) return '';
    var length = str.length;
    if (start < 0) start = length + start;
    if (!len) return str.substring(start);
    if (len > 0) return str.substring(start, start + len);
    return str.substring(start, length + len);
};

xn.strrpos = function (str, s) {
    return str.lastIndexOf(s);
};

xn.explode = function (sep, s) {
    return s.split(sep);
};

xn.in_array = function (v, arr) {
    return $.inArray(v, arr) != -1;
};

xn.json_decode = function (s) {
    if (!s) return null;
    try {
        if (s.match(/^<!DOCTYPE/i)) return null;
        return JSON.parse(s);
    } catch (e) {
        return null;
    }
};

xn.json_encode = function (o) {
    return JSON.stringify(o);
};

xn.urlencode = function (s) {
    s = encodeURIComponent(s);
    s = s.replace(/_/g, "%5f").replace(/\-/g, "%2d").replace(/\./g, "%2e").replace(/\%/g, "_");
    return s;
};

/********************* URL 生成 ************************/

xn.url = function (u, extra, url_access) {
    var on = window.url_rewrite_on || 0;
    url_access = url_access || window.url_access || false;
    var path = '', query = '';
    if (-1 != u.indexOf('/')) {
        path = xn.substr(u, 0, u.lastIndexOf('/') + 1);
        query = xn.substr(u, u.lastIndexOf('/') + 1);
    } else {
        query = u;
    }
    var r = '';
    if (!on) r = path + '?' + query + '.html';
    else if (1 == on) r = path + query + '.html';
    else if (2 == on || 3 == on) {
        var url_path = window.url_path || '/';
        r = url_path + xn.str_replace('-', '/', query);
        if (2 == on) r = r + '.html';
    }
    var arr = query.split('-');
    var url_filter = window.url_filter || ['operate', 'attach', 'read', 'category', 'list', 'my', 'forum', 'thread'];
    if ((true === url_access && false === xn.in_array(arr[0], url_filter)) || 3 === url_access) {
        var str = '';
        for (var key in arr) str += '&' + key + '=' + arr[key];
        // ：补前导 / 改为绝对路径，避免 AJAX/链接从 /yg2twb.php/index/xxx
        // 页面发送时被浏览器解析为相对路径 /yg2twb.php/index/yg2twb.php?... 而导致 404
        r = '/' + (window.admin_entry || 'index.php') + '?' + str.replace('&','');
    }
    if(2 === url_access && 2 > on) r = '../' + r;
    if (extra) {
        var args = '';
        for (var key in extra) args += '&' + key + '=' + extra[key];
        args = args.replace('&','');
        r += (r.indexOf('?') == -1 ? '?' : '&') + args;
    }
    return r;
};

/********************* AJAX 请求 ************************/

$.xpost = function (url, postdata, callback, progress_callback) {
    if (typeof postdata === 'function') { callback = postdata; postdata = null; }
    $.ajax({
        type: 'POST', url: url, data: postdata, dataType: 'text', timeout: 6000000,
        progress: function (e) {
            if (e.lengthComputable && progress_callback) progress_callback(e.loaded / e.total * 100);
        },
        success: function (r) {
            if (!r) return callback(-1, 'Server Response Empty!');
            var s = xn.json_decode(r);
            if (!s || s.code === undefined) return callback(-1, 'Server Response Not JSON：' + r);
            // 第三参透传后端完整响应（含 jump_script）：调用方需要处理「成功后的服务端跳转」时用，
            // 老调用方只取前两参，向后兼容。jump_script 由 core/func/misc.php::message() 白名单生成。
            return callback(s.code, s.message, s);
        },
        error: function (xhr, type) {
            if (type != 'abort' && type != 'error' || xhr.status == 403)
                return callback(-1000, "xhr.responseText:" + xhr.responseText + ', type:' + type);
            return callback(-1001, "xhr.responseText:" + xhr.responseText + ', type:' + type);
        }
    });
};

/********************* 弹窗 ************************/

$.alert = function (body, timeout, options) {
    /* 统一弹窗：自动判定类型 + 自动关闭 + 兼容旧链式 $.alert(msg).delay(1000).location() */
    var type = 'info', title = '提示';
    if (typeof body === 'string') {
        if (body.indexOf('成功') !== -1 || /^(ok|success)/i.test(body)) { type = 'success'; title = '操作成功'; }
        else if (body.indexOf('失败') !== -1 || body.indexOf('error') !== -1) { type = 'danger'; title = '操作失败'; }
    }
    var m = $.modal(type, title, String(body == null ? '' : body), { confirmText: '关闭' });
    /* 自动关闭：成功 1.2s，其他 2.5s；显式 timeout 优先 */
    var auto = (typeof timeout == 'number' && timeout >= 0) ? timeout * 1000 : (type === 'success' ? 1200 : 2500);
    setTimeout(function () { if (m && m.close) m.close(); }, auto);
    /* 返回伪链式对象，兼容旧代码 $.alert(msg).delay(ms).location(href) */
    var chain = {
        _delay: 0,
        close: function () { if (m && m.close) m.close(); },
        delay: function (ms) { chain._delay = ms || 0; return chain; },
        location: function (href) {
            setTimeout(function () {
                if (!href) window.location.reload();
                else window.location = href;
            }, chain._delay || 0);
            return chain;
        }
    };
    return chain;
};

/********************* jQuery 插件 ************************/

$.fn.button = function (status) {
    return this.each(function () {
        var jthis = $(this);
        if (status == 'loading') {
            /* 保留原始 HTML（含 SVG 图标）以便 reset 恢复 */
            if (!jthis.data('default-html')) jthis.data('default-html', jthis.html());
            var lt = jthis.attr('loading-text') || jthis.data('loading-text') || jthis.attr('data-loading-text') || '提交中...';
            jthis.prop('disabled', true).addClass('disabled');
            jthis.html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:btnSpin 0.6s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg><span style="margin-left:6px">' + lt + '</span>');
        } else if (status == 'disabled') {
            jthis.prop('disabled', true).addClass('disabled');
        } else if (status == 'enable' || status == 'reset') {
            jthis.prop('disabled', false).removeClass('disabled');
            if (status == 'reset' && jthis.data('default-html')) jthis.html(jthis.data('default-html'));
        } else {
            /* 字符串参数：设置文本（保留图标场景由调用方自行处理） */
            jthis.text(status);
        }
    });
};

$.fn.location = function (href) {
    var jthis = this;
    jthis.queue(function (next) {
        href ? window.location = href : window.location.reload();
        next();
    });
};

$.fn.serializeObject = function () {
    var self = this, json = {}, push_counters = {},
        patterns = {
            validate: /^[a-zA-Z][a-zA-Z0-9_]*(?:\[(?:\d*|[a-zA-Z0-9_]+)\])*$/,
            key: /[a-zA-Z0-9_]+|(?=\[\])/g,
            push: /^$/, fixed: /^\d+$/, named: /^[a-zA-Z0-9_]+$/
        };
    this.build = function (base, key, value) { base[key] = value; return base; };
    this.push_counter = function (key) {
        if (push_counters[key] === undefined) push_counters[key] = 0;
        return push_counters[key]++;
    };
    $.each($(this).serializeArray(), function () {
        if (!patterns.validate.test(this.name)) return;
        var k, keys = this.name.match(patterns.key), merge = this.value, reverse_key = this.name;
        while ((k = keys.pop()) !== undefined) {
            reverse_key = reverse_key.replace(new RegExp("\\[" + k + "\\]$"), '');
            if (k.match(patterns.push)) merge = self.build([], self.push_counter(reverse_key), merge);
            else if (k.match(patterns.fixed)) merge = self.build([], k, merge);
            else if (k.match(patterns.named)) merge = self.build({}, k, merge);
        }
        json = $.extend(true, json, merge);
    });
    return json;
};

$.fn.reset = function () {
    var jform = $(this);
    /* 兼容 <button id="submit"> 与 <input type="submit"> 两种提交按钮 */
    jform.find('#submit, button[type=submit], input[type=submit]').button('reset');
};

/* 字段错误提示（后端返回 code=字段名 时调用）：聚焦字段 + 醒目错误态（is-invalid + 内联错误文案）+ toast/modal
   ⚠️ 修复：不能占用 $.fn.alert 名字——Bootstrap 5 自带同名 Alert 插件（$.fn.alert = jQueryInterface），
   任何脚本加载顺序变化（如 bootstrap 晚于 app.js 加载）都会覆盖实现，运行时抛 "No method named ..."（实测复现）。
   独立命名 fieldAlert 与 Bootstrap 零冲突；$.fn.alert 保留为兼容别名。 */
$.fn.fieldAlert = function (message) {
    return this.each(function () {
        var jthis = $(this);
        var el = jthis[0];
        try { el && el.focus(); } catch (e) {}
        /* 字段错误态：Bootstrap is-invalid 红框 + 内联错误文案，保证 toast/弹窗不可见时仍有明确提示 */
        if (el) {
            jthis.addClass('is-invalid');
            var msg = String(message == null ? '' : message);
            var wrap = jthis.closest('.col-12, .form-group, .mb-3, .mb-4, .form-floating');
            var tip = wrap && wrap.children('.invalid-feedback').first();
            if (tip && tip.length) {
                tip.text(msg).show();
            } else {
                var tipHtml = '<div class="invalid-feedback" style="display:block;">' + msg.replace(/</g, '&lt;') + '</div>';
                if (wrap && wrap.length) { wrap.append(tipHtml); }
                else { jthis.after(tipHtml); }
            }
        }
        if (window.showToast) window.showToast(message, 'error');
        else $.modal('danger', '字段错误', String(message), { confirmText: '关闭' });
    });
};
/* 兼容别名：仅在未被 Bootstrap 覆盖时生效（新页面请一律走 fieldAlert） */
$.fn.alert = $.fn.fieldAlert;

/* Bootstrap 5 兼容：补上 Bootstrap 4 的 .button() API（BS5 已移除） */
if (typeof $.fn.button === 'undefined') {
    $.fn.button = function (action) {
        return this.each(function () {
            var btn = $(this);
            if (action === 'loading') {
                btn.data('_orig_text', btn.text() || btn.val());
                var loadingText = btn.attr('data-loading-text') || btn.data('_orig_text') + '...';
                btn.prop('disabled', true);
                if (btn.is('input')) btn.val(loadingText); else btn.text(loadingText);
            } else if (action === 'reset') {
                btn.prop('disabled', false);
                var orig = btn.data('_orig_text');
                if (orig) { if (btn.is('input')) btn.val(orig); else btn.text(orig); }
            }
            // 'string' action (set text) — Bootstrap 4 直接设文本
            else if (typeof action === 'string') {
                if (btn.is('input')) btn.val(action); else btn.text(action);
            }
        });
    };
}

/********************* 事件绑定 ************************/

body.on('keyup', 'form', function (e) {
    var jthis = $(this);
    if ((e.ctrlKey && (e.which == 13 || e.which == 10)) || (e.altKey && e.which == 83)) {
        jthis.trigger('submit');
        return false;
    }
});

var jkeyword = $('input[name="keyword"]');
body.on('click', '#search', function () {
    var jthis = $(this);
    var jkw = jthis.closest('.input-group').find('input[name="keyword"]').first();
    if (!jkw.length) jkw = $('input[name="keyword"]').first();
    var keyword = jkw.val();
    var endpoint = 'vod-search';
    if (typeof admin_route != 'undefined') {
        if (admin_route == 'art') endpoint = 'art-search';
        if (admin_route == 'vod') endpoint = 'vod-search';
    }
    try {
        var k = (typeof admin_route != 'undefined' && admin_route) ? ('admin.' + admin_route + '.keyword') : 'admin.keyword';
        if (typeof $.pdata == 'function') $.pdata(k, keyword);
    } catch (e) {}
    if (!keyword) return;
    window.location = xn.url(endpoint, {keyword: xn.urlencode(keyword)}, true);
});

window.onerror = function (msg, url, line) {
    if (!window.debug) return;
    alert("error: " + msg + "\r\n line: " + line + "\r\n url: " + url);
    return false;
};

/********************* 模态对话框系统 ************************/

/**
 * 显示模态对话框 — 遵循 UI 设计规范 v2
 * @param {object} opts 配置项
 * @param {string} opts.type 类型: success|info|warning|danger
 * @param {string} opts.title 标题
 * @param {string} opts.message 消息内容
 * @param {function} opts.onConfirm 确定回调
 * @param {function} opts.onCancel 取消回调
 * @returns {object} {close: function}
 */
function showAlert(opts) {
    opts = opts || {};
    var type = opts.type || 'info';
    var title = opts.title || '';
    var message = opts.message || '';

    /* 内联注入模态样式（一次性）：部分页面（前台登录/改密等）未加载 app.css，
       此前 .nn-modal-* 无样式导致弹窗异常显示（修复） */
    if (!document.getElementById('nn-modal-style')) {
        var style = document.createElement('style');
        style.id = 'nn-modal-style';
        style.textContent =
            '.nn-modal-overlay{position:fixed;inset:0;z-index:10800;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);animation:nnFadeIn .18s ease-out;}' +
            '.nn-modal-box{width:100%;max-width:420px;background:var(--nn-surface,#fff);border:1px solid var(--nn-border,#e2e8f0);border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,0.25);padding:28px 28px 24px;animation:nnPopIn .18s ease-out;}' +
            '.nn-modal-head{display:flex;align-items:center;gap:12px;margin-bottom:14px;}' +
            '.nn-modal-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}' +
            '.nn-modal-icon i{font-size:20px;}' +
            '.nn-modal-icon.success{background:rgba(34,197,94,.12);color:#22c55e;}' +
            '.nn-modal-icon.info{background:rgba(37,99,235,.12);color:#2563eb;}' +
            '.nn-modal-icon.warning{background:rgba(245,158,11,.12);color:#f59e0b;}' +
            '.nn-modal-icon.danger{background:rgba(239,68,68,.12);color:#ef4444;}' +
            '.nn-modal-title{font-size:16px;font-weight:700;color:var(--nn-text,#0f172a);}' +
            '.nn-modal-body{font-size:14px;line-height:1.7;color:var(--nn-text-secondary,#475569);margin-bottom:20px;word-break:break-word;}' +
            '.nn-modal-foot{display:flex;justify-content:flex-end;gap:10px;}' +
            '@keyframes nnFadeIn{from{opacity:0;}to{opacity:1;}}' +
            '@keyframes nnPopIn{from{opacity:0;transform:scale(.92) translateY(10px);}to{opacity:1;transform:scale(1) translateY(0);}}';
        (document.head || document.body).appendChild(style);
    }

    var icons = {
        success: '<i class="fe fe-check-circle"></i>',
        info: '<i class="fe fe-info"></i>',
        warning: '<i class="fe fe-alert-triangle"></i>',
        danger: '<i class="fe fe-x-circle"></i>'
    };
    var btnConfigs = {
        success: { cls: 'btn-success', confirm: '确定', showCancel: false },
        info:    { cls: 'btn-primary', confirm: '确定', showCancel: false },
        warning: { cls: 'btn-warning', confirm: '确认', showCancel: true, cancel: '取消' },
        danger:  { cls: 'btn-danger',  confirm: '重试', showCancel: true, cancel: '取消' }
    };
    var cfg = btnConfigs[type] || btnConfigs.info;

    var overlay = $('<div class="nn-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="nn-modal-title">\
        <div class="nn-modal-box">\
            <div class="nn-modal-head">\
                <div class="nn-modal-icon ' + type + '">' + icons[type] + '</div>\
                <div class="nn-modal-title" id="nn-modal-title">' + title + '</div>\
            </div>\
            <div class="nn-modal-body">' + message + '</div>\
            <div class="nn-modal-foot"></div>\
        </div></div>').appendTo('body');

    var foot = overlay.find('.nn-modal-foot');
    if (cfg.showCancel) {
        foot.append('<button class="btn btn-outline-secondary nn-modal-cancel">' + cfg.cancel + '</button>');
    }
    foot.append('<button class="btn ' + cfg.cls + ' nn-modal-confirm">' + cfg.confirm + '</button>');

    var closeModal = function (callback) {
        overlay.find('.nn-modal-box').css('animation', 'nnFadeIn 0.15s ease reverse');
        overlay.css('animation', 'nnFadeIn 0.15s ease reverse');
        setTimeout(function () {
            overlay.remove();
            $(document).off('keydown.nnmodal');
            if (callback) callback();
        }, 150);
    };

    /* 聚焦主按钮 */
    setTimeout(function () { overlay.find('.nn-modal-confirm').focus(); }, 50);

    overlay.find('.nn-modal-confirm').on('click', function () { closeModal(opts.onConfirm); });
    overlay.find('.nn-modal-cancel').on('click', function () { closeModal(opts.onCancel); });

    /* Enter → 主按钮，Esc → 取消/关闭 */
    $(document).on('keydown.nnmodal', function (e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            if (cfg.showCancel) { closeModal(opts.onCancel); }
            else { closeModal(opts.onConfirm); }
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            closeModal(opts.onConfirm);
        }
    });

    return { close: function () { closeModal(); } };
}

/* 兼容旧调用方式 */
$.modal = function (type, title, message, options) {
    options = options || {};
    return showAlert({
        type: type,
        title: title,
        message: message,
        onConfirm: options.onConfirm,
        onCancel: options.onCancel
    });
};

/**
 * 简化调用
 */
$.success = function (msg, cb) { return $.modal('success', '操作成功', msg, { onConfirm: cb }); };
$.info = function (msg, cb) { return $.modal('info', '提示信息', msg, { onConfirm: cb }); };
$.warning = function (msg, cb) { return $.modal('warning', '操作警告', msg, { showCancel: true, onConfirm: cb }); };
$.danger = function (msg, cb) { return $.modal('danger', '操作失败', msg, { showCancel: true, onConfirm: cb }); };

/* ============================================
   后台布局交互（侧栏、菜单、全屏、折叠）
   ============================================ */
; (function ($) {
    'use strict';

    /* 主题切换：html[data-theme=dark] + localStorage 持久化（暗黑模式） */
    $(document).on('click', '[data-theme-toggle]', function (e) {
        e.preventDefault();
        var html = document.documentElement;
        if (html.getAttribute('data-theme') === 'dark') {
            html.removeAttribute('data-theme');
            try { localStorage.setItem('nncms_theme', 'light'); } catch (err) {}
        } else {
            html.setAttribute('data-theme', 'dark');
            try { localStorage.setItem('nncms_theme', 'dark'); } catch (err) {}
        }
    });

    var sidebar = $('.app-sidebar');
    var header = $('.app-header');
    var mainContent = $('.main-content');
    var overlay = $('.app-sidebar__overlay');
    var isMobile = function () { return $(window).width() < 1024; };

    /* 1. 侧栏折叠/展开 */
    $(document).on('click', '[data-toggle="sidebar"]', function (e) {
        e.preventDefault();
        if (isMobile()) {
            /* 移动端：抽屉式 */
            sidebar.toggleClass('mobile-open');
            overlay.toggleClass('mobile-show');
        } else {
            /* 桌面端：折叠 */
            sidebar.toggleClass('collapsed');
            header.toggleClass('collapsed');
            mainContent.toggleClass('collapsed');
        }
    });

    /* 点击 overlay 关闭移动端侧栏 */
    overlay.on('click', function () {
        sidebar.removeClass('mobile-open');
        overlay.removeClass('mobile-show');
    });

    /* 2. 子菜单展开/折叠 */
    $(document).on('click', '[data-bs-toggle="slide"]', function (e) {
        e.preventDefault();
        if (sidebar.hasClass('collapsed') && !isMobile()) return;
        var parent = $(this).closest('.slide');
        /* 切换本菜单 */
        parent.toggleClass('is-expanded');
        /* 收起同级其他菜单 */
        parent.siblings('.slide').removeClass('is-expanded');
    });

    /* 3. 全屏切换 */
    $(document).on('click', '.fullscreen-button', function (e) {
        e.preventDefault();
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen();
        } else {
            document.exitFullscreen();
        }
    });


    /* 5. 窗口变化时处理边栏状态 */
    $(window).on('resize', function () {
        if (!isMobile()) {
            sidebar.removeClass('mobile-open');
            overlay.removeClass('mobile-show');
        }
    });

    /* 6. 年份自动更新 */
    $('#year').text(new Date().getFullYear());

    /* 7. 折叠时显示 tooltip */
    /* 在 collapsed 侧栏中，鼠标悬停在侧栏项上时显示 data-label */

})(jQuery);

/* ============================================
   前台模板脚本（原 template/default/js/cms.js，合并）
   ============================================ */
var YingShi = {
    'Url': document.URL,
    'Title': document.title,
    'jscode': function () {

        // 仅当 lazyload 插件已加载时才调用，避免后台/未引入插件的页面抛错
        if ($.fn && $.fn.lazyload) {
            $(".lazy").lazyload({
                effect: "fadeIn",
                threshold: 200,
                failure_limit: 10,
                skip_invisible: false
            });
        }

        var currentUrl = window.location.toString().split("#")[0];
        $(".header-tab a").each(function () {
            if (this.href === currentUrl) {
                $(this).addClass("selected text-yellow-500").removeClass("text-white");
                return false; // 停止循环
            }
        });

        var currentUrl2 = window.location.href.split("#")[0];
        $(".pcnav a").each(function () {
            if (this.href === currentUrl2) {
                $(this).find('span').addClass("text-yellow-500").removeClass("text-white");
                $(this).append('<div class="border-2 border-yellow-500 w-5 h-0.5 rounded-lg"></div>')
                return false;
            }
        });

    },
};
$(function () {
    YingShi.jscode();
});
