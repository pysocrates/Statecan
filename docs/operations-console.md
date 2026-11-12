# Operations console

The console provides five views: Operations, Work, Git, History and Runtime.
Operations is the default. It uses Twig, responsive CSS and small JavaScript
modules over the existing Symfony read APIs, task engine and operator security.
There is no frontend framework or build step.

## Operations and Work

Operations prioritizes attention tasks and system issue categories, alongside
active work, project-wide task totals, live-lease agents, bounded transition
activity, queue backlog and fresh worker coverage. Task failures, blocked work,
review requests, expired active leases and inconsistent receipts contribute to
the attention task list. System issues link to Runtime.

An active agent is a distinct registered agent with an unexpired lease in the
selected project and a session heartbeat within five minutes. Open tasks exclude
completed and cancelled tasks. Attention counts combine distinct attention tasks
with system issue categories; they are not counts of distinct people or jobs.
Queue backlog includes delayed and in-flight normal messages, excluding the failed
transport. Runtime metrics are application-wide, separately labeled from project
metrics. Worker freshness uses the runtime monitor's TTL.

Totals cover the selected project, independently of the task page. Attention and
running lists contain up to six tasks each, recent task activity eight events.
Work retains 25-task pagination; column counts are project-wide totals.

| Work group | Original statuses |
| --- | --- |
| Ready | backlog, ready |
| In progress | claimed, running, completion_pending_evidence |
| Attention | blocked, awaiting_review, failed |
| Done | completed, cancelled |

Cards keep original status badges. Backlog is not claimable, pending evidence is
not completed, and cancelled is not successful. No drag-and-drop or new state
mutation operation is introduced. Columns scroll vertically and adapt to four,
two or one columns without document-level horizontal scrolling.

## Contextual task inspection

Task links open an inspector while retaining the current view. Native links remain
available for new tabs or a JavaScript-free fallback. The persistent inspector
mount is outside component refresh regions. Direct task links render initial task
details on the server; selected task IDs are retained in the URL.

The inspector presents contract requirements and historical contract versions,
current dependencies, agent/session/lease details, transitions, indexed Git
associations, receipt verification metadata, and related job errors. Structured
fields are primary; raw contract and canonical-evidence JSON are secondary options.
Task histories are bounded to 25 rows per section/page. Lease tokens, credential
hashes, evidence storage paths and queue message bodies are not projected.

Open sections, scroll and keyboard focus survive relevant updates. Unrelated task
changes do not reload the selected inspector. Closing restores focus to a visible
source link or stable workspace fallback. Session expiry stops reads and exposes
an authorized sign-in link. The inspector is read-only: existing agent lease and
operator mutation rules are unchanged.

## Targeted live updates

Native PostgreSQL LISTEN/NOTIFY remains the event transport; no Mercure hub is
required for the current deployment. Migration `Version20261112130000` changes
notifications into bounded table/entity IDs, operation types and revision hints.
Payloads contain no task contracts or canonical domain data. Stream setup validates
context without building the full snapshot, and stream wakeups do not build graphs,
contracts or historical projections.

Notifications invalidate affected components. Clients fetch focused authoritative
reads and reconcile the visible component only. The root dashboard DOM is never
replaced. Stable mounts and keyed reconciliation retain focus, draft controls,
open sections and scroll. The inspector and feature modules own their scoped
content. Hidden views remain dirty until activated; the project selector can update
independently in the background without resetting its selection or focus.

Bursts debounce for 150ms with a maximum 750ms delay. Each component has one active
request plus at most one follow-up; requests abort after 15 seconds. Notification
batches retain at most 128 entities; overflow requests reconciliation. Reconnects
always reconcile authoritative visible state. A 30-second focused refresh of the
visible Operations, Work or Runtime view reevaluates time-derived lease/worker
freshness even if no database write occurs. This does not fetch a full snapshot.
Connection status and data freshness are separate. Failures retain the last view
and remain visible until the failed component recovers. Expired sessions stop
background reads rather than repeatedly loading a login page as console content.

The original `/dashboard/fragment` and `/api/v1/observability/snapshot` endpoints
remain available. Focused operator-only endpoints add:

- `/dashboard/components/{operations|work|projects}` and JSON equivalents under
  `/api/v1/observability/components/`.
- `/dashboard/tasks/{id}/inspector` and its JSON equivalent under
  `/api/v1/observability/tasks/{id}/inspector`.
- `/dashboard/git`, `/dashboard/history`, and JSON equivalents under
  `/api/v1/observability/`.
- `/dashboard/git/commits/{sha}` for selected indexed commit details.
- `/dashboard/runtime` for the focused runtime observation.

See [stream deployment notes](observability.md) for PHP worker/database capacity,
timeouts and session-pooling requirements. Apply migrations and restart workers
when deploying the integrated change.

## Git, History and Runtime

Git offers repository/ref selection, activity/message filters, a commit timeline,
selected commit details, bounded branch graphs and task/session correlations.
Graphs support zoom, pan, fit, and keyboard controls within their own viewport.
Observed refs and trailers retain their provenance: validating identities never
establishes authorship or proves execution. Focused commit reads match the selected
SHA, not incidental text mentions in other commits.

History supports transitions, sessions, receipts, contract revisions and Git
associations, with search, task/status filters and independent pagination. Each
page is bounded to 25 records; graphs to 50 nodes. Pending, verified and inconsistent
evidence remain distinct. Search results and missing bounded correlations do not
claim that no evidence exists elsewhere.

Runtime presents workers, coverage and freshness, queue depth, job attempts/errors,
stall reasons, dead-letter counts and recent outbox references. It does not fabricate
process liveness or retry timestamps. Unknown observation remains unknown, and a
fresh heartbeat does not prove useful progress. Worker/job reads are bounded to 50
and outbox references to 25. Filters operate within these bounded observations.
There are no retry mutation buttons without a corresponding authorized API.

## Verification

Tests use development-only Symfony Panther, Chromium and a matching ChromeDriver
on PATH, alongside an isolated migrated PostgreSQL test database. They use the
normal login, operator authorization, real DB changes and native SSE; no acceptance
credentials or real repository paths are embedded in browser fixtures.

```bash
php bin/phpunit --group browser
php bin/phpunit --exclude-group browser
```

Representative browser checks cover five-view navigation, document overflow at
1440/1024/768/390px, project-wide totals, contextual selection, focus/expansion/scroll
retention, actual scoped invalidations, burst coalescing, network/session recovery,
empty projects, Git graph controls, bounded historical paging, and runtime failures.
See [cross-stage acceptance notes](console-acceptance.md) for measured request-scope
checks and browser port overrides. Screenshots are kept under ignored `var/`.

PHP modules must be enabled for both PHPUnit and Panther's child PHP server. Browser
suites use concurrent PHP workers so SSE does not block other requests. Run parallel
suites on distinct HTTP/ChromeDriver ports. Use `PANTHER_EXTERNAL_BASE_URI` only with
an isolated test server configured for the test database.

## Branch ancestry trees

The Git graph grows top-to-bottom. Observed ref buttons locate branch tips and
highlight their recorded ancestors; forks and merges use actual Git parent links.
Ordinary stretches may collapse into dashed links labeled with the number of
intermediate commits. The view keeps at most 50 commits from a bounded 500-commit
metadata read, preferring ref tips, forks, merges and ancestry boundaries. Missing
parents are marked as outside the current view. Filter by a ref to narrow a large
history. Readable view restores legible node sizes; Fit graph shows the whole tree.
Pan, zoom, selected commit details and live-update state retention remain available.

This works with any registered repository after indexing: local branches, remote
tracking refs and tags are discovered from Git, without agent names or paths in
application configuration. Normal repository sync picks up new refs. A deployment
must have access to those refs in its Git checkout; cloning the application from
GitHub cannot expose private branches held only on another machine. Clone/fetch
policy and repository registration remain deployment configuration. Branch labels
are observations, not verified agent ownership. Existing self-reported task/session
associations remain available in commit details with their confidence labels.
