# Agent API and receipts

Run migrations, then register a form user normally and explicitly grant operator
access with `php bin/console app:operator:grant user@example.org`. Public
registration grants only ROLE_USER. Operator access uses the existing form login;
agents use a separate stateless bearer firewall and never receive operator roles.
Provision agent credentials with `php bin/console app:agent:create 'build agent'`.
The token is displayed once; store it securely. Only its SHA-256 digest is persisted.
Revoke by deleting the agent's credential through trusted database administration
(or replacing its digest); there is no public credential enrollment endpoint.

Send `Authorization: Bearer <token>` for agent requests and a unique
`Idempotency-Key` (1–128 ASCII letters/digits/period/underscore/colon/hyphen) on
all POSTs. Replays with identical input return the original response; reusing a
key with different input returns 409. Keys are scoped to the agent across all
operations. Use `Content-Type: application/json` for task commands.

1. `POST /api/v1/agents/sessions` returns 201 with id/agentId/timestamps.
2. `GET /api/v1/agents/sessions/{id}` reads your own session.
   `POST .../{id}/heartbeat` updates its heartbeat.
3. `POST /api/v1/tasks/{id}/claim` accepts sessionId, expectedVersion and a
   client-generated cryptographically random token of 32–128 characters. Only
   ready tasks can be claimed. Store the returned generation and task version.
4. `POST .../{id}/heartbeat` accepts sessionId, token, generation, expectedVersion
   and renews the lease for five minutes. Session heartbeat also updates.
5. `POST .../{id}/transition` accepts those fields plus target: running, blocked,
   awaiting_review, failed or ready (release). Core state/dependency rules apply.
6. `POST .../{id}/complete` accepts those fields plus result (nonempty string)
   and evidence (up to 100 string references). Returns 202 with receiptId,
   pending status and taskVersion after transactionally writing the receipt
   attempt and receipt.requested outbox event. Tasks remain
   completion_pending_evidence until stored evidence is verified.
7. `GET .../{id}/receipts` returns up to 100 newest receipt metadata records.

Leases use a unique task row plus monotonically increasing generations. Task row
locks serialize claims and transitions. Expired or released generations cannot
mutate a task. Explicit release or recovery retains the lease row so the next
claim increments its generation. Operators cannot mutate a task with a live
lease, even if its state is blocked. Lease ownership does not lock Git files.

Run `statecan:outbox:dispatch` (runtime command) and the receipts Messenger worker as
documented in runtime.md. The receipt.requested handler writes evidence outside
DB transactions and finalizes receipt metadata/task state together afterward.
Stable IDs and no-replace atomic hard-link publication permit retries after
worker crashes. Files are synchronized before publication and the directory is
synchronized before database finalization. Existing bytes are never overwritten.
Configure STATECAN_RECEIPT_DIR to private durable storage on a filesystem that
supports atomic hard links and fsync; default is var/receipts. Pending attempt
content stays in PostgreSQL for recovery.

Canonicalization `statecan-json-v1`: recursively sort object keys bytewise, retain
list order, UTF-8 JSON with unescaped Unicode/slashes, no whitespace, and only
strings, integers, booleans, null and arrays. Receipts use fixed schemaVersion 1;
no floating-point fields. SHA-256 is calculated over exactly these bytes. Verify
independently using `sha256sum <storageKey>` in the configured evidence directory.
Verification attests stored bytes and authenticated report provenance, not truth
of self-reported evidence references. Hashes alone do not enforce immutability;
use restrictive storage ownership/backups or external append-only controls.

Run `app:leases:recover` periodically to return expired active tasks to ready.
Run `app:receipts:reconcile` to inspect all receipt batches, retry recoverable
pending attempts and flag missing/corrupt verified objects as inconsistent. It
never overwrites corrupt evidence. Unique receipt IDs and task/request-key
constraints prevent duplicate attempts/finalization for replayed requests.
The supplied systemd evidence-maintenance service/timer run both every minute;
adapt its user, application directory and writable storage paths for deployment.

Errors use `{ "error": { "status": 409, "code": "conflict", "message": "..." } }`.
Framework validation errors return 422, malformed input 400, unsupported format
415, authentication 401, unauthorized actions 403, missing resources 404 and
lease/version/idempotency conflicts 409. A failed storage job stays recoverable
and must never make a pending task appear completed.
