# Human observability

Sign in through the existing form login using an account granted `ROLE_OPERATOR`
(or `ROLE_ADMIN` through the shared role hierarchy), then open `/dashboard`.
Agent bearer credentials do not grant operator permissions. The first registered
account on an empty installation automatically receives operator access. Later
accounts require an explicit grant with `app:operator:grant`.

The server renders project selection, a paged Kanban board, task/contract inspector,
state transitions, agent session heartbeats, dependency/provenance diagrams and Mermaid source, indexed Git
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

SSE uses PostgreSQL `LISTEN/NOTIFY` and Symfony `EventStreamResponse`. Run the
notification-trigger migration before deploying this code. Statement triggers on
the projected domain, job, outbox and Messenger tables send an empty invalidation
payload when a transaction commits. Updates made by HTTP requests, workers and
maintenance commands all use the same path. Repeated notifications within a
transaction coalesce; pending notifications are drained before one snapshot read.
No task data, credentials or user input is sent in the PostgreSQL notification.

Each authorized stream creates a dedicated, nonpersistent PDO PostgreSQL connection,
commits LISTEN before reading the initial snapshot, and then blocks waiting for
notifications. The listener never holds an open transaction. A notification causes
a fresh authoritative snapshot; a changed revision produces a refresh event on the
existing HTTP connection. Idle streams send comment heartbeats every ten seconds,
without querying snapshots. Reconnects still refresh authoritative HTML to recover
changes missed while disconnected; notifications are transient, not a durable ledger.
`Last-Event-ID` suppresses an unchanged initial invalidation, not the recovery read.
The browser queues another refresh if an invalidation arrives during an active fetch.

`STATECAN_SSE_DURATION` defaults to 25 seconds (clamped to 1–120). Streams close at
that deadline so reconnects recheck authentication and operator permissions; a role
revocation may take up to that interval to close an existing stream. Retry delay is
three seconds after closure or network failure. Exceptions close the stream and the
browser reconnects. Listener connections close on completion, error or client abort.
The session lock is released before streaming so concurrent fragment reads can run.

Capacity matters: each open stream occupies one PHP worker and one additional
PostgreSQL connection, alongside the normal application connection. Use PHP-FPM or
another server with sufficient concurrency; a single-process PHP development server
will block other requests while streaming. On supported Unix systems, use
`PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t public` for local development.
Proxy buffering must be disabled for SSE (`X-Accel-Buffering: no` is set by Symfony),
and proxy/worker timeouts must exceed the configured stream lifetime. Keep the
lifetime below PHP's execution timeout, or configure that timeout for streaming.
Connect listeners directly to PostgreSQL or through session pooling; transaction
pooling cannot preserve LISTEN registrations. Larger deployments should move the
subscription fanout to a dedicated hub such as Mercure.

Serve via the normal Symfony front controller; static CSS/JS live under `public/`
and require no JavaScript build pipeline. PostgreSQL documents the required
[LISTEN-before-read ordering](https://www.postgresql.org/docs/18/sql-listen.html)
and [transactional notification delivery](https://www.postgresql.org/docs/18/sql-notify.html).

Worker liveness comes from persisted Messenger lifecycle/keepalive heartbeats.
The dashboard displays worker processes, subscribed queues, heartbeat ages, job
progress boundaries and stall reasons. Missing queue coverage, stale owners, overdue
processing, aged ready backlogs and stalled outbox dispatch degrade health; see
[runtime operations](runtime.md) for thresholds and heartbeat requirements. A fresh
heartbeat proves responsiveness, not useful handler progress.
A pending completion attempt is visibly distinct from verified evidence. Displaying
a stored hash does not assert that storage itself is immutable. Mermaid is provided
as escaped source and indexed relationships, with bounded server-rendered SVG
previews of the identifier-only graph subset. No untrusted graph code executes
in the browser. Session timelines include persisted transitions linked by session ID,
and retain historical sessions referenced by transitions or receipts. Receipt
canonical bytes can be inspected at `/api/v1/observability/receipts/{id}`.
