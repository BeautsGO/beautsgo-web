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

    // -------------------- 加入对比池(localStorage,对齐 detail.vue contrast)--------------------
    // type=1 医院 / 2 医生 / 3 项目;最多 4 个
    BG.toggleContrast = function (btn) {
        var type = parseInt(btn.dataset.contrastType || '0', 10);
        var id   = parseInt(btn.dataset.contrastId   || '0', 10);
        var name = btn.dataset.contrastName || '';
        var cover= btn.dataset.contrastCover || '';
        if (!type || !id) { BG.toast('参数缺失', 'error'); return; }
        var key = 'cmp_pool_' + type;
        var pool;
        try { pool = JSON.parse(localStorage.getItem(key)) || []; } catch (e) { pool = []; }
        var i = pool.findIndex(function (x) { return x.id === id; });
        if (i >= 0) {
            pool.splice(i, 1);
            btn.classList.remove('is-contrasted');
            BG.toast('已从对比清单移除', 'info');
        } else if (pool.length >= 4) {
            BG.toast('对比最多 4 个,请先移除', 'error'); return;
        } else {
            pool.push({ id: id, name: name, cover: cover });
            btn.classList.add('is-contrasted');
            BG.toast('已加入对比 (' + pool.length + '/4)', 'success');
        }
        localStorage.setItem(key, JSON.stringify(pool));
    };
    BG.bindContrast = function (root) {
        root = root || document;
        root.querySelectorAll('[data-contrast-toggle]').forEach(function (el) {
            if (el.__bgBound) return; el.__bgBound = true;
            // 进入页面时如果已在对比池里,加 active class
            var type = parseInt(el.dataset.contrastType || '0', 10);
            var id   = parseInt(el.dataset.contrastId   || '0', 10);
            try {
                var pool = JSON.parse(localStorage.getItem('cmp_pool_' + type)) || [];
                if (pool.some(function (x) { return x.id === id; })) el.classList.add('is-contrasted');
            } catch (e) {}
            el.addEventListener('click', function (e) { e.preventDefault(); BG.toggleContrast(el); });
        });
    };

    // -------------------- 视频播放(banner is_mp4 → lightbox 视频弹层)--------------------
    BG.playVideo = function (url, poster) {
        if (!url) return;
        var mask = document.createElement('div');
        mask.className = 'bg-lightbox';
        mask.innerHTML =
            '<div class="bg-lightbox__close" aria-label="关闭">×</div>' +
            '<video class="bg-lightbox__video" src="' + url + '" controls autoplay playsinline' +
            (poster ? ' poster="' + poster + '"' : '') + '></video>';
        function close() {
            document.removeEventListener('keydown', onKey);
            mask.parentNode && mask.parentNode.removeChild(mask);
        }
        function onKey(e) { if (e.key === 'Escape') close(); }
        mask.querySelector('.bg-lightbox__close').onclick = close;
        mask.onclick = function (e) { if (e.target === mask) close(); };
        document.addEventListener('keydown', onKey);
        document.body.appendChild(mask);
    };
    BG.bindVideoPlay = function (root) {
        root = root || document;
        root.querySelectorAll('[data-video-url]').forEach(function (el) {
            if (el.__bgBound) return; el.__bgBound = true;
            el.style.cursor = 'pointer';
            el.addEventListener('click', function (e) {
                e.preventDefault();
                BG.playVideo(el.dataset.videoUrl, el.dataset.videoPoster);
            });
        });
    };

    // -------------------- 分享上报(POST /{lang}/share/save)--------------------
    // 用户点分享按钮触发(打开浮层时一并 fire-and-forget 上报)
    BG.reportShare = function (type, id, seg) {
        if (!type || !id) return;
        seg = seg || (document.documentElement.dataset.langSeg || 'cn');
        try {
            var form = new URLSearchParams();
            form.append('type', type); form.append('with_id', id);
            if (navigator.sendBeacon) {
                navigator.sendBeacon('/' + seg + '/share/save', form);
            } else {
                fetch('/' + seg + '/share/save', {
                    method: 'POST', body: form, credentials: 'same-origin', keepalive: true,
                });
            }
        } catch (e) { /* 静默 */ }
    };
    BG.bindShareReport = function (root) {
        root = root || document;
        root.querySelectorAll('[data-share-report]').forEach(function (el) {
            if (el.__bgBound) return; el.__bgBound = true;
            el.addEventListener('click', function () {
                BG.reportShare(parseInt(el.dataset.shareType || '0', 10),
                               parseInt(el.dataset.shareId   || '0', 10),
                               el.dataset.langSeg);
            });
        });
    };

    // -------------------- 收藏(POST /{lang}/collect)--------------------
    // 1:1 对齐 detail.vue collection():type=1/2/3 with_id is_collect
    BG.toggleCollect = function (btn) {
        var type = parseInt(btn.dataset.collectType || '0', 10);
        var id   = parseInt(btn.dataset.collectId || '0', 10);
        if (!type || !id) { BG.toast('参数缺失', 'error'); return; }
        var isCollect = btn.classList.contains('is-collected') ? 0 : 1;
        var seg = btn.dataset.langSeg || (document.documentElement.dataset.langSeg || 'cn');

        var form = new URLSearchParams();
        form.append('type', type); form.append('with_id', id); form.append('is_collect', isCollect);
        fetch('/' + seg + '/collect', {
            method: 'POST', body: form, credentials: 'same-origin',
            headers: {'X-Requested-With': 'fetch'},
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j.ok) {
                btn.classList.toggle('is-collected', isCollect === 1);
                BG.toast(j.msg || (isCollect ? '已收藏' : '已取消'), 'success');
            } else {
                BG.toast(j.msg || '操作失败', 'error');
            }
        }).catch(function () { BG.toast('网络错误', 'error'); });
    };
    BG.bindCollect = function (root) {
        root = root || document;
        root.querySelectorAll('[data-collect-toggle]').forEach(function (el) {
            if (el.__bgBound) return; el.__bgBound = true;
            el.addEventListener('click', function (e) { e.preventDefault(); BG.toggleCollect(el); });
        });
    };

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
    function init() {
        BG.bindPreviews(); BG.bindCopy(); BG.bindCollect();
        BG.bindShareReport(); BG.bindContrast(); BG.bindVideoPlay();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
