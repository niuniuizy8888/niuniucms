
// portal.js - portal (front) global behavior, extracted from inline <script> in
// public/include.html + public/head.html + search.html
(function() {
  'use strict';

  // ---- search form submit (from public/head.html; config on data-* attrs) ----
  window.handleSearch = function(form) {
    if (!form || !form.querySelector) return false;
    var input = form.querySelector('input[name="keyword"]');
    var keyword = input && input.value ? input.value.replace(/^\s+|\s+$/g, '') : '';
    if (!keyword) {
      if (input && input.focus) input.focus();
      return false;
    }
    var rw = parseInt(form.getAttribute('data-rewrite') || '0', 10) || 0;
    var base = form.getAttribute('data-search') || '';
    var enc = encodeURIComponent(keyword);
    if (rw === 0) {
      var sep = base.indexOf('?') === -1 ? '?' : '&';
      window.location.href = base + sep + 'keyword=' + enc;
      return false;
    } else if (rw === 1) {
      var b1 = base.replace(/^\/\?/, '/').replace(/\.html$/i, '');
      window.location.href = b1.replace(/\/$/, '') + '-' + enc + '.html';
      return false;
    } else if (rw === 2) {
      var b2 = base.replace(/\.html$/i, '').replace(/\/$/, '');
      window.location.href = b2 + '/' + enc + '.html';
      return false;
    } else if (rw === 3) {
      var b3 = base.replace(/\.html$/i, '').replace(/\/$/, '');
      window.location.href = b3 + '/' + enc;
      return false;
    }
    return false;
  };

  // ---- page load animation (from public/include.html) ----
  window.addEventListener('load', function() {
    setTimeout(function() {
      var loading = document.querySelector('.loading');
      if (loading) loading.classList.add('hidden');
      document.body.classList.add('page-loaded');
    }, 300);
  });

  document.addEventListener('DOMContentLoaded', function() {

    // search count fill (from search.html: $('.hl-total').html('{$num}'))
    document.querySelectorAll('.hl-total').forEach(function(el) {
      var c = el.getAttribute('data-count');
      if (c !== null) el.textContent = c;
    });

    // ---- search box interaction (from public/include.html) ----
    var searchInput = document.getElementById('searchInput');
    var searchWrap = document.getElementById('searchWrap');
    if (searchInput && searchWrap) {
      searchInput.addEventListener('focus', function() {
        searchWrap.classList.remove('hidden');
        requestAnimationFrame(function() {
          searchWrap.style.opacity = '1';
          searchWrap.style.transform = 'scaleY(1)';
        });
      });
      document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchWrap.contains(e.target)) {
          searchWrap.style.opacity = '0';
          searchWrap.style.transform = 'scaleY(0.95)';
          setTimeout(function() {
            searchWrap.classList.add('hidden');
          }, 250);
        }
      });
    }

    // ---- back to top (from public/include.html) ----
    var backTop = document.getElementById('backTop');
    if (backTop) {
      var scrollTimeout;
      window.addEventListener('scroll', function() {
        if (scrollTimeout) cancelAnimationFrame(scrollTimeout);
        scrollTimeout = requestAnimationFrame(function() {
          if (window.scrollY > 300) {
            backTop.classList.remove('d-none');
            backTop.style.opacity = '1';
          } else {
            backTop.style.opacity = '0';
            setTimeout(function() {
              backTop.classList.add('d-none');
            }, 250);
          }
        });
      }, { passive: true });
      backTop.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    }

    // ---- lazy image enhance (from public/include.html) ----
    if ('IntersectionObserver' in window) {
      var imageObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
          if (entry.isIntersecting) {
            var img = entry.target;
            if (img.dataset.src) { img.src = img.dataset.src; img.removeAttribute('data-src'); }
            img.classList.add('loaded');
            imageObserver.unobserve(img);
          }
        });
      }, { rootMargin: '50px 0px', threshold: 0.01 });
      document.querySelectorAll('img[data-src], img.lazy, img[loading="lazy"]').forEach(function(img) {
        imageObserver.observe(img);
      });
    }

    // ---- scroll reveal (from public/include.html) ----
    if ('IntersectionObserver' in window) {
      var animateObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('animate-visible');
            animateObserver.unobserve(entry.target);
          }
        });
      }, { rootMargin: '0px 0px -50px 0px', threshold: 0.1 });
      document.querySelectorAll('.animate-on-scroll').forEach(function(el) {
        animateObserver.observe(el);
      });
    }

    // ---- video card touch feedback (from public/include.html) ----
    document.querySelectorAll('.video-card, .topic-details-card').forEach(function(card) {
      card.addEventListener('touchstart', function() { this.style.transform = 'scale(0.98)'; }, { passive: true });
      card.addEventListener('touchend', function() { this.style.transform = ''; }, { passive: true });
    });

    // ---- mobile bottom nav highlight (from public/include.html) ----
    var currentPath = window.location.pathname;
    document.querySelectorAll('.bottom-nav-item').forEach(function(item) {
      var href = item.getAttribute('href');
      if (href === currentPath || (href === '/' && currentPath === '/')) item.classList.add('active');
    });

    // ---- desktop nav highlight (from public/include.html) ----
    document.querySelectorAll('.header-tab, .nav-item').forEach(function(item) {
      var link = item.querySelector('a') || item;
      var href = link.getAttribute('href');
      if (!href) return;
      var normHref = href.replace(/\/$/, '');
      var normPath = currentPath.replace(/\/$/, '');
      if (normHref === normPath || (href === '/' && currentPath === '/')) item.classList.add('active');
    });

    // ---- mobile sidebar (from public/head.html) ----
    var mobileMenuBtn = document.getElementById('mobileMenuBtn');
    var mobileSidebar = document.getElementById('mobileSidebar');
    var sidebarOverlay = document.getElementById('sidebarOverlay');
    var closeSidebarBtn = document.getElementById('closeSidebarBtn');
    var sidebarContent = document.getElementById('sidebarContent');
    if (mobileMenuBtn) {
      mobileMenuBtn.addEventListener('click', function() {
        mobileSidebar.classList.remove('hidden');
        setTimeout(function() { sidebarContent.classList.remove('-translate-x-full'); }, 10);
      });
    }
    function closeSidebar() {
      sidebarContent.classList.add('-translate-x-full');
      setTimeout(function() { mobileSidebar.classList.add('hidden'); }, 300);
    }
    if (closeSidebarBtn) closeSidebarBtn.addEventListener('click', closeSidebar);
    if (sidebarOverlay) sidebarOverlay.addEventListener('click', closeSidebar);
  });

  // ---- view transition (from public/include.html) ----
  if (document.startViewTransition) {
    document.addEventListener('click', function(e) {
      var link = e.target.closest('a[href]');
      if (link && link.href && !link.href.includes('#') && link.target !== '_blank' && link.hostname === window.location.hostname) {
        e.preventDefault();
        document.startViewTransition(function() { window.location.href = link.href; });
      }
    });
  }
})();
