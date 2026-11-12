(() => {
    let active = null;
    let expired = false;
    let registered = false;
    let filter = 'all';
    const panel = () => document.getElementById('runtime-panel');
    const visible = () => {
        const root = panel();
        return root && !root.closest('[hidden]');
    };
    function applyFilter() {
        const root = panel();
        if (!root) return;
        const select = root.querySelector('#runtime-job-filter');
        if (select) select.value = filter;
        let matches = 0;
        const jobs = root.querySelectorAll('[data-runtime-job]');
        jobs.forEach(job => {
            const attention = ['failed', 'dead_letter'].includes(job.dataset.jobStatus) || job.dataset.jobStalled === 'true';
            job.hidden = !(filter === 'all' || (filter === 'attention' ? attention : job.dataset.jobStatus === filter));
            if (!job.hidden) matches++;
        });
        root.querySelector('#runtime-no-job-matches').hidden = jobs.length === 0 || matches > 0;
    }
    function sessionExpired() {
        expired = true;
        active?.abort();
        const root = panel();
        if (!root) return;
        const error = root.querySelector('#runtime-error');
        error.textContent = 'Your session expired or operator access changed. Runtime data is retained and may be stale.';
        error.hidden = false;
        root.querySelector('#runtime-sign-in').hidden = false;
        root.querySelector('[data-runtime-refresh]').disabled = true;
        root.querySelector('#runtime-refresh-status').textContent = 'Session unavailable · data may be stale';
    }
    async function refresh({signal} = {}) {
        const root = panel();
        if (!root || expired || signal?.aborted) return;
        active?.abort();
        const controller = new AbortController();
        active = controller;
        const abort = () => controller.abort();
        signal?.addEventListener('abort', abort, {once: true});
        root.setAttribute('aria-busy', 'true');
        root.querySelector('[data-runtime-refresh]').setAttribute('aria-disabled', 'true');
        root.querySelector('#runtime-refresh-status').textContent = 'Loading runtime observations…';
        try {
            const response = await fetch(root.dataset.runtimeFragment, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: {'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest'}});
            if (response.redirected || [401, 403].includes(response.status)) {
                document.dispatchEvent(new CustomEvent('statecan:session-expired'));
                sessionExpired();
                return;
            }
            if (!response.ok) throw new Error('Runtime observations are unavailable. Last data is retained; use Refresh runtime to retry.');
            const html = await response.text();
            const next = new DOMParser().parseFromString(html, 'text/html').getElementById('runtime-panel');
            if (!next) throw new Error('invalid response');
            if (controller.signal.aborted || panel() !== root) return;
            // Capture immediately before replacement so interactions during the fetch survive.
            const open = new Set(Array.from(root.querySelectorAll('details[open][data-runtime-detail]'), node => node.dataset.runtimeDetail));
            const scroll = Array.from(root.querySelectorAll('.runtime-list[id]'), node => ({id: node.id, top: node.scrollTop}));
            const focused = root.contains(document.activeElement) ? document.activeElement : null;
            const focusId = focused?.id;
            const focusDetail = focused?.closest('[data-runtime-detail]')?.dataset.runtimeDetail;
            next.querySelectorAll('details[data-runtime-detail]').forEach(node => { node.open = open.has(node.dataset.runtimeDetail); });
            if (registered && window.StatecanConsole?.patch) {
                window.StatecanConsole.patch(root, html);
                // The shared patcher deliberately preserves mount attributes.
                root.dataset.runtimeLoaded = next.dataset.runtimeLoaded;
            } else {
                root.replaceWith(next);
            }
            applyFilter();
            scroll.forEach(item => { const node = document.getElementById(item.id); if (node) node.scrollTop = item.top; });
            if (focusId) document.getElementById(focusId)?.focus({preventScroll: true});
            else if (focusDetail) Array.from(panel().querySelectorAll('[data-runtime-detail]')).find(node => node.dataset.runtimeDetail === focusDetail)?.querySelector('summary')?.focus({preventScroll: true});
            if (!registered) document.dispatchEvent(new CustomEvent('statecan:component-updated', {detail: {component: 'runtime'}}));
            return true;
        } catch (failure) {
            if (controller.signal.aborted) return;
            const error = root.querySelector('#runtime-error');
            error.textContent = 'Runtime observations could not be refreshed. Last data is retained and may be stale. Use Refresh runtime to retry.';
            error.hidden = false;
            root.querySelector('#runtime-refresh-status').textContent = 'Refresh failed · data may be stale';
            throw failure;
        } finally {
            signal?.removeEventListener('abort', abort);
            if (active === controller) {
                active = null;
                const current = panel();
                current?.removeAttribute('aria-busy');
                const button = current?.querySelector('[data-runtime-refresh]');
                if (button) { button.disabled = expired; button.removeAttribute('aria-disabled'); }
            }
        }
    }
    function register() {
        if (!registered && window.StatecanConsole?.register) {
            window.StatecanConsole.register('runtime', {refresh});
            registered = true;
            window.StatecanConsole.invalidate({components: ['runtime']});
        }
        applyFilter();
    }
    document.addEventListener('click', event => {
        if (event.target.closest('[data-runtime-refresh]') && !active) {
            if (registered) window.StatecanConsole.invalidate({components: ['runtime']});
            else refresh().catch(() => {});
        }
        // Supports the preserved shell before Stage3 is integrated, without owning navigation.
        if (!registered && event.target.closest('[data-view-link="runtime"]')) queueMicrotask(() => { if (visible() && panel().dataset.runtimeLoaded !== 'true') refresh().catch(() => {}); });
    });
    document.addEventListener('change', event => {
        if (event.target.id === 'runtime-job-filter') { filter = event.target.value; applyFilter(); }
    });
    document.addEventListener('statecan:view-change', event => {
        register();
        if (!registered && event.detail?.view === 'runtime' && panel()?.dataset.runtimeLoaded !== 'true') refresh().catch(() => {});
    });
    document.addEventListener('statecan:component-updated', register);
    document.addEventListener('statecan:session-expired', sessionExpired);
    window.addEventListener('pagehide', () => active?.abort(), {once: true});
    register();
    if (!registered && visible() && panel()?.dataset.runtimeLoaded !== 'true') refresh().catch(() => {});
})();
