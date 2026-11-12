(() => {
    const states = new Map();
    let expired = false;
    const names = ['git', 'history'];
    const panel = name => document.querySelector(`[data-console-component="${name}"]`);
    const visible = node => node && !node.closest('[data-console-view][hidden]');
    function stateFor(name, node) {
        let state = states.get(name);
        if (!state || state.project !== node.dataset.projectId) {
            state?.controller?.abort();
            state?.commitController?.abort();
            state = {project: node.dataset.projectId, applied: node.dataset.componentUrl, sequence: 0, selected: null};
            states.set(name, state);
        }
        return state;
    }
    function failure(node, message) {
        const error = node.querySelector('[data-review-error]');
        if (error) { error.textContent = message; error.hidden = false; }
    }
    function sessionLost() {
        if (expired) return;
        document.dispatchEvent(new CustomEvent('statecan:session-expired'));
    }
    function capture(node) {
        const focus = document.activeElement;
        let selector = null;
        if (node.contains(focus)) {
            if (focus.id) selector = '#' + CSS.escape(focus.id);
            else if (focus.matches('summary')) selector = `[data-review-detail="${CSS.escape(focus.parentElement.dataset.reviewDetail || '')}"] > summary`;
            else for (const key of ['data-graph-zoom', 'data-graph-fit', 'data-git-commit', 'data-review-refresh', 'data-review-page']) {
                if (focus.hasAttribute(key)) { selector = `[${key}="${CSS.escape(focus.getAttribute(key))}"]`; break; }
            }
        }
        return {
            open: [...node.querySelectorAll('details[open][data-review-detail]')].map(d => d.dataset.reviewDetail),
            scroll: [...node.querySelectorAll('[data-review-scroll]')].map(n => [n.dataset.reviewScroll, n.scrollTop, n.scrollLeft]),
            values: [...node.querySelectorAll('[data-review-form] input,[data-review-form] select')].map(n => [n.name, n.value]),
            focus: selector,
            range: focus?.matches('input[type=search],input:not([type])') ? [focus.selectionStart, focus.selectionEnd] : null,
            graph: node.querySelector('[data-git-graph]')?.getAttribute('viewBox')
        };
    }
    function restore(node, saved, live) {
        saved.open.forEach(key => { const detail = node.querySelector(`[data-review-detail="${CSS.escape(key)}"]`); if (detail) detail.open = true; });
        saved.scroll.forEach(([key, top, left]) => { const target = node.querySelector(`[data-review-scroll="${CSS.escape(key)}"]`); if (target) { target.scrollTop = top; target.scrollLeft = left; } });
        if (live) saved.values.forEach(([name, value]) => { const field = node.querySelector(`[data-review-form] [name="${CSS.escape(name)}"]`); if (field) field.value = value; });
        if (live && saved.graph) node.querySelector('[data-git-graph]')?.setAttribute('viewBox', saved.graph);
        const focus = saved.focus && node.querySelector(saved.focus);
        if (focus) { focus.focus({preventScroll: true}); if (saved.range) focus.setSelectionRange?.(...saved.range); }
    }
    async function refresh(name, {signal, explicit = false, params = null} = {}) {
        const node = panel(name);
        if (!node || expired) return;
        const state = stateFor(name, node);
        state.controller?.abort();
        const controller = new AbortController();
        state.controller = controller;
        const abort = () => controller.abort();
        if (signal?.aborted) controller.abort();
        signal?.addEventListener('abort', abort, {once: true});
        if (explicit) state.commitController?.abort();
        const sequence = ++state.sequence;
        const url = new URL(params ? node.dataset.componentUrl : state.applied, location.href);
        if (params) url.search = params.toString();
        node.setAttribute('aria-busy', 'true');
        node.querySelector('[data-review-status]').textContent = 'Reading authoritative records…';
        try {
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
            if ([401, 403].includes(response.status) || response.redirected) { sessionLost(); return; }
            if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) throw new Error('Records could not be read. Previous data is retained; use Refresh to retry.');
            const html = await response.text();
            if (sequence !== state.sequence || !node.isConnected) return;
            const incoming = new DOMParser().parseFromString(html, 'text/html').querySelector(`[data-console-component="${name}"]`);
            if (!incoming) throw new Error('Unexpected response. Previous data is retained; use Refresh to retry.');
            const saved = capture(node);
            const selection = node.querySelector('[data-git-selection]');
            const selectionCopy = selection && !selection.hidden ? selection.cloneNode(true) : null;
            node.replaceChildren(...incoming.childNodes);
            node.dataset.reviewLoaded = 'true';
            state.applied = url.href;
            if (selectionCopy && !explicit) node.querySelector('[data-git-selection]')?.replaceWith(selectionCopy);
            if (explicit) state.selected = null;
            restore(node, saved, !explicit);
            node.querySelector('[data-review-status]').textContent = `Read completed ${new Date().toLocaleTimeString()} · bounded indexed records`;
            node.dispatchEvent(new CustomEvent('statecan:review-updated', {bubbles: true, detail: {component: name}}));
            if (state.selected && !explicit) loadCommit(node, state.selected, false);
            return true;
        } catch (error) {
            if (error.name !== 'AbortError' && sequence === state.sequence) {
                failure(node, error instanceof TypeError ? 'Connection failed. Previous data is retained; use Refresh to retry.' : error.message);
                node.querySelector('[data-review-status]').textContent = 'Read failed · data may be stale';
                return false;
            }
        } finally {
            signal?.removeEventListener('abort', abort);
            if (sequence === state.sequence) node.removeAttribute('aria-busy');
        }
    }
    async function loadCommit(node, sha, moveFocus = true) {
        if (expired || !/^[a-f0-9]{40,64}$/.test(sha)) return;
        const state = stateFor('git', node);
        state.commitController?.abort();
        const controller = new AbortController();
        state.commitController = controller;
        state.selected = sha;
        const section = node.querySelector('[data-git-selection]');
        const url = new URL(node.dataset.commitBase + sha, location.href);
        const repository = new URL(state.applied, location.href).searchParams.get('repository') || node.querySelector('[name=repository]')?.value;
        if (repository) url.searchParams.set('repository', repository);
        if (state.project) url.searchParams.set('project', state.project);
        section.hidden = false;
        section.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
            if ([401, 403].includes(response.status) || response.redirected) { sessionLost(); return; }
            if (!response.ok) throw new Error('Commit details are unavailable. Select the commit again to retry.');
            const html = await response.text();
            if (!section.isConnected || state.selected !== sha) return;
            const saved = capture(section);
            const parsed = new DOMParser().parseFromString(html, 'text/html').querySelector('.git-commit-detail');
            if (!parsed) throw new Error('Commit details could not be read.');
            section.querySelector('[data-git-selected-content]').replaceChildren(parsed);
            restore(section, saved, false);
            if (moveFocus) { section.querySelector('h3').focus({preventScroll: true}); section.scrollIntoView({block: 'nearest'}); }
        } catch (error) {
            if (error.name !== 'AbortError') failure(node, error.message || 'Commit details could not be read.');
        } finally { section.removeAttribute('aria-busy'); }
    }
    function viewBox(svg) { return svg.getAttribute('viewBox').split(/\s+/).map(Number); }
    function zoom(svg, factor) {
        const [x, y, width, height] = viewBox(svg);
        const next = Math.max(80, Math.min(Number(svg.dataset.graphWidth) * 8, width * factor));
        const ratio = next / width;
        svg.setAttribute('viewBox', [x + (width - next) / 2, y + (height - height * ratio) / 2, next, height * ratio].join(' '));
    }
    document.addEventListener('submit', event => {
        const form = event.target.closest('[data-review-form]');
        if (!form) return;
        event.preventDefault();
        form.elements.page.value = '0';
        refresh(form.closest('[data-console-component]').dataset.consoleComponent, {explicit: true, params: new URLSearchParams(new FormData(form))});
    });
    document.addEventListener('change', event => {
        if (event.target.id === 'git-repository') { const form = event.target.form; form.elements.branch.value = ''; form.requestSubmit(); }
        if (event.target.id === 'history-kind') { const form = event.target.form; form.elements.status.value = ''; form.requestSubmit(); }
    });
    document.addEventListener('click', event => {
        const node = event.target.closest('.review-panel[data-console-component]');
        if (!node) {
            if (event.target.closest('[data-view-link]')) queueMicrotask(ensureVisible);
            return;
        }
        if (event.target.closest('[data-review-refresh]')) { refresh(node.dataset.consoleComponent); return; }
        const page = event.target.closest('[data-review-page]');
        if (page && !page.disabled) {
            const params = new URLSearchParams(new URL(stateFor(node.dataset.consoleComponent, node).applied, location.href).search);
            params.set('page', page.dataset.reviewPage);
            refresh(node.dataset.consoleComponent, {explicit: true, params});
        }
        const svg = node.querySelector('[data-git-graph]');
        const zoomButton = event.target.closest('[data-graph-zoom]');
        if (zoomButton && svg) zoom(svg, zoomButton.dataset.graphZoom === 'in' ? .8 : 1.25);
        if (event.target.closest('[data-graph-fit]') && svg) svg.setAttribute('viewBox', `0 0 ${svg.dataset.graphWidth} ${svg.dataset.graphHeight}`);
        const commit = event.target.closest('[data-git-commit]');
        if (commit && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) { event.preventDefault(); if (!stateFor('git', node).dragged) loadCommit(node, commit.dataset.gitCommit); }
        if (event.target.closest('[data-git-close]')) { node.querySelector('[data-git-selection]').hidden = true; const state = stateFor('git', node); state.selected = null; state.commitController?.abort(); }
        const task = event.target.closest('[data-inspect-task]');
        if (task && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && window.StatecanInspector) {
            event.preventDefault();
            document.dispatchEvent(new CustomEvent('statecan:inspect-task', {detail: {taskId: task.dataset.inspectTask, projectId: task.dataset.projectId}}));
        }
    });
    document.addEventListener('keydown', event => {
        const area = event.target.closest('.git-graph-viewport');
        const svg = area?.querySelector('[data-git-graph]');
        if (!svg || event.target !== area || !['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','+','-','Home'].includes(event.key)) return;
        event.preventDefault();
        if (event.key === '+' || event.key === '-') { zoom(svg, event.key === '+' ? .8 : 1.25); return; }
        if (event.key === 'Home') { svg.setAttribute('viewBox', `0 0 ${svg.dataset.graphWidth} ${svg.dataset.graphHeight}`); return; }
        const box = viewBox(svg); const step = box[2] * .1;
        box[0] += event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0;
        box[1] += event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0;
        svg.setAttribute('viewBox', box.join(' '));
    });
    document.addEventListener('pointerdown', event => {
        const area = event.target.closest('.git-graph-viewport');
        const svg = area?.querySelector('[data-git-graph]');
        if (!svg || event.button !== 0) return;
        const state = stateFor('git', area.closest('[data-console-component]'));
        state.dragged = false;
        const box = viewBox(svg); const inverse = svg.getScreenCTM().inverse();
        const start = new DOMPoint(event.clientX, event.clientY).matrixTransform(inverse);
        const origin = [event.clientX, event.clientY];
        const move = current => {
            const point = new DOMPoint(current.clientX, current.clientY).matrixTransform(inverse);
            if (Math.abs(current.clientX - origin[0]) + Math.abs(current.clientY - origin[1]) > 5) state.dragged = true;
            svg.setAttribute('viewBox', [box[0] - (point.x - start.x), box[1] - (point.y - start.y), box[2], box[3]].join(' '));
        };
        const stop = () => { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', stop); document.removeEventListener('pointercancel', stop); };
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', stop, {once: true});
        document.addEventListener('pointercancel', stop, {once: true});
    });
    function ensureVisible() {
        names.forEach(name => { const node = panel(name); if (visible(node) && node.dataset.reviewLoaded !== 'true' && !node.hasAttribute('aria-busy')) refresh(name); });
    }
    names.forEach(name => window.StatecanConsole?.register(name, {refresh: async options => {
        const success = await refresh(name, options);
        if (success === false) throw new Error('Focused ' + name + ' data could not be refreshed.');
    }}));
    document.addEventListener('statecan:view-change', ensureVisible);
    document.addEventListener('statecan:component-updated', ensureVisible);
    document.addEventListener('statecan:session-expired', () => {
        expired = true;
        states.forEach(state => { state.controller?.abort(); state.commitController?.abort(); });
        names.forEach(name => { const node = panel(name); if (node) { failure(node, 'Session expired or operator access changed. Sign in again using Account.'); node.querySelector('[data-review-status]').textContent = 'Session unavailable · automatic reads stopped'; } });
    });
    window.addEventListener('hashchange', ensureVisible);
    window.addEventListener('pagehide', () => states.forEach(state => { state.controller?.abort(); state.commitController?.abort(); }), {once: true});
    ensureVisible();
})();
