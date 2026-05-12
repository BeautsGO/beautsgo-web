// BeautsGO SSR 通用 UI 工具集 —— 模拟 UniApp API,让 1:1 还原后的页面能跑
// 仅依赖 vanilla JS,不引入框架。所有方法挂在 window.BG.* 命名空间。
(function () {
    'use strict';
    if (window.BG && window.BG.__loaded) return;

    var BG = (window.BG = window.BG || {});
    BG.__loaded = true;

    // -------------------- Toast 提示 --------------------
    BG.toast = function (msg, type) {
        type = type || 'info';
        var t = document.createElement('div');
        t.className = 'bg-toast bg-toast--' + type;
        t.textContent = String(msg || '');
        document.body.appendChild(t);
        // trigger reflow then fade
        void t.offsetWidth;
        t.classList.add('bg-toast--show');
        setTimeout(function () {
            t.classList.remove('bg-toast--show');
            setTimeout(function () { t.parentNode && t.parentNode.removeChild(t); }, 200);
        }, 1800);
    };

    // -------------------- 图片放大预览(lightbox)--------------------
    BG.previewImage = function (urls, current) {
        if (!Array.isArray(urls) || !urls.length) return;
        var idx = Math.max(0, urls.indexOf(current));
        if (idx < 0) idx = 0;

        var mask = document.createElement('div');
        mask.className = 'bg-lightbox';
        mask.innerHTML =
            '<div class="bg-lightbox__close" aria-label="关闭">×</div>' +
            '<div class="bg-lightbox__counter"></div>' +
            '<img class="bg-lightbox__img" alt="">' +
            '<div class="bg-lightbox__nav bg-lightbox__nav--prev" aria-label="上一张">‹</div>' +
            '<div class="bg-lightbox__nav bg-lightbox__nav--next" aria-label="下一张">›</div>';
        var imgEl = mask.querySelector('.bg-lightbox__img');
        var counter = mask.querySelector('.bg-lightbox__counter');
        function render() {
            imgEl.src = urls[idx];
            counter.textContent = (idx + 1) + ' / ' + urls.length;
        }
        function close() {
            document.removeEventListener('keydown', onKey);
            mask.parentNode && mask.parentNode.removeChild(mask);
        }
        function onKey(e) {
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowLeft' && idx > 0) { idx--; render(); }
            else if (e.key === 'ArrowRight' && idx < urls.length - 1) { idx++; render(); }
        }
        mask.querySelector('.bg-lightbox__close').onclick = close;
        mask.querySelector('.bg-lightbox__nav--prev').onclick = function () { if (idx > 0) { idx--; render(); } };
        mask.querySelector('.bg-lightbox__nav--next').onclick = function () { if (idx < urls.length - 1) { idx++; render(); } };
        mask.onclick = function (e) { if (e.target === mask) close(); };
        document.addEventListener('keydown', onKey);
        render();
        document.body.appendChild(mask);
    };

    // 自动给 [data-preview] 加 lightbox 监听
    BG.bindPreviews = function (root) {
        root = root || document;
        var nodes = root.querySelectorAll('[data-preview]');
        nodes.forEach(function (el) {
            if (el.__bgBound) return;
            el.__bgBound = true;
            el.style.cursor = 'zoom-in';
            el.addEventListener('click', function () {
                var groupAttr = el.getAttribute('data-preview-group');
                var urls;
                if (groupAttr) {
                    urls = Array.prototype.map.call(
                        document.querySelectorAll('[data-preview-group="' + groupAttr + '"]'),
                        function (n) { return n.getAttribute('data-preview') || n.src; }
                    );
                } else {
                    urls = [el.getAttribute('data-preview') || el.src];
                }
                BG.previewImage(urls, el.getAttribute('data-preview') || el.src);
            });
        });
    };

    // -------------------- 复制到剪贴板 --------------------
    BG.copy = function (text, successMsg) {
        var done = function (ok) { BG.toast(ok ? (successMsg || '已复制') : '复制失败', ok ? 'success' : 'error'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            ta.parentNode.removeChild(ta);
            done(ok);
        }
    };
    BG.bindCopy = function (root) {
        root = root || document;
        root.querySelectorAll('[data-copy]').forEach(function (el) {
            if (el.__bgBound) return; el.__bgBound = true;
            el.addEventListener('click', function () { BG.copy(el.getAttribute('data-copy'), el.getAttribute('data-copy-msg')); });
        });
    };

    // -------------------- 拨号 --------------------
    BG.call = function (phone) { if (phone) location.href = 'tel:' + phone; };

    // -------------------- 倒计时(用于验证码按钮)--------------------
    BG.countdown = function (btn, sec, label) {
        sec = sec || 60;
        label = label || '重新获取';
        var orig = btn.textContent;
        btn.disabled = true;
        var t = setInterval(function () {
            btn.textContent = sec + 's';
            if (--sec < 0) {
                clearInterval(t);
                btn.disabled = false;
                btn.textContent = label;
            }
        }, 1000);
    };

    // -------------------- 初始化:DOM ready 后绑定 --------------------
    function init() { BG.bindPreviews(); BG.bindCopy(); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
