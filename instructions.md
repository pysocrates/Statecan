# STATECAN — Engineering Implementation Specification

**Project:** Statecan  
**Category:** Agentic SDLC coordination and observability  
**Backend:** Symfony / PHP  
**Architecture:** API-first, asynchronous, event-driven  
**Status:** Initial engineering specification

## 1. Mission

Build Statecan, a framework-agnostic coordination and observability platform designed to operate alongside autonomous and semi-autonomous software development agents.

Statecan must provide:

- Structured task contracts consumed by agents.
- Persistent task and session state.
- Safe coordination across concurrent agent sessions.
- Immutable JSON evidence of task completion.
- Asynchronous background processing.
- Git repository observability and provenance tracking.
- A real-time human operator dashboard.
- Historical reporting and visualization of agentic SDLC activity.

Statecan is not an LLM execution framework, task-planning agent, or replacement for Git.

**Core principle:** Statecan governs, records, correlates, and presents the work performed by external agents.

## 2. Engineering Constraints

1. Use Symfony for the application backend.
2. Use PostgreSQL as the authoritative transactional database.
3. Use JSON Schema for task contracts and versioned public data structures.
4. Use Symfony Messenger for asynchronous job processing.
5. Prefer PostgreSQL-backed Messenger transport initially.
6. Use Doctrine ORM for ordinary entities and DBAL where explicit transaction control is necessary.
7. Support independent worker processes managed by systemd.
8. Keep HTTP request handlers short and nonblocking except for necessary database transactions.
9. Use a transactional outbox to coordinate state mutations and asynchronous work.
10. Ensure business operations and background handlers are idempotent.
11. Use UTC for persisted timestamps.
12. Do not introduce Docker, Kubernetes, Redis, RabbitMQ, or a JavaScript SPA without a demonstrated requirement.
13. Keep domain services independent of controllers, UI code, and message transports.
14. Prefer existing Symfony components over custom infrastructure.
15. Implement tests for concurrency, data integrity, retries, and failure recovery.

Do not create additional subsystems beyond those described here without documenting a concrete requirement.

## 3. Domain Model

Implement the following primary entities:

| Entity | Responsibility |
|---|---|
| Project | Logical grouping of development work |
| Task | Authoritative current task state |
| TaskDependency | Directed task dependency relationships |
| TaskContract | Versioned JSON task specification |
| Agent | Registered agent identity |
| AgentSession | Individual execution session |
| TaskLease | Temporary exclusive task ownership |
| StateEvent | Append-only state transition record |
| CompletionReceipt | Metadata and location of finalized JSON evidence |
| GitRepository | Registered repository and configuration |
| GitEvent | Indexed repository activity |
| GitTaskAssociation | Correlation between Git evidence and tasks |
| BackgroundJob | Operational status of relevant asynchronous work |
| OutboxEvent | Durable pending integration event |

Use PostgreSQL JSONB for variable contract and metadata fields.

Use relational columns for frequently queried fields such as IDs, status, ownership, timestamps, version, and repository relationships.

Do not substitute large unindexed JSON documents for a usable relational model.

## 4. Task State Management

Implement an explicit state-transition service.

Initial states:

- `backlog`
- `ready`
- `claimed`
- `running`
- `blocked`
- `awaiting_review`
- `completion_pending_evidence`
- `completed`
- `failed`
- `cancelled`

Only authorized transitions are permitted.

Every successful transition must:

1. Validate the requested transition.
2. Check ownership, authorization, dependencies, and relevant preconditions.
3. Update the authoritative database state.
4. Increment a version number.
5. Append a StateEvent.
6. Create required OutboxEvents.
7. Commit atomically.

Use optimistic version checking or database row locking to prevent conflicting updates.

No agent may directly overwrite arbitrary task state.

## 5. Agent Coordination

Agents must be able to claim, release, and update tasks through the API.

Implement task leases with:

- Agent identity
- Session identity
- Expiration timestamp
- Lease token
- Monotonically increasing fencing generation
- Renewal/heartbeat support

Claims must be atomic.

Two concurrent claims for the same task cannot both succeed.

Expired leases may be reclaimed, but commands from an older lease generation must be rejected.

Implement a background lease-recovery job.

A task lease governs Statecan permissions. It does not independently lock Git working trees or prevent external file changes.

Task contracts must include:

- Objective
- Authorized scope
- Constraints
- Dependencies
- Acceptance criteria
- Expected deliverables
- Optional resource budgets

Preserve the original contract version for historical inspection.

## 6. Asynchronous Processing

Use Symfony Messenger with separate logical queues for:

- `state_events`
- `receipts`
- `git_indexing`
- `notifications`
- `maintenance`

Logical queue names may share the same PostgreSQL infrastructure.

Required background operations:

- Receipt materialization
- Receipt verification
- Git repository synchronization
- Commit indexing
- Task-to-commit correlation
- Lease expiration detection
- Outbox dispatch
- Dashboard notification publication
- Periodic consistency checks

Implement:

- Bounded retries with exponential backoff and jitter
- Dead-letter/failure transport
- Idempotent message handlers
- Structured logging and correlation IDs
- Worker timeouts and graceful shutdown
- Job status and failure inspection
- Queue depth and processing latency visibility
- Safe recovery after worker termination

Do not place full task contracts or large Git diffs into queue messages. Queue messages should carry stable identifiers and necessary version references.

Avoid holding database transactions open while performing Git operations, filesystem writes, network requests, or other slow external work.

## 7. Database and JSON Consistency

PostgreSQL is authoritative for operational state.

Immutable JSON is authoritative for the contents of finalized evidence artifacts.

Every completion receipt must:

1. Reference a specific task and contract version.
2. Identify the responsible session and lease generation.
3. Include the reported result and supporting evidence references.
4. Record verification status and provenance.
5. Be serialized using a documented deterministic canonicalization scheme.
6. Be hashed using SHA-256.
7. Be written to immutable or append-only evidence storage.
8. Be verifiable against the stored hash.

Receipt writing must use an idempotent, crash-recoverable process.

Use a stable receipt ID and deterministic storage key. Store content and metadata safely, verify the stored object, and finalize database metadata without assuming the filesystem and database share an atomic transaction.

A failed receipt-write job must not cause a task to be falsely reported as finalized.

Keep completion attempts and finalized receipts distinguishable.

Provide a reconciliation job that identifies missing, inconsistent, duplicated, or unverified evidence.

Do not claim cryptographic hashes alone guarantee immutability.

## 8. Git Observability

Implement read-only Git repository indexing.

Initial supported information:

- Repository identity
- Current observed refs
- Branch creation and deletion observations
- Commit metadata and SHA
- Parent commit relationships
- File change lists
- Merge events
- Commit-to-task associations
- Revert detection where identifiable

Git collection must run asynchronously.

Support local repositories initially. Design integration boundaries that permit GitHub webhook ingestion later.

Use Git commit trailers when available:

- `Statecan-Task`
- `Statecan-Session`
- `Statecan-Agent`

Treat trailer values as untrusted, self-reported correlation data.

Validate referenced tasks and sessions before accepting associations as verified.

Repository history is not equivalent to observed agent activity.

Track source, observation timestamp, confidence, and verification status for correlations.

Repository synchronization must be idempotent and tolerate force-pushes, rebases, ref deletion, and repeated indexing.

Never allow arbitrary untrusted repository paths or unrestricted shell execution.

## 9. Visualization

Provide JSON-backed read endpoints for:

- Kanban task views
- Task dependency graphs
- Task-to-commit provenance graphs
- Git branch/commit history
- Agent session timelines
- Completion receipt history
- Worker and queue status

Implement Mermaid-compatible graph generation where appropriate.

Graph generation should be a projection of indexed data, not the canonical record.

Limit graph size and provide pagination or filtering for large histories.

The browser must not need to independently reconstruct authoritative state from raw event logs.

## 10. Human Operator Dashboard

Initial dashboard features:

1. Project selection
2. Kanban board
3. Task detail inspector
4. Agent/session status
5. State transition timeline
6. Git activity ledger
7. Receipt inspection and verification status
8. Background job health
9. Live activity stream

Use server-rendered Symfony UI components where practical.

Implement authorized live updates using Mercure/SSE.

After reconnecting or missing events, clients must be able to refresh the authoritative state from the API.

Live updates are a notification mechanism, not the system of record.

Operator permissions must be distinct from agent API permissions.

## 11. Initial API

Implement versioned REST endpoints under `/api/v1`.

### Tasks

- `POST /projects`
- `GET /projects/{id}`
- `POST /tasks`
- `GET /tasks`
- `GET /tasks/{id}`
- `POST /tasks/{id}/claim`
- `POST /tasks/{id}/heartbeat`
- `POST /tasks/{id}/transition`
- `POST /tasks/{id}/complete`
- `GET /tasks/{id}/events`
- `GET /tasks/{id}/receipts`

### Agents and sessions

- `POST /agents/sessions`
- `POST /agents/sessions/{id}/heartbeat`
- `GET /agents/sessions/{id}`

### Git

- `POST /repositories`
- `POST /repositories/{id}/sync`
- `GET /repositories/{id}/events`
- `GET /repositories/{id}/graph`
- `GET /tasks/{id}/git`

### Operations

- `GET /operations/jobs`
- `GET /operations/queues`
- `GET /operations/health`

Mutation endpoints must enforce authentication, authorization, input validation, and idempotency.

Use `202 Accepted` where work has been durably scheduled but not completed.

Use `409 Conflict` for task ownership or version conflicts.

Document status codes and machine-readable error responses.

## 12. Testing Requirements

At minimum, verify:

- Concurrent claims cannot create multiple owners.
- Duplicate completion requests cannot create duplicate finalized receipts.
- Failed database transactions leave no partial state changes.
- Outbox events survive process termination.
- Failed handlers can be retried safely.
- Expired leases reject stale owners.
- Receipt hashes can be independently verified.
- Git indexing does not duplicate commits on subsequent scans.
- Agent-created Git metadata cannot bypass authorization.
- Operator dashboard reconnects to authoritative state.
- Worker failure does not corrupt tasks.
- Receipt storage failure leaves a recoverable pending state.

Provide integration tests using actual PostgreSQL transactions for concurrency-sensitive functionality.

Do not treat SQLite-only tests as proof of PostgreSQL locking behavior.

## 13. Implementation Phases

### Phase 1 — Transactional Core

Deliver:

- Symfony application
- PostgreSQL schema and migrations
- Project/task CRUD
- Versioned JSON contracts
- State transition service
- StateEvent ledger
- Transactional outbox
- Tests for transaction integrity

### Phase 2 — Asynchronous Runtime

Deliver:

- Messenger queues and worker configuration
- Outbox dispatcher
- Idempotent handlers
- Job monitoring
- Retry/failure transport
- systemd worker configuration
- Worker restart/recovery tests

### Phase 3 — Agent API and Receipts

Deliver:

- Session registration
- Atomic task claiming
- Lease heartbeat and recovery
- Completion requests
- Asynchronous receipt storage
- Hash verification
- Reconciliation mechanism

### Phase 4 — Git Intelligence

Deliver:

- Repository registration
- Asynchronous Git indexing
- Commit ledger
- Task/commit correlation
- Git-related query endpoints
- Mermaid graph generation

### Phase 5 — Human Observability

Deliver:

- Kanban dashboard
- Task inspector
- Agent activity views
- Git ledger and graphs
- Historical receipts
- Worker health view
- Real-time SSE updates

Complete and test each phase before starting the next.

## 14. Codex Execution Rules

Before implementation:

1. Inspect the repository and existing code.
2. Identify applicable conventions and existing infrastructure.
3. Produce a concise implementation plan for the current phase.
4. Identify files expected to change.
5. State assumptions and dependencies.

During implementation:

1. Work only on the authorized phase.
2. Avoid unrelated refactors.
3. Do not invent new requirements.
4. Do not modify unrelated modules.
5. Add tests alongside behavior.
6. Prefer small, reviewable commits.
7. Do not start subsequent phases autonomously.
8. Preserve existing interfaces unless explicitly authorized to change them.
9. Do not declare success without executing relevant checks.

After implementation, report:

- Implemented functionality
- Changed files
- Migrations created
- Tests executed and results
- Remaining known issues
- Design decisions and tradeoffs
- Any deviation from this specification

**Definition of success:** Statecan can safely accept concurrent agent operations, maintain recoverable asynchronous processing, preserve verifiable historical evidence, correlate Git activity, and present accurate state to a human operator.

**Do not build an autonomous agent orchestration engine. Build the state infrastructure that orchestration engines can depend upon.**

