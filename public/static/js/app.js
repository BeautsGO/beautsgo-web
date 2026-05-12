// BeautsGO SSR 客户端 JS —— 仅承载用户交互增强,不参与首屏渲染
// 原则:无 JS 也能用(SEO 由 SSR 直出 + <a href> 链接化兜底)

(function () {
    'use strict';
    document.documentElement.classList.add('ssr-ready');

    // ---------- 列表页:渐进增强滚动加载更多 (Progressive Enhancement) ----------
    // 触发条件:页面里存在 .view-list 列表容器 + .pagination 链接(说明 SSR 已经准备了完整分页)
    function initInfiniteScroll() {
        const list =
            document.querySelector('.view-list .list-jg') ||
            document.querySelector('.view-list .list-xm') ||
            document.querySelector('.view-list .list-ys') ||
            document.querySelector('.view-list .jf_duihuan .sp_content');
        if (!list) return;

        const pagination = document.querySelector('.pagination');
        const nextLink = pagination && pagination.querySelector('a[rel="next"]');

        const url = new URL(window.location.href);
        let currentPage = parseInt(url.searchParams.get('page') || '1', 10);
        let hasMore = !!nextLink;
        let loading = false;

        // 隐藏 SSR 分页 UI(IO 接管后不再需要)
        if (pagination) pagination.style.display = 'none';

        // 状态行
        const status = document.createElement('div');
        status.className = 'load-more-status';
        status.style.cssText = 'text-align:center;padding:18.7px 0 30px;color:#999;font-size:12px;';
        list.parentNode.insertBefore(status, list.nextSibling);

        // sentinel
        const sentinel = document.createElement('div');
        sentinel.className = 'load-more-sentinel';
        sentinel.style.cssText = 'height:1px;width:100%;';
        list.parentNode.insertBefore(sentinel, status);

        async function loadNext() {
            if (loading || !hasMore) return;
            loading = true;
            status.textContent = '加载中…';
            const nextPage = currentPage + 1;
            const u = new URL(window.location.href);
            u.searchParams.set('page', nextPage);
            u.searchParams.set('_partial', '1');
            try {
                const r = await fetch(u.toString(), {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'fetch' },
                });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const html = (await r.text()).trim();
                if (!html) { hasMore = false; status.textContent = '~ 没有更多了 ~'; return; }
                const tmp = document.createElement('div');
                tmp.innerHTML = html;
                while (tmp.firstChild) list.appendChild(tmp.firstChild);
                currentPage = nextPage;
                hasMore = r.headers.get('X-Has-More') === '1';
                status.textContent = hasMore ? '' : '~ 没有更多了 ~';
            } catch (e) {
                status.textContent = '加载失败,点击重试';
                status.style.cursor = 'pointer';
                status.onclick = function () {
                    status.onclick = null;
                    status.style.cursor = '';
                    loadNext();
                };
            } finally {
                loading = false;
            }
        }

        if ('IntersectionObserver' in window) {
            const io = new IntersectionObserver(function (entries) {
                if (entries[0].isIntersecting) loadNext();
            }, { rootMargin: '300px' });
            io.observe(sentinel);
        } else {
            // 老浏览器兜底:还原 SSR 分页 UI
            if (pagination) pagination.style.display = '';
            sentinel.remove();
            status.remove();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initInfiniteScroll);
    } else {
        initInfiniteScroll();
    }

    // ---------- 浏览轨迹埋点(对齐 beauts_app/utils/common.js anchorPoint) ----------
    // fire-and-forget POST /api/user/optSave;爬虫不执行 JS 自然过滤
    function getCookie(name) {
        var m = document.cookie.match('(^|; )' + name + '=([^;]+)');
        return m ? decodeURIComponent(m[2]) : '';
    }
    function initTracking() {
        var t = window.__BG_TRACK__;
        if (!t || !t.api || !t.payload) return;
        var body = {
            type: t.payload.type || 1,
            name: t.payload.name || '',
            id: '',  // 上一个埋点 id(SSR 端不维持会话链,留空)
            with_id: t.payload.with_id || 0,
            fun_type: t.payload.fun_type || '浏览',
            action_name: t.payload.action_name || '',
            page_from_source: document.referrer || '',
            shareId: getCookie('shareId'),
            shareType: getCookie('shareType'),
        };
        try {
            var form = new URLSearchParams();
            Object.keys(body).forEach(function (k) { form.append(k, body[k]); });
            // 优先 sendBeacon(页面跳走也能发),否则 fetch keepalive
            if (navigator.sendBeacon) {
                navigator.sendBeacon(t.api, form);
            } else {
                fetch(t.api, { method: 'POST', body: form, keepalive: true, credentials: 'omit' });
            }
        } catch (e) { /* 静默 */ }
    }
    if (document.readyState === 'complete') {
        setTimeout(initTracking, 0);
    } else {
        window.addEventListener('load', function () { setTimeout(initTracking, 0); });
    }
})();
