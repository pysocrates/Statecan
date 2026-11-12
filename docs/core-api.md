# Transactional core

Requires PHP >=8.4, Symfony 8.1, PostgreSQL 18 (or a compatible supported PostgreSQL version configured through `serverVersion`) and the PDO PostgreSQL extension. Install dependencies with `composer install`. Set your own `DATABASE_URL` and `APP_SECRET` in ignored `.env.local`; set a separate database URL in `.env.test.local` if desired. Never commit real database credentials. The committed database URL is a placeholder.

Run `php bin/console doctrine:database:create`, then `php bin/console doctrine:migrations:migrate --no-interaction`. Tests use the configured database name plus `_test`; create it and run migrations with `APP_ENV=test` first. Run `php bin/phpunit`. If extensions are installed but disabled, enable them in your PHP environment. No system settings or container runtime are required by this feature.

The two original authentication migrations now branch on database platform: fresh PostgreSQL uses identity columns and `ALTER INDEX`; their original MySQL SQL remains intact for existing MySQL migration histories. This is not an in-place MySQL-to-PostgreSQL data migration. Existing MySQL installations must export/transform/import data into PostgreSQL before enabling the transactional core. The core migration deliberately rejects non-PostgreSQL platforms. Phase 3 owns the subsequent operator role column/provisioning migration; form login and registration remain available.

## HTTP

All routes are under `/api/v1`, require authentication, and return JSON. Read access is global to authenticated application principals; project membership/tenant authorization is not part of this phase. Writes require an explicitly provisioned `ROLE_OPERATOR`; registration does not grant it. Phase 3 supplies agent bearer credentials and fenced command endpoints. Operator task transitions accept form-authenticated operators; bearer requests are routed to the agent endpoint after integration.

Mutations require `Content-Type: application/json` when carrying a payload, plus `Idempotency-Key` (1–128 ASCII letters, digits, `.`, `_`, `:`, `-`). Each operator key is permanently bound to the request method, path and exact body bytes. Replays return the original body/status. Different requests with the same key receive `409`; simultaneous first attempts may receive `409` and should retry with the same key. Replay records are committed with domain changes.

| Method/path | Body/query | Result |
| --- | --- | --- |
| POST /projects | `{"name":"Example"}` | 201 project |
| GET /projects | none | up to 100 active projects |
| GET /projects/{id} | none | project |
| PATCH /projects/{id} | `{"name":"Renamed"}` | updated project |
| DELETE /projects/{id} | none | archived project; requires terminal tasks |
| POST /tasks | `projectId`, `title`, `contract` | 201 task |
| GET /tasks | `project`, `status`, `limit` (max 100), `offset` | tasks |
| GET /tasks/{id} | optional `contractVersion` | task plus current/historical contract |
| PATCH /tasks/{id} | `title`, `expectedVersion`, optional `contract` | revised backlog/blocked task |
| DELETE /tasks/{id} | `expectedVersion` | cancels an unowned task, preserving history |
| POST /tasks/{id}/transition | `target`, `expectedVersion` | operator transition |
| GET /tasks/{id}/events | optional `offset` | up to 100 ordered events |

Core operation errors are `{ "error": "conflict" | "invalid_request" | "invalid_idempotency_key", "message": "..." }`: `409` ownership/version/state/key conflict, `422` invalid domain input, `400` missing/invalid key. Framework mapping/validation uses HTTP `400`/`415`/`422`, missing records use `404`, and insufficient privilege uses `403`. Authentication behavior is supplied by the existing form firewall and Phase 3's bearer firewall. Clients should send `Accept: application/json`.

Example contract:

```json
{"schemaVersion":1,"objective":"Ship the feature","authorizedScope":["src/"],"constraints":[],"dependencies":[],"acceptanceCriteria":["Feature works"],"expectedDeliverables":["Code"],"resourceBudgets":{"seconds":3600}}
```

See `schemas/task-contract-v1.json`. Contracts are immutable snapshots; a revision creates the next contract version and maintains relational dependency rows. Dependencies must exist in the same project. Project row locking serializes dependency edits, and cycles are rejected. Historical snapshots retain their original dependency list.

## Transactions and integration boundaries

`TaskService::create()` and `revise()` and `TaskTransitionService::transition()` own short ORM transactions and may be called within an enclosing DBAL transaction. Task rows use pessimistic write locks with an explicit expected version. State changes increment the task version and insert both `state_event` and `outbox_event` in the same transaction. Task creation/revision also writes a ledger event. Persisted times are generated in UTC; timestamp-without-time-zone fields must be interpreted as UTC.

`TaskTransitionService` is a trusted application boundary, not an agent authorization mechanism. Phase 3 must validate current session/token/fencing under the same task lock before calling it. Operator mutations dispatch `App\Event\OperatorTaskMutation` under that lock; Phase 3's listener rejects live leases, including those on blocked tasks. The HTTP operator endpoint cannot claim, run or finalize tasks. Internal `completed` transitions require `metadata.verifiedReceiptId` after Phase 3 verifies receipt storage and flushes receipt metadata in its enclosing transaction. Database transactions contain no filesystem/network work.

Allowed transitions:

- backlog → ready, blocked, cancelled
- ready → claimed, blocked, cancelled
- claimed → running, ready, blocked, failed, cancelled
- running → ready, blocked, awaiting_review, completion_pending_evidence, failed, cancelled
- blocked → ready, cancelled
- awaiting_review → ready, running, completion_pending_evidence, failed, cancelled
- completion_pending_evidence → completed, failed
- failed → ready, cancelled
- completed/cancelled → terminal

Transitions to ready/claimed/running/pending/completed require completed dependencies. Public operator mutation of leased states is rejected. State/contract history is retained rather than physically deleted.

Outbox rows use string identifiers, `type`, `aggregate_id`, JSONB `payload`, `created_at`, nullable `published_at`. Core emits `task.transitioned` with `{taskId,projectId,version,eventId}`. Phase 2 owns durable dispatch/publication marking. No large contracts or transport implementation details enter those messages.

Phase 1 verification is deliberately minimal: a PostgreSQL lifecycle/transaction test (including outer rollback of task/ledger/outbox), an authenticated task-inspection HTTP happy path, and the existing form-authentication smoke test. It is not a concurrency stress or failure-recovery suite.
