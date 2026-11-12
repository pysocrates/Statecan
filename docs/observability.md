# Human observability

Sign in through the existing form login using an account granted `ROLE_OPERATOR`
(or `ROLE_ADMIN` through the shared role hierarchy), then open `/dashboard`.
Agent bearer credentials do not grant operator permissions. Registration does not
automatically grant operator access; use the existing operator provisioning flow.

The server renders project selection, a paged Kanban board, task/contract inspector,
state transitions, agent session heartbeats, dependency Mermaid source, indexed Git
ledger/graphs, historical receipt verification metadata and global runtime health.
Task and history data come from bounded PostgreSQL read projections; current task
state comes directly from the task row. Each snapshot uses a short repeatable-read,
read-only transaction. Git and runtime views delegate to their owning services.
No Git commands, receipt writes or other external work happen during these reads.

Operator-only routes:

- `GET /dashboard` and `/dashboard/fragment`: complete page and refresh fragment.
- `GET /api/v1/observability/snapshot`: authoritative JSON projection.
- `GET /api/v1/observability/events`: authenticated server-sent notifications.

All accept optional `project` and `task` UUIDs plus a zero-based `page` (0–10000).
A task must belong to the selected project. A missing selection uses the first
project. Histories are bounded to 25 rows, projects to 100; `page` advances histories
and the task board together. Git graph limits are also applied by the Git service.
Missing project/task returns 404; invalid query values return 404 via Symfony's
MapQueryString validation. HTML and JSON are private and must not be proxy cached.
The operator role currently has application-wide visibility; project membership
is not modeled in the existing permissions. Agent/lease secrets are never projected.

SSE uses Symfony `EventStreamResponse` and `ServerEvent`; there is no hub requirement.
Each authenticated connection emits a snapshot revision hash and closes with a
three-second retry hint. This avoids reserving PHP workers for idle streams. Every
reconnect rechecks permissions and refreshes authoritative HTML. `Last-Event-ID`
only suppresses redundant invalidation, never restricts authoritative refresh.
The browser also provides manual refresh, retains the last page during failures,
and records the ten most recent refreshes. Notifications are not a durable event
ledger. The transition timeline, Git ledger and receipt history supply persisted
history after gaps. This is a small-installation SSE implementation; it deliberately
trades a bounded periodic database read for no additional infrastructure. Large
installations can replace notification delivery with Mercure without changing the
snapshot API. Serve via the normal Symfony front controller; static CSS/JS live
under `public/` and require no JavaScript build pipeline.

Worker liveness is displayed exactly as reported by RuntimeMonitor. Queue depth,
job latency and failure status are observations, not proof that workers are alive.
A pending completion attempt is visibly distinct from verified evidence. Displaying
a stored hash does not assert that storage itself is immutable. Mermaid is provided
as escaped source and indexed relationships; no untrusted graph code executes in
the browser.
