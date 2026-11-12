(() => {
    const panel = document.getElementById('task-inspector');
    if (!panel) return;
    const content = document.getElementById('inspector-content');
    const error = document.getElementById('inspector-error');
    const retry = document.getElementById('inspector-retry');
    const loading = document.getElementById('inspector-loading');
    const closeButton = document.getElementById('inspector-close');
    let selected = null, project = null, page = 0, contractVersion = null;
    let request = null, sequence = 0, timer = null, source = null, expired = false;
    const validId = value => typeof value === 'string' && /^[a-f0-9-]{36}$/i.test(value);

    function selection() {
        document.dispatchEvent(new CustomEvent('statecan:inspector-selection', {detail: {taskId: selected, projectId: project}}));
    }
    function saveLocation() {
        const url = new URL(location.href);
        if (selected) url.searchParams.set('task', selected);
        else url.searchParams.delete('task');
        // The project/view/page of the surrounding workspace remains untouched.
        history.replaceState(history.state, '', url);
    }
    function stopForSession() {
        if (expired) return;
        expired = true;
        request?.abort();
        clearTimeout(timer);
        error.textContent = 'Your session expired or operator access changed. ';
        const login = document.createElement('a');
        login.href = panel.dataset.loginUrl;
        login.textContent = 'Sign in again';
        error.append(login);
        error.hidden = false;
        retry.hidden = true;
        document.dispatchEvent(new CustomEvent('statecan:session-expired'));
    }
    function capture() {
        return {
            scroll: panel.scrollTop,
            focus: content.contains(document.activeElement) ? document.activeElement.id : null,
            details: new Map([...content.querySelectorAll('details[data-inspector-section]')].map(item => [item.dataset.inspectorSection, item.open]))
        };
    }
    async function refresh({focus = false} = {}) {
        if (!selected || expired) return;
        request?.abort();
        const controller = new AbortController();
        request = controller;
        const current = ++sequence;
        let state = capture();
        content.setAttribute('aria-busy', 'true');
        loading.textContent = content.childElementCount ? 'Refreshing task details…' : 'Loading task…';
        loading.hidden = false;
        retry.hidden = true;
        const url = new URL(panel.dataset.endpointTemplate.replace('00000000-0000-0000-0000-000000000000', selected).replace('__TASK__', selected), location.origin);
        if (project) url.searchParams.set('project', project);
        url.searchParams.set('page', String(page));
        if (contractVersion) url.searchParams.set('contractVersion', String(contractVersion));
        try {
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'text/html'}, signal: controller.signal});
            if ([401, 403].includes(response.status) || response.redirected) { stopForSession(); return; }
            if (!response.ok) throw new Error(response.status === 404 ? 'This task or contract is no longer available in the selected project.' : 'Task details could not be refreshed. The last loaded details are retained.');
            if (!(response.headers.get('content-type') || '').includes('text/html')) throw new Error('Unexpected task response. Reload the console after signing in.');
            const html = await response.text();
            if (current !== sequence || expired || panel.hidden) return;
            // This feature-owned content is server-escaped Twig, fetched from the authorized focused endpoint.
            state = capture();
            content.innerHTML = html;
            content.querySelectorAll('details[data-inspector-section]').forEach(item => {
                if (state.details.has(item.dataset.inspectorSection)) item.open = state.details.get(item.dataset.inspectorSection);
            });
            error.hidden = true;
            if (focus) document.getElementById('inspector-title')?.focus({preventScroll: true});
            else if (state.focus) (document.getElementById(state.focus) || document.getElementById('inspector-pagination'))?.focus({preventScroll: true});
            panel.scrollTop = focus ? 0 : state.scroll;
        } catch (failure) {
            if (failure.name === 'AbortError' || current !== sequence) return;
            error.textContent = failure instanceof TypeError ? 'Connection failed. Task details may be stale; retry when connected.' : failure.message;
            error.hidden = false;
            retry.hidden = false;
        } finally {
            if (current === sequence) {
                content.removeAttribute('aria-busy');
                loading.hidden = true;
            }
        }
    }
    function open(taskId, projectId = null, origin = null) {
        if (!validId(taskId) || (projectId && !validId(projectId))) return;
        if (selected !== taskId) {
            content.replaceChildren();
            panel.scrollTop = 0;
            page = 0;
            contractVersion = null;
            error.hidden = true;
        }
        source = origin || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
        selected = taskId;
        project = projectId;
        panel.dataset.taskId = taskId;
        panel.hidden = false;
        saveLocation();
        selection();
        refresh({focus: true});
    }
    function close() {
        request?.abort();
        ++sequence;
        clearTimeout(timer);
        panel.hidden = true;
        panel.dataset.taskId = '';
        const closedTask = selected;
        selected = project = null;
        saveLocation();
        selection();
        if (source?.isConnected) source.focus({preventScroll: true});
        else {
            const replacement = [...document.querySelectorAll('#observability a[href]')].find(link => new URL(link.href, location.href).searchParams.get('task') === closedTask);
            (replacement || document.getElementById('console-content'))?.focus({preventScroll: true});
        }
    }
    closeButton.addEventListener('click', close);
    retry.addEventListener('click', () => refresh());
    panel.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); close(); } });
    panel.addEventListener('click', event => {
        const button = event.target.closest('[data-inspector-page]');
        if (button) { page = Number(button.dataset.inspectorPage); refresh(); }
    });
    panel.addEventListener('change', event => {
        if (event.target.matches('[data-inspector-contract]')) {
            contractVersion = Number(event.target.value);
            refresh();
        }
    });
    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a');
        if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin) return;
        const taskId = link.dataset.inspectTask || (url.pathname === panel.dataset.dashboardUrl ? url.searchParams.get('task') : null);
        if (!validId(taskId)) return;
        event.preventDefault();
        open(taskId, link.dataset.projectId || url.searchParams.get('project'), link);
    });
    document.addEventListener('statecan:inspect-task', event => open(event.detail?.taskId, event.detail?.projectId));
    document.addEventListener('statecan:session-expired', stopForSession);
    document.addEventListener('statecan:invalidate', event => {
        if (!selected || panel.hidden || expired) return;
        const detail = event.detail || {};
        const owner = content.querySelector('[data-session-id]');
        const related = new Set([...content.querySelectorAll('[data-related-task]')].map(link => link.dataset.relatedTask));
        const relevant = detail.reconcile || ((detail.components || []).includes('inspector') && !(detail.entities || []).length) || (detail.entities || []).some(entity =>
            (entity.type === 'task' && (entity.id === selected || related.has(entity.id))) || entity.taskId === selected ||
            (entity.type === 'project' && entity.id === project) ||
            (entity.type === 'session' && entity.id === owner?.dataset.sessionId) ||
            (entity.type === 'agent' && entity.id === owner?.dataset.agentId)
        );
        if (!relevant) return;
        clearTimeout(timer);
        timer = setTimeout(() => refresh(), 250);
    });
    window.StatecanInspector = {open, close, refresh};
    const initial = new URL(location.href);
    if (validId(panel.dataset.taskId)) {
        // Adopt the authorized server-rendered selection without clearing it or stealing focus.
        selected = panel.dataset.taskId;
        project = validId(panel.dataset.projectId) ? panel.dataset.projectId : null;
        page = Math.max(0, Math.min(10000, Number(panel.dataset.page) || 0));
        contractVersion = Number(panel.dataset.contractVersion) || null;
        selection();
    } else if (validId(initial.searchParams.get('task'))) {
        open(initial.searchParams.get('task'), initial.searchParams.get('project'));
    }
    window.addEventListener('pagehide', () => { request?.abort(); clearTimeout(timer); }, {once: true});
})();
