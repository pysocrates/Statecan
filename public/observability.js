(() => {
    'use strict';
    const root = document.getElementById('observability');
    if (!root) return;
    const connection = document.getElementById('connection');
    const freshness = document.getElementById('freshness');
    const error = document.getElementById('console-error');
    const labels = {operations: 'Operations', work: 'Work', git: 'Git', history: 'History', runtime: 'Runtime'};
    const names = [...Object.keys(labels), 'inspector', 'projects'];
    const handlers = new Map();
    const dirty = new Map();
    const running = new Map();
    const failures = new Map();
    const pendingEntities = new Map();
    const pendingComponents = new Set();
    const controller = new AbortController();
    let view = Object.hasOwn(labels, location.hash.slice(1)) ? location.hash.slice(1) : (root.dataset.initialView || 'operations');
    let reconcilePending = false;
    let timer;
    let burstStarted = 0;
    let stopped = false;
    let events;

    function emit(name, detail) { document.dispatchEvent(new CustomEvent(name, {detail})); }
    function report(message) {
        error.textContent = message;
        error.hidden = false;
        freshness.textContent = 'Data may be stale';
    }
    function showFailure() {
        if (stopped) return;
        const message = failures.get(view) || failures.get('projects');
        if (message) report(message);
        else error.hidden = true;
    }
    function expire() {
        if (stopped) return;
        stopped = true;
        events?.close();
        controller.abort();
        clearTimeout(timer);
        dirty.clear();
        pendingEntities.clear();
        pendingComponents.clear();
        connection.textContent = 'Session unavailable';
        report('Your session expired or operator access changed. Sign in again from Account.');
        const account = document.querySelector('.account-link');
        if (root.dataset.loginUrl || account) {
            const link = document.createElement('a');
            link.href = root.dataset.loginUrl || account.href;
            link.textContent = root.dataset.loginUrl ? 'Sign in' : 'Open Account';
            error.append(' ', link);
        }
        emit('statecan:session-expired', {});
    }
    document.addEventListener('statecan:session-expired', expire);

    async function request(url, options = {}) {
        if (stopped) throw new Error('Session unavailable.');
        const target = new URL(url, location.href);
        if (target.origin !== location.origin) throw new Error('Console reads must use the current origin.');
        const response = await fetch(target, {credentials: 'same-origin', cache: 'no-store', ...options, signal: options.signal || controller.signal});
        if ([401, 403].includes(response.status) || response.redirected) {
            expire();
            throw new Error('Session unavailable.');
        }
        if (!response.ok) throw new Error('Dashboard data is unavailable. The last snapshot is retained; use Refresh data to retry.');
        return response;
    }

    function key(node) {
        if (node.nodeType !== Node.ELEMENT_NODE) return null;
        if (node.id) return 'id:' + node.id;
        if (node.dataset.consoleKey) return 'key:' + node.dataset.consoleKey;
        if (node.dataset.entityId) return 'entity:' + node.dataset.entityType + ':' + node.dataset.entityId;
        for (const attribute of ['data-runtime-detail', 'data-inspector-section']) {
            if (node.hasAttribute(attribute)) return attribute + ':' + node.getAttribute(attribute);
        }
        if (node.matches('a[href]')) return 'link:' + node.getAttribute('href') + ':' + node.textContent.trim();
        if (node.matches('input[name],select[name],textarea[name]')) return node.tagName + ':' + node.name;
        if (node.matches('details')) return 'details:' + (node.querySelector(':scope > summary')?.textContent.trim() || '');
        return null;
    }
    function compatible(a, b) { return a && a.nodeType === b.nodeType && a.nodeName === b.nodeName && key(a) === key(b); }
    function morph(current, incoming) {
        if (current.nodeType !== Node.ELEMENT_NODE) {
            if (current.nodeValue !== incoming.nodeValue) current.nodeValue = incoming.nodeValue;
            return;
        }
        if (current.matches('[data-console-preserve],#task-inspector')) return;
        const editing = current === document.activeElement;
        const selection = current.matches('select') ? current.value : null;
        for (const attribute of [...current.attributes]) {
            if (attribute.name === 'open' && current.matches('details')) continue;
            if (editing && ['value', 'checked'].includes(attribute.name)) continue;
            if (!incoming.hasAttribute(attribute.name)) current.removeAttribute(attribute.name);
        }
        for (const attribute of incoming.attributes) {
            if (attribute.name === 'open' && current.matches('details')) continue;
            if (editing && ['value', 'checked'].includes(attribute.name)) continue;
            if (current.getAttribute(attribute.name) !== attribute.value) current.setAttribute(attribute.name, attribute.value);
        }
        const existing = [...current.childNodes];
        const keyed = new Map(existing.filter(node => key(node)).map(node => [key(node), node]));
        const retained = new Set();
        let cursor = current.firstChild;
        for (const fresh of incoming.childNodes) {
            const candidate = key(fresh) ? keyed.get(key(fresh)) : cursor;
            const node = compatible(candidate, fresh) && !retained.has(candidate) ? candidate : fresh.cloneNode(true);
            if (node !== cursor) current.insertBefore(node, cursor);
            if (node === candidate) morph(node, fresh);
            retained.add(node);
            cursor = node.nextSibling;
        }
        for (const child of [...current.childNodes]) {
            if (!retained.has(child) && !(child.nodeType === Node.ELEMENT_NODE && child.matches('[data-console-preserve],#task-inspector'))) child.remove();
        }
        if (selection !== null && [...current.options].some(option => option.value === selection)) current.value = selection;
    }

    function patch(component, html) {
        const mount = typeof component === 'string' ? root.querySelector(`[data-console-component="${component}"]`) : component;
        if (!mount) throw new Error('Console component is unavailable. Refresh the page to load the current console.');
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const incoming = parsed.querySelector(`[data-console-component="${mount.dataset.consoleComponent}"]`) || (mount.id ? parsed.getElementById(mount.id) : null);
        const source = incoming || parsed.body;
        const active = mount.contains(document.activeElement) ? document.activeElement : null;
        const activeKey = key(active || document.createTextNode(''));
        const caret = active && typeof active.selectionStart === 'number' ? [active.selectionStart, active.selectionEnd] : null;
        const scroll = [mount, ...mount.querySelectorAll('*')].filter(node => node.scrollTop || node.scrollLeft).map(node => ({node, key: key(node), top: node.scrollTop, left: node.scrollLeft}));
        // Preserve the stable mount and the view's hidden state; reconcile children only.
        const shell = mount.cloneNode(false);
        shell.replaceChildren(...[...source.childNodes].map(node => node.cloneNode(true)));
        morph(mount, shell);
        for (const position of scroll) {
            const node = position.node.isConnected ? position.node : [...mount.querySelectorAll('*')].find(candidate => key(candidate) === position.key && position.key);
            if (node) { node.scrollTop = position.top; node.scrollLeft = position.left; }
        }
        if (active) {
            const replacement = active.isConnected ? active : [...mount.querySelectorAll('a,button,input,select,textarea,[tabindex]')].find(node => activeKey && key(node) === activeKey);
            if (replacement && (document.activeElement === document.body || document.activeElement === active || !active.isConnected)) {
                replacement.focus({preventScroll: true});
                if (caret && replacement.setSelectionRange) replacement.setSelectionRange(...caret);
            }
        }
        return mount;
    }

    function componentUrl(name) {
        const mount = root.querySelector(`[data-console-component="${name}"]`);
        if (mount?.dataset.componentUrl) return mount.dataset.componentUrl;
        const url = new URL(root.dataset.fragment || location.href, location.href);
        url.pathname = url.pathname.replace(/\/dashboard(?:\/fragment)?\/?$/, '/dashboard/components/' + name);
        url.searchParams.delete('task');
        return url;
    }
    for (const name of ['operations', 'work']) {
        handlers.set(name, {refresh: async ({signal}) => {
            const response = await request(componentUrl(name), {signal});
            patch(name, await response.text());
        }});
    }

    handlers.set('projects', {refresh: async ({signal}) => {
        const select = document.getElementById('project-select');
        if (!select) return;
        const url = new URL(componentUrl('projects'), location.href);
        url.pathname = url.pathname.replace(/\/dashboard\/components\/projects$/, '/api/v1/observability/components/projects');
        const response = await request(url, {signal});
        const data = await response.json();
        const empty = document.getElementById('console-no-projects');
        if (empty) empty.hidden = data.projects.length > 0;
        const next = select.cloneNode(false);
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Select project';
        next.append(placeholder);
        for (const project of data.projects) {
            const option = document.createElement('option');
            option.value = project.id;
            option.textContent = project.name;
            next.append(option);
        }
        morph(select, next);
    }});

    async function refreshComponent(name) {
        if (stopped || (name !== view && name !== 'projects') || !dirty.has(name) || !handlers.has(name) || running.has(name)) return;
        const context = dirty.get(name);
        dirty.delete(name);
        const mount = root.querySelector(`[data-console-component="${name}"]`);
        mount?.setAttribute('aria-busy', 'true');
        if (name === view) freshness.textContent = 'Refreshing ' + labels[name] + '…';
        const timeout = AbortSignal.timeout(15000);
        const task = Promise.resolve().then(() => handlers.get(name).refresh({...context, component: name, signal: AbortSignal.any([controller.signal, timeout])}));
        running.set(name, task);
        try {
            await task;
            if (stopped) return;
            failures.delete(name);
            if (name === view || name === 'projects') showFailure();
            if (name === view && !failures.has('projects')) freshness.textContent = labels[name] + ' updated ' + new Date().toLocaleTimeString();
            emit('statecan:component-updated', {component: name});
        } catch (failure) {
            if (!stopped) {
                const message = failure instanceof TypeError ? 'Connection failed. The last snapshot is retained; use Refresh data to retry.' : (failure.message || 'Component refresh failed.');
                failures.set(name, name === 'projects' ? 'Project choices could not be refreshed. The current selection is retained; use Refresh data to retry.' : message);
                if (name === view || name === 'projects') showFailure();
            }
        } finally {
            running.delete(name);
            mount?.removeAttribute('aria-busy');
            // A burst during an in-flight request schedules exactly one follow-up.
            if (dirty.has(name) && !stopped) queueMicrotask(() => refreshComponent(name));
        }
    }

    function flush() {
        timer = null;
        burstStarted = 0;
        if (stopped) return;
        const payload = {components: [...pendingComponents], entities: [...pendingEntities.values()], reconcile: reconcilePending};
        pendingComponents.clear();
        pendingEntities.clear();
        reconcilePending = false;
        for (const component of payload.components) {
            const previous = dirty.get(component);
            dirty.set(component, {entities: payload.entities, reconcile: payload.reconcile || previous?.reconcile || false});
        }
        emit('statecan:invalidate', payload);
        refreshComponent(view);
        refreshComponent('projects');
    }
    function invalidate(payload = {}) {
        if (stopped) return;
        if (payload.reconcile) reconcilePending = true;
        for (const name of payload.reconcile ? names : (payload.components || [])) if (names.includes(name)) pendingComponents.add(name);
        for (const entity of (payload.entities || []).slice(0, 128)) {
            if (!entity || typeof entity.id !== 'string' || typeof entity.type !== 'string') continue;
            pendingEntities.set(entity.type + ':' + entity.id, entity);
            if (pendingEntities.size > 128) {
                pendingEntities.clear();
                reconcilePending = true;
                names.forEach(name => pendingComponents.add(name));
                break;
            }
        }
        if (!burstStarted) burstStarted = performance.now();
        clearTimeout(timer);
        timer = setTimeout(flush, Math.min(150, Math.max(0, 750 - (performance.now() - burstStarted))));
    }
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
        showFailure();
        refreshComponent(view);
        emit('statecan:view-change', {view});
    }
    window.StatecanConsole = {
        register(name, handler) {
            if (!Object.hasOwn(labels, name) || typeof handler?.refresh !== 'function') throw new Error('Invalid console component registration.');
            handlers.set(name, handler);
            refreshComponent(name);
        },
        invalidate, patch, request,
        refresh: () => invalidate({reconcile: true}),
        get view() { return view; },
    };
    document.addEventListener('click', event => {
        const link = event.target.closest('[data-view-link]');
        if (link && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
            event.preventDefault();
            showView(link.dataset.viewLink);
            history.replaceState(null, '', '#' + view);
        }
        if (event.target.closest('#refresh-now')) invalidate({reconcile: true});
    });
    window.addEventListener('hashchange', () => showView(location.hash.slice(1)));
    showView(view);
    events = new EventSource(root.dataset.events);
    events.addEventListener('open', () => { if (!stopped) connection.textContent = 'Events connected'; });
    events.addEventListener('refresh', event => {
        try {
            const payload = JSON.parse(event.data);
            if (payload.refresh) invalidate(payload.components ? payload : {reconcile: true});
        } catch (_) { invalidate({reconcile: true}); }
    });
    events.addEventListener('error', () => {
        if (stopped) return;
        connection.textContent = 'Events disconnected · reconnecting';
        // EventSource hides HTTP status. A focused authorized read detects expiry.
        invalidate({components: [view]});
    });
    // Time-derived lease/worker freshness can change without a database write.
    const freshnessTimer = setInterval(() => {
        if (!document.hidden && ['operations', 'work', 'runtime'].includes(view)) invalidate({components: [view]});
    }, 30000);
    window.addEventListener('pagehide', () => {
        events.close();
        controller.abort();
        clearTimeout(timer);
        clearInterval(freshnessTimer);
    }, {once: true});
})();
