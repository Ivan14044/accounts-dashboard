/** Refresh server-rendered lists without replaying mutations or reloading the page. */
(function () {
    let pending = null;

    window.refreshAccountList = function (kind) {
        if (pending) return pending;
        pending = refresh(kind).finally(() => { pending = null; });
        return pending;
    };

    async function refresh(kind) {
        const selector = 'main[data-list-page="' + kind + '"]';
        const current = document.querySelector(selector);
        if (!current) return false;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        const scroll = [window.scrollX, window.scrollY];
        const tableScroll = Array.from(current.querySelectorAll('.dashboard-table__scroll')).map(el => [el.scrollLeft, el.scrollTop]);
        current.setAttribute('aria-busy', 'true');
        current.inert = true;
        try {
            const response = await fetch(window.location.href, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-List-Refresh': '1' }
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = parsed.querySelector(selector);
            if (!next || next.dataset.listError === 'true') throw new Error('Некорректный ответ списка');
            const config = kind === 'trash' ? JSON.parse(next.dataset.listConfig) : null;
            // Imported scripts are deliberately not executed. Only list markup is replaced.
            next.querySelectorAll('script').forEach(script => script.remove());
            if (config) window.TrashConfig = config;
            current.replaceWith(next);
            const url = new URL(window.location.href);
            if (url.searchParams.has('page') || Number(next.dataset.page) > 1) {
                url.searchParams.set('page', next.dataset.page);
                window.history.replaceState(null, '', url);
            }
            next.querySelectorAll('.dashboard-table__scroll').forEach((el, i) => {
                if (tableScroll[i]) [el.scrollLeft, el.scrollTop] = tableScroll[i];
            });
            window.scrollTo({ left: scroll[0], top: scroll[1], behavior: 'instant' });
            window.dispatchEvent(new CustomEvent('account-list:updated', { detail: { kind } }));
            if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && next.animate) {
                next.animate([{ opacity: 0.7 }, { opacity: 1 }], { duration: 180, easing: 'ease-out' });
            }
            document.getElementById('listRefreshRetry')?.remove();
            return true;
        } catch (error) {
            // The write may already have succeeded. Never offer to replay it automatically.
            document.getElementById('listRefreshRetry')?.remove();
            const notice = document.createElement('div');
            notice.id = 'listRefreshRetry';
            notice.className = 'alert alert-warning position-fixed bottom-0 start-50 translate-middle-x shadow';
            notice.style.zIndex = '1090';
            notice.setAttribute('role', 'alert');
            const text = document.createElement('span');
            text.textContent = 'Не удалось обновить список. Данные могут быть устаревшими. ';
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn btn-sm btn-outline-dark';
            retry.textContent = 'Обновить список';
            retry.addEventListener('click', () => window.refreshAccountList(kind));
            notice.append(text, retry);
            document.body.append(notice);
            return false;
        } finally {
            clearTimeout(timeout);
            current.removeAttribute('aria-busy');
            current.inert = false;
        }
    }
})();
