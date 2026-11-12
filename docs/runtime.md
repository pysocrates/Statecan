# Asynchronous runtime

Requires PostgreSQL, PHP extensions required by Composer plus `pdo_pgsql`, and `pcntl` for graceful worker signal handling. Install with `composer install`, configure DATABASE_URL privately, then run `php bin/console doctrine:migrations:migrate --no-interaction`.

Write an `OutboxEvent` in the same transaction as the business mutation. Its payload contains identifiers and version references only. `php bin/console statecan:outbox:dispatch --limit=100` locks pending rows with SKIP LOCKED, enqueues and marks published atomically on the **same default Doctrine connection**. Do not replace those transports with remote transports without replacing the atomic delivery protocol. The dispatcher is safe to run concurrently and restarts at unpublished rows. Internal callers can pass an optional `eventId` to `OutboxDispatcher::dispatch()` to dispatch only that event; normal batch dispatch is unchanged.

`App\Message\OutboxMessage` carries eventId/type/aggregateId/payload/correlationId. The handler dispatches a Symfony event named exactly `type`; use `#[AsEventListener(event: 'receipt.requested')]` and an `OutboxMessage` argument to handle it. Work already executes on the matching queue: `receipt.*` → receipts, `git.*` → git_indexing, `notification.*` → notifications, `maintenance.*` and `lease.*` → maintenance, others → state_events. Unknown non-task events fail instead of silently disappearing. A task ledger event may legitimately have no projection listeners.

All five queues and the failed queue share `messenger_messages`. Auto-setup is disabled; migrations own the schema. Transport notification polling is disabled so deployments need no database trigger setup. Retries use 1s exponential backoff, 20% jitter, maximum five retries and a 60s delay ceiling. Inspect/retry failures with `php bin/console messenger:failed:show` and `messenger:failed:retry`.

The dispatcher inserts queued job rows in the enqueue transaction. Job middleware records attempts, errors, correlation IDs and status; completed event IDs are suppressed on redelivery. PostgreSQL session advisory locks serialize attempts across workers without holding transactions during external operations. Process termination releases the lock. Messenger redelivers unacknowledged jobs after 300 seconds; run with `--keepalive=30` for longer handlers. A crash after an external effect but before the completion marker can repeat that effect: receipt/Git/notification handlers must implement their own deterministic idempotence. This is at-least-once delivery, not an atomic transaction with external storage. Custom typed messages should implement `App\Runtime\JobMessage` with a stable ID for retry-safe job identity.

## Worker liveness and stalled work

Operator-only GET `/api/v1/operations/workers` reports up to 100 recent worker
instances: unique ID, hostname, PID, subscribed transports, last heartbeat,
last job-boundary progress, current job, completed attempt count, and derived
`idle`, `busy`, `stale`, or `stopped` liveness. Multiple processes and hosts are
tracked independently; restarting creates a new instance rather than reusing a PID.
Historical records are retained, but only fresh, non-stopped workers count toward
queue coverage. The dashboard shows the 25 most recent instances.

Messenger lifecycle listeners register startup, message receipt, success/failure,
idle-loop activity, and graceful shutdown. Idle heartbeats are throttled to once
every 15 seconds by default (less with a short TTL). During long handlers,
Messenger Console keepalive alarms also refresh the heartbeat: run consumers with
`--keepalive=30` and `pcntl`, as the supplied systemd units do. The heartbeat uses
a separate autocommit DB connection with a two-second statement timeout, so it
cannot commit or join a handler's transaction. Each worker needs one additional
database connection. A worker without keepalive alarms may look stale during a
long handler; configure keepalive intervals below the heartbeat TTL. Blocking
native code may defer signal processing; freshness measures observed responsiveness,
not an OS-level guarantee that a PID exists.

Configure these thresholds in the deployment environment (seconds):

| Setting | Default | Meaning |
| --- | --- | --- |
| `STATECAN_WORKER_TTL` | 90 | Maximum heartbeat age for a live worker |
| `STATECAN_JOB_TIMEOUT` | 300 | Maximum processing time before a job is suspected stalled |
| `STATECAN_QUEUE_STALL` | 300 | Maximum ready-message or pending-outbox age |

GET `/api/v1/operations/jobs` includes `worker_id`, `queue_name`, latency, and a
nullable `stall_reason`. Reasons include `runtime_exceeded`, `heartbeat_expired`,
`worker_stopped`, `worker_missing`, and `worker_untracked` (legacy processing rows
without ownership, after the TTL). These are read-time diagnostics: the persisted
job status stays `processing`. A fresh heartbeat proves responsiveness, not useful
handler progress. Job-boundary timestamps and runtime limits are deliberately
separate; long legitimate work may require a higher timeout. No task, lease or
message is automatically failed/requeued by these diagnostics. Normal Messenger
redelivery and idempotency remain responsible for recovery.

GET `/api/v1/operations/queues` adds fresh-worker count, ready-message count,
oldest ready age and backlog-stalled status. Delayed retries are excluded until
they become available. Each of the five normal queues requires fresh coverage;
the `failed` transport deliberately does not. A multi-queue worker covers each
subscribed queue, but does not guarantee fair scheduling under priority starvation.

GET `/api/v1/operations/health` returns HTTP 200 only when healthy, or HTTP 503 when
degraded/unavailable. Degradation includes missing worker coverage even on empty
queues, stalled processing jobs, old ready backlogs, aged unpublished outbox events,
or dead letters. The body reports missing queues, stalled counts/queues, thresholds,
and pending outbox age. A database failure reports liveness `unknown`, not healthy.
The health check is read-only and detection runs when monitoring is queried.

Apply migration `Version20261112060000` before deploying the code and restart
workers so they begin reporting. The migration adds the worker registry, job
ownership/queue columns, and a PostgreSQL notification trigger: lifecycle and
heartbeat changes refresh the live dashboard. Unclean exits remain apparently
fresh until TTL expiry; graceful exits are marked stopped immediately. Deployments
must consume all five queues for a healthy result. Keep endpoint access restricted
to operators; hostname/PID metadata is operational information.

## systemd deployment

The templates in `deploy/systemd` assume a dedicated `statecan` service account, application installation at `/srv/statecan`, and a protected `/etc/statecan/runtime.env` containing deployment DATABASE_URL and APP_SECRET. These are deployment conventions; adapt paths/user via systemd overrides. Provision a writable `var/` for the service account; grant only necessary read access to allowlisted Git repositories. Evidence storage outside `var/` requires an explicit ReadWritePaths override. Do not store secrets in unit files or Git.

After installing units, run `systemctl daemon-reload`, then `systemctl enable --now statecan-outbox.timer statecan-worker@state_events statecan-worker@receipts statecan-worker@git_indexing statecan-worker@notifications statecan-worker@maintenance`. Do not consume `failed` as a normal worker. On deploy run `php bin/console messenger:stop-workers`; systemd restarts workers with fresh code. SIGTERM permits current work to finish; systemd stops it forcibly after 330 seconds if necessary, and Messenger subsequently redelivers. Worker time/memory bounds recycle processes between jobs, while RuntimeMaxSec caps hung processes; each external integration must also set its own operation timeout.

Production Monolog emits JSON to stderr with job_id and correlation_id on runtime lifecycle records. Use the journal or your existing log collector; no extra logging service is required. The timer retries unpublished outbox events after dispatcher process termination. Queue and job records survive worker restarts.

## Minimal recovery smoke test

Against a migrated isolated PostgreSQL test database, run `php bin/phpunit tests/Runtime`. The test dispatches only its own outbox event, moves only its own message to a unique fixture queue, kills a real worker after its handler starts, advances the expired delivery timestamp, starts another worker, and checks completion and duplicate suppression. It does not use SQLite or claim to prove all concurrency cases. When extensions are enabled only with CLI flags, pass the same child-process options as a JSON array in `STATECAN_TEST_PHP_ARGS` (for example `["-d","extension=pdo_pgsql"]`).
