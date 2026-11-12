(() => {
    const root = document.getElementById('observability');
    if (!root) return;
    const status = document.getElementById('connection');
    let updating = false;
    let pending = false;
    const history = [];
    async function refresh() {
        if (updating) { pending = true; return; }
        updating = true;
        try {
            const response = await fetch(root.dataset.fragment, {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok || response.redirected) throw new Error('Session or read unavailable');
            const html = await response.text();
            // HTML is rendered and escaped by Twig, never assembled from notification data.
            root.innerHTML = html;
            history.unshift(new Date().toLocaleTimeString() + ' — authoritative state refreshed');
            history.splice(10);
            const log = document.getElementById('activity-log');
            for (const message of history) {
                const item = document.createElement('li');
                item.textContent = message;
                log.append(item);
            }
            status.textContent = 'Live · refreshed ' + new Date().toLocaleTimeString();
        } catch (_) {
            status.textContent = 'Refresh unavailable · retrying';
        } finally {
            updating = false;
            if (pending) {
                pending = false;
                refresh();
            }
        }
    }
    root.addEventListener('click', event => {
        if (event.target.id === 'refresh-now') refresh();
    });
    const events = new EventSource(root.dataset.events);
    events.addEventListener('open', refresh); // Reconnect always recovers authoritative state.
    events.addEventListener('refresh', event => {
        try {
            if (JSON.parse(event.data).refresh) refresh();
        } catch (_) { refresh(); }
    });
    events.addEventListener('error', () => { status.textContent = 'Reconnecting · last snapshot retained'; });
    window.addEventListener('pagehide', () => events.close(), {once: true});
})();
