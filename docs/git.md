# Git intelligence

Configure `STATECAN_GIT_ROOT` to a trusted operator-managed directory (default
`%kernel.project_dir%/var/repositories`). Create the directory and place local
repositories inside it. The worker OS account needs read access. Repository
registration requires `ROLE_OPERATOR`, as do all Git endpoints. Agent bearer
credentials and commit trailers cannot administer repositories. Existing form
login remains available.

The path is canonicalized and both the working directory and Git directory must
remain under that root. Treat repositories and their filesystem configuration as
operator-managed: this is a trust boundary, not a sandbox for hostile Git object
stores or concurrent filesystem replacement. Commands use Symfony Process argv
arrays, timeouts, disabled hooks/fsmonitor and no network operations. Sync means
observe local Git data; it never fetches, checks out, writes refs or modifies the
working tree. A future webhook collector can produce the same snapshot boundary.

## API

All routes are under `/api/v1`, return JSON, and require an authenticated operator.

- `POST /repositories`: JSON `{ "projectId": "<uuid>", "path": "<local path>" }`.
- `POST /repositories/{id}/sync`: request another asynchronous observation.
- `GET /repositories/{id}/events?limit=50&offset=0`: observed refs and activity.
- `GET /repositories/{id}/graph?limit=50`: bounded Mermaid commit-parent graph.
- `GET /tasks/{id}/git?limit=50`: task-to-commit correlation and provenance.

Both mutations require `Idempotency-Key` (1–128 ASCII letters, digits, `.`, `_`,
`:`, `-`). Registration keys are scoped to project and operation; sync keys to
repository and operation. Reusing a key with different registration input returns
409. A retry returns the same repository and job identifiers. Use a new key to
schedule another scan. Successful mutations return 202 with `id` and `jobId` after
repository/outbox persistence commits atomically. Unknown project/repository/task
returns 404, invalid path/key 400, invalid JSON DTO 422 (malformed JSON 400), missing
operator authorization 403 (unauthenticated form requests may redirect to login).
API errors use `{ "error": { "status": 400, "code": "bad_request",
"message": "..." } }` through the shared API exception listener. Limits are 1–200 and offset 0–1,000,000.

## Workers and evidence

Registration and sync persist `git.sync_requested` in the core transactional
outbox. Run the Phase 2 outbox dispatcher and `git_indexing` Messenger worker.
The listener executes Git reads outside a transaction, then atomically persists
ledger entries, correlations and the latest observed refs. PostgreSQL advisory
locks serialize scans per repository without keeping a transaction open. Failed
work is retried through runtime retry/failure handling; completed events use the
runtime idempotency key. Repeated scans upsert commit identities and only append
ref activity when observed refs change.

Commit/merge entries retain SHA, parents, author metadata, message, changed file
names, parsed Git trailers and conventional `This reverts commit <sha>.` evidence.
Ref creation/deletion/update entries record before/after targets. Force-pushes and
rebases preserve historical commit entries while current refs change. Unreachable
objects that were never reachable from an observed ref are not collected. This
records observation time, not exact branch creation or deletion time. History
is not proof of agent activity.

Only existing tasks in the repository's project receive trailer associations.
Unknown values remain in raw trailer metadata. A session ID is retained only if
it exists; agent identity must match that session for `identities_validated`.
Even then, confidence remains `self_reported` and source `git_trailer`: identity
validation does not authenticate authorship, prove task execution or grant task
permissions. Association checks rerun on subsequent scans. Git does not change
operational task state. Mermaid uses hexadecimal SHA identifiers only, and graphs
are projections of indexed data with bounded nodes/edges.

The initial collector reads full locally reachable history. Very large histories
may need a later incremental collector; Git command timeouts fail recoverably
rather than silently declaring a partial scan complete. Graphs and ledgers have
bounded HTTP projections. No history pruning is performed.
