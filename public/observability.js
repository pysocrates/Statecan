(() => {
    const root = document.getElementById('observability');
    if (!root) return;
    const status = document.getElementById('connection');
    const freshness = document.getElementById('freshness');
    const error = document.getElementById('console-error');
    const labels = {operations: 'Operations', work: 'Work', git: 'Git', history: 'History', runtime: 'Runtime'};
    let view = Object.hasOwn(labels, location.hash.slice(1)) ? location.hash.slice(1) : root.dataset.initialView;
    let updating = false;
    let pending = false;
    let authenticationLost = false;
    function showView(next) {
        if (!Object.hasOwn(labels, next)) return;
        view = next;
        root.querySelectorAll('[data-console-view]').forEach(panel => { panel.hidden = panel.dataset.consoleView !== view; });
        document.querySelectorAll('.console-nav [data-view-link]').forEach(link => {
            if (link.dataset.viewLink === view) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        document.getElementById('view-heading').textContent = labels[view];
        document.title = 'Statecan · ' + labels[view];
    }
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-view-link]');
        if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
            event.preventDefault();
            showView(link.dataset.viewLink);
            history.replaceState(null, '', '#' + view);
        }
        if (event.target.closest('#refresh-now')) refresh();
    });
    window.addEventListener('hashchange', () => showView(location.hash.slice(1)));
    showView(view);

    async function refresh() {
        if (authenticationLost) return;
        if (updating) { pending = true; return; }
        updating = true;
        root.setAttribute('aria-busy', 'true');
        freshness.textContent = 'Refreshing data…';
        try {
            const response = await fetch(root.dataset.fragment, {credentials: 'same-origin', cache: 'no-store'});
            if ([401, 403].includes(response.status) || response.redirected) {
                authenticationLost = true;
                events.close();
                throw new Error('Your session expired or operator access changed. Sign in again from Account.');
            }
            if (!response.ok) throw new Error('Dashboard data is unavailable. The last snapshot is retained; use Refresh data to retry.');
            const html = await response.text();
            // Stage 1 retains the existing fragment transport; component patches follow in Stage 3.
            root.innerHTML = html;
            showView(view);
            error.hidden = true;
            freshness.textContent = 'Data updated ' + new Date().toLocaleTimeString();
        } catch (failure) {
            error.textContent = failure instanceof TypeError ? 'Connection failed. The last snapshot is retained; use Refresh data to retry.' : (failure.message || 'Dashboard data is unavailable. The last snapshot is retained.');
            error.hidden = false;
            freshness.textContent = 'Data may be stale';
        } finally {
            updating = false;
            root.removeAttribute('aria-busy');
            if (pending) { pending = false; refresh(); }
        }
    }
    const events = new EventSource(root.dataset.events);
    events.addEventListener('open', () => { status.textContent = 'Events connected'; refresh(); });
    events.addEventListener('refresh', event => {
        try { if (JSON.parse(event.data).refresh) refresh(); }
        catch (_) { refresh(); }
    });
    events.addEventListener('error', () => { status.textContent = authenticationLost ? 'Session unavailable' : 'Events disconnected · reconnecting'; });
    window.addEventListener('pagehide', () => events.close(), {once: true});
})();
