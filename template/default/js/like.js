
(function() {
  'use strict';
  // 登录跳转地址由模板 data-login-url 注入（public/like.html）
  var loginUrl = (function(){
    var box = document.querySelector('.action-buttons');
    return box ? (box.getAttribute('data-login-url') || '/') : '/';
  })();

  // 通用Toast管理器
  const toastManager = {
    toast: document.getElementById('actionToast'),
    toastMsg: document.getElementById('actionToastMsg'),
    toastClose: document.getElementById('actionToastClose'),
    toastTimer: null,

    show: function(message, { duration = 2000, callback = null } = {}) {
      this.toastMsg.textContent = message;
      this.toast.hidden = false;
      this.toast.classList.add('show');
      if (this.toastTimer) { clearTimeout(this.toastTimer); this.toastTimer = null; }
      if (duration > 0) {
        this.toastTimer = setTimeout(() => {
          this.hide();
          if (typeof callback === 'function') callback();
        }, duration);
      }
    },
    hide: function() {
      this.toast.classList.remove('show');
      setTimeout(() => { this.toast.hidden = true; }, 240);
    }
  };

  toastManager.toastClose && toastManager.toastClose.addEventListener('click', () => toastManager.hide());

  // 通用操作按钮类
  class ActionButton {
    constructor(btn) {
      this.btn = btn;
      this.countEl = btn.querySelector('.action-count');
      this.aid = btn.dataset.aid || '';
      this.uid = parseInt(btn.dataset.uid, 10) || 0;
      this.model = btn.dataset.model || '';
      this.type = btn.dataset.type || 'like';
      this.api = btn.dataset.api || '';
      this.token = btn.dataset.token || '';
      this.state = (this.type === 'like')
        ? (btn.dataset.state === 'unlike' ? 'unlike' : 'like')
        : (btn.dataset.state === 'unfavorite' ? 'unfavorite' : 'favorite');
      this.count = parseInt(btn.dataset.count || '0', 10) || 0;
      this.pending = false;
      this.init();
    }
    init() { this.initUI(); this.bindEvents(); }
    initUI() {
      const isActive = (this.type === 'like' && this.state === 'unlike') ||
                      (this.type === 'favorite' && this.state === 'unfavorite');
      this.btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      this.btn.dataset.state = this.state;
      this.countEl.textContent = String(this.count);
    }
    bindEvents() {
      this.btn.addEventListener('click', async (e) => { e.preventDefault(); await this.handleClick(); });
      this.btn.addEventListener('keydown', (e) => {
        if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); this.btn.click(); }
      });
    }
    async handleClick() {
      if (this.uid === 0) {
        toastManager.show('请先登录再操作', { duration: 5000, callback: () => { window.location.href = loginUrl; } });
        return;
      }
      const actionType = (this.type === 'like' && this.state === 'like') ||
                        (this.type === 'favorite' && this.state === 'favorite')
                        ? this.type : 'un' + this.type;
      const data = await this.postAction(actionType);
      if (!data) return;
      const code = Number(data.code);
      const msg = data.message || '';
      if (code === 0 && actionType === this.type) {
        this.applyActive(true);
        const successMsg = this.type === 'like' ? '点赞成功' : '收藏成功';
        toastManager.show(msg || successMsg);
      } else if (code === 3 && actionType === 'un' + this.type) {
        this.applyInactive(true);
        const successMsg = this.type === 'like' ? '已取消点赞' : '取消收藏';
        toastManager.show(msg || successMsg);
      } else if (code === 1) {
        const repeatMsg = this.type === 'like' ? '不要重复点赞' : '不要重复收藏';
        toastManager.show(msg || repeatMsg);
      } else {
        toastManager.show(msg || '操作失败');
      }
    }
    async postAction(type) {
      if (this.pending) return null;
      this.pending = true;
      this.btn.classList.add('disabled');
      this.btn.disabled = true;
      const body = new URLSearchParams();
      body.append('aid', this.aid);
      body.append('uid', this.uid);
      body.append('model', this.model);
      body.append('type', type);
      body.append('safe_token', this.token);
      let data = null;
      try {
        const res = await fetch(this.api, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        const text = await res.text();
        try { data = text ? JSON.parse(text) : {}; }
        catch (e) { toastManager.show('服务器返回格式不正确', { duration: 2500 }); }
      } catch (err) {
        console.error('网络错误', err);
        toastManager.show('网络错误，请稍后再试', { duration: 2500 });
      } finally {
        this.pending = false;
        this.btn.classList.remove('disabled');
        this.btn.disabled = false;
      }
      return data;
    }
    applyActive(animate = true) {
      this.state = this.type === 'like' ? 'unlike' : 'unfavorite';
      this.btn.dataset.state = this.state;
      this.btn.setAttribute('aria-pressed', 'true');
      this.count = Math.max(0, this.count) + 1;
      this.countEl.textContent = String(this.count);
      if (animate) {
        const icon = this.btn.querySelector('.action-icon');
        if (icon) { icon.classList.add('pop'); setTimeout(() => icon.classList.remove('pop'), 420); }
      }
    }
    applyInactive(animate = false) {
      this.state = this.type;
      this.btn.dataset.state = this.state;
      this.btn.setAttribute('aria-pressed', 'false');
      this.count = Math.max(0, this.count - 1);
      this.countEl.textContent = String(this.count);
      if (animate) {
        const icon = this.btn.querySelector('.action-icon');
        if (icon) { icon.classList.add('pop'); setTimeout(() => icon.classList.remove('pop'), 420); }
      }
    }
  }
  // 初始化按钮
  document.addEventListener('DOMContentLoaded', () => {
    const likeBtn = document.getElementById('likeBtn');
    const favBtn = document.getElementById('favBtn');
    if (likeBtn) new ActionButton(likeBtn);
    if (favBtn) new ActionButton(favBtn);
  });
})();
