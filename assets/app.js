(() => {
    'use strict';

    const pagerSelector = '[data-async-pager]';

    const routeOf = (url) => new URL(url, window.location.href).searchParams.get('r') || 'dashboard';

    const showFeedback = (root, type, message, retryUrl = '') => {
        const feedback = root.querySelector('[data-async-feedback]');
        if (!feedback) return;
        feedback.className = `async-feedback ${type}`;
        feedback.hidden = false;
        feedback.innerHTML = '';

        const text = document.createElement('span');
        text.textContent = message;
        feedback.appendChild(text);

        if (retryUrl) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button small-button';
            button.dataset.asyncRetry = retryUrl;
            button.textContent = '再試行';
            feedback.appendChild(button);
        }
    };

    const hideFeedback = (root) => {
        const feedback = root.querySelector('[data-async-feedback]');
        if (!feedback) return;
        feedback.hidden = true;
        feedback.textContent = '';
        feedback.className = 'async-feedback';
    };

    const setBusy = (root, busy) => {
        root.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            showFeedback(root, 'loading', '一覧を読み込んでいます…');
        }
    };

    const fragmentUrl = (targetUrl) => {
        const url = new URL(targetUrl, window.location.href);
        url.searchParams.set('_fragment', '1');
        return url;
    };

    const loadPage = async (root, targetUrl, options = {}) => {
        const content = root.querySelector('[data-async-content]');
        if (!content) return;

        const canonicalUrl = new URL(targetUrl, window.location.href);
        canonicalUrl.searchParams.delete('_fragment');

        if (root._seoWatchController) {
            root._seoWatchController.abort();
        }
        const controller = new AbortController();
        root._seoWatchController = controller;

        setBusy(root, true);
        try {
            const response = await fetch(fragmentUrl(canonicalUrl), {
                method: 'GET',
                headers: {
                    'Accept': 'text/html',
                    'X-Requested-With': 'fetch',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const html = await response.text();
            content.innerHTML = html;
            hideFeedback(root);

            if (options.history !== false) {
                window.history.pushState({seoWatchPager: true}, '', canonicalUrl);
            }

            if (options.scroll !== false) {
                const top = root.getBoundingClientRect().top;
                if (top < 0 || top > window.innerHeight * 0.65) {
                    root.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            }
        } catch (error) {
            if (error && error.name === 'AbortError') return;
            showFeedback(root, 'error', '一覧の取得に失敗しました。現在の表示は保持しています。', canonicalUrl.toString());
        } finally {
            if (root._seoWatchController === controller) {
                root._seoWatchController = null;
                setBusy(root, false);
                const feedback = root.querySelector('[data-async-feedback]');
                if (feedback && feedback.classList.contains('loading')) hideFeedback(root);
            }
        }
    };

    document.addEventListener('click', (event) => {
        const pageLink = event.target.closest('[data-page-link]');
        if (pageLink) {
            const root = pageLink.closest(pagerSelector);
            if (!root || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            loadPage(root, pageLink.href);
            return;
        }

        const retry = event.target.closest('[data-async-retry]');
        if (retry) {
            const root = retry.closest(pagerSelector);
            if (!root) return;
            loadPage(root, retry.dataset.asyncRetry || window.location.href);
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm]');
        if (!form || event.defaultPrevented) return;
        const message = form.dataset.confirm || 'この操作を実行しますか？';
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-page-size-form]');
        if (!form) return;
        const root = form.closest(pagerSelector);
        if (!root) return;
        event.preventDefault();
        const url = new URL(form.action || window.location.href, window.location.href);
        url.search = new URLSearchParams(new FormData(form)).toString();
        loadPage(root, url);
    });

    window.addEventListener('popstate', () => {
        const root = document.querySelector(pagerSelector);
        if (!root) return;
        const expectedRoute = root.dataset.route || '';
        if (expectedRoute && routeOf(window.location.href) !== expectedRoute) {
            window.location.reload();
            return;
        }
        loadPage(root, window.location.href, {history: false, scroll: false});
    });
})();
