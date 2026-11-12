# Statecan v1

Statecan is a coordination and observability backend for software development agents. It gives external agents structured task contracts, exclusive task leases, durable completion evidence, and a shared record of work that a human operator can inspect.

Statecan records and governs work performed elsewhere. It does not run LLMs, plan tasks autonomously, manage agent processes, or replace Git.

## What v1 includes

- Projects and tasks with versioned JSON contracts, dependencies, explicit state transitions, and an append-only transition ledger.
- Authenticated agent sessions, atomic task claims, heartbeats, expiring leases, and fencing generations that reject previous owners.
- PostgreSQL-backed Messenger queues, a transactional outbox, retries, failed-message inspection, and job/queue status.
- Asynchronous completion receipts with deterministic JSON, SHA-256 verification, recoverable storage writes, and reconciliation.
- Read-only indexing of trusted local Git repositories, observed refs, commits, file changes, and task/session trailer associations.
- A server-rendered operator dashboard with Kanban, task details, timelines, receipt inspection, Git graphs, and SSE refresh notifications.
- systemd templates for workers, outbox dispatch, lease recovery, and receipt reconciliation.

## Requirements

| Dependency | Notes |
| --- | --- |
| PHP | 8.4.1 or newer for the locked Symfony 8.1 dependencies; verified with PHP 8.5 |
| Composer | Install dependencies from the committed lock file |
| PostgreSQL | Authoritative database and queue storage; verified with PostgreSQL 18 |
| PHP extensions | Composer platform requirements, plus `pdo_pgsql`; `pcntl` for worker signal handling |
| Git | Required for repository indexing |
| Symfony CLI | Optional; convenient for the development server |
| systemd | Optional for development; supplied deployment templates target Linux/systemd |

The dashboard has no JavaScript build step. Docker, Redis, RabbitMQ, a Mercure hub, and a JavaScript SPA are not required.

## Local setup

### 1. Install dependencies

```bash
composer install
```

### 2. Configure your environment

Create an ignored `.env.local` with your own values:

```dotenv
APP_ENV=dev
APP_SECRET=replace-with-a-random-secret
DATABASE_URL="postgresql://statecan:replace-with-your-password@127.0.0.1:5432/statecan?serverVersion=18&charset=utf8"
```

Generate a secret with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. URL-encode reserved characters in database credentials, and set `serverVersion` to match your PostgreSQL server. Provision the PostgreSQL login first; database creation requires appropriate privileges.

Committed `.env` values are defaults. Keep actual credentials in `.env.local`, environment variables, or the Symfony secrets vault. Environment-specific local files such as `.env.dev.local` take precedence over `.env.local`; real environment variables take precedence over these files.

### 3. Create the database and run migrations

```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
```

Use migrations for schema changes. The application requires PostgreSQL; the legacy MySQL authentication migrations retain their original platform branches, but this is not an automatic MySQL-to-PostgreSQL data migration.

### 4. Start the web application

```bash
symfony serve -d
```

Without the Symfony CLI, use PHP's development server with multiple workers (Unix):

```bash
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t public
```

SSE streams occupy a worker, so the web server must allow concurrent requests. See [stream deployment notes](docs/observability.md).

Open `/register`, create the initial account, then sign in and open `/dashboard`.

**The first account registered while the user table is empty automatically becomes an operator.** Later registrations receive regular user access. The initial-account decision uses a PostgreSQL-backed Symfony lock across application instances. Existing installations with users do not automatically promote an account.

To grant another registered account operator access:

```bash
php bin/console app:operator:grant user@example.org
```

Sign out and back in after a role change. Operator permissions are separate from agent bearer credentials.

The dashboard observes data created through the API; v1 does not include a full browser editor for projects and tasks. An empty installation initially shows an empty board.

## Background processing

HTTP requests durably schedule work; workers perform receipt writes and Git indexing. A completion remains pending until its receipt is stored and verified.

For development, run a worker in another terminal:

```bash
php bin/console messenger:consume state_events receipts git_indexing notifications maintenance --keepalive=30 --time-limit=3600 --memory-limit=256M
```

Dispatch pending outbox events after submitting work:

```bash
php bin/console statecan:outbox:dispatch --limit=100
```

Outbox dispatch must run repeatedly for ongoing operation. Production templates provide a timer. A single development worker can consume all five queues; production can run separate processes per queue. Do not consume the `failed` transport as a normal queue.

Useful maintenance commands:

```bash
php bin/console app:leases:recover
php bin/console app:receipts:reconcile
php bin/console messenger:failed:show
php bin/console messenger:failed:retry
```

All queues share the default PostgreSQL connection. Enqueueing and marking an outbox event published commit in the same transaction. Delivery is at least once: handlers must tolerate retries after a crash, including after an external effect has already happened.

For service installation, restart behavior, and logging, see [runtime operations](docs/runtime.md) and [systemd templates](deploy/systemd/). Adapt the service account, application directory, environment files, and writable paths to your deployment. Templates are examples, not an installer. In particular, give the evidence-maintenance service the same database/secret environment as the web application and workers.

## API and agent workflow

The REST API lives under `/api/v1`. Operators use the form-authenticated session for project/task administration and repository operations. Agents use provisioned bearer tokens for sessions and fenced task commands.

| Area | Main endpoints |
| --- | --- |
| Projects | `POST /projects`, `GET /projects`, `GET/PATCH/DELETE /projects/{id}` |
| Tasks | `POST /tasks`, `GET /tasks`, `GET/PATCH/DELETE /tasks/{id}` |
| Task activity | `POST /tasks/{id}/transition`, `GET /tasks/{id}/events`, `GET /tasks/{id}/receipts` |
| Agent sessions | `POST /agents/sessions`, `GET /agents/sessions/{id}`, `POST /agents/sessions/{id}/heartbeat` |
| Ownership and completion | `POST /tasks/{id}/claim`, `/heartbeat`, `/transition`, `/complete` |
| Repositories | `POST /repositories`, `POST /repositories/{id}/sync`, `GET /repositories/{id}/events`, `/graph` |
| Provenance | `GET /tasks/{id}/git` |
| Operations | `GET /operations/jobs`, `/operations/queues`, `/operations/workers`, `/operations/health` |
| Dashboard data | `GET /observability/snapshot`, `/observability/events`, `/observability/receipts/{id}` |

Mutation requests require an `Idempotency-Key`. Reuse a key only when retrying the same operation and input. JSON payloads use `Content-Type: application/json`; send `Accept: application/json` for API responses. Version, ownership, and idempotency conflicts return `409`. Durable asynchronous scheduling returns `202`.

### Task contracts

Operators create projects and tasks through the API, then move eligible tasks to `ready`. A task creation body has `projectId`, `title`, and a contract such as:

```json
{
  "schemaVersion": 1,
  "objective": "Implement the requested feature",
  "authorizedScope": ["src/", "tests/"],
  "constraints": ["Preserve existing public interfaces"],
  "dependencies": [],
  "acceptanceCriteria": ["The documented workflow succeeds"],
  "expectedDeliverables": ["Implementation", "Minimal functional test"],
  "resourceBudgets": {"seconds": 3600}
}
```

See the [contract schema](schemas/task-contract-v1.json) and [core API guide](docs/core-api.md) for payloads, state rules, contract revisions, and archival behavior.

### Agent access

Provision an agent from the command line:

```bash
php bin/console app:agent:create 'build agent'
```

The command displays the bearer token once. Store it privately; the database keeps its SHA-256 digest. Set `STATECAN_AGENT_TOKEN` in your client environment, then register a session:

```bash
curl --fail-with-body -X POST http://127.0.0.1:8000/api/v1/agents/sessions \
  -H "Authorization: Bearer $STATECAN_AGENT_TOKEN" \
  -H 'Accept: application/json' \
  -H 'Idempotency-Key: session-create-001'
```

The agent workflow is:

1. Read a ready task and its contract.
2. Claim it with the session ID, expected task version, and a client-generated cryptographically random lease token.
3. Retain the returned fencing generation and updated task version; include them with the token in subsequent commands.
4. Renew the lease through heartbeats and transition the task as work proceeds.
5. Submit a result and evidence references to `/complete`.
6. Inspect the pending receipt until a worker verifies storage and finalizes the task.

Lease tokens must be 32–128 characters; leases last five minutes between renewals. A Statecan lease controls API permissions, not Git working trees or external files. See [agent API and receipts](docs/agent-api.md) for complete request fields and error behavior.

## Git and evidence storage

| Setting | Default | Purpose |
| --- | --- | --- |
| `STATECAN_GIT_ROOT` | `%kernel.project_dir%/var/repositories` | Trusted root for operator-managed local repositories |
| `STATECAN_RECEIPT_DIR` | `%kernel.project_dir%/var/receipts` | Private, durable receipt storage |

Put local repositories under the configured Git root and register them as an operator. Synchronization observes local history; it does not fetch remotes or modify working trees. Both the repository and Git directory must remain inside the trusted root.

Commit trailers can associate indexed commits with Statecan records:

```text
Statecan-Task: <task-id>
Statecan-Session: <session-id>
Statecan-Agent: <agent-id>
```

Trailers are self-reported evidence. Validating referenced identities does not prove authorship or grant permissions. See [Git indexing](docs/git.md).

Receipt storage must support atomic hard links and `fsync`. Back up both PostgreSQL and evidence storage, and restrict storage ownership. Deterministic JSON and hashes make bytes verifiable; they do not, by themselves, make storage immutable. See [receipt canonicalization and recovery](docs/agent-api.md).

## Tests and verification

Use a dedicated PostgreSQL test database. Symfony adds `_test` to the configured database name; `.env.test.local` can provide a separate base connection. Never point tests at a production database.

```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/console doctrine:migrations:migrate --env=test --no-interaction
php bin/phpunit
```

If PHP extensions are enabled through command-line flags rather than the normal PHP configuration, pass matching worker subprocess flags as JSON in `STATECAN_TEST_PHP_ARGS`; see [runtime test notes](docs/runtime.md).

Additional checks:

```bash
php bin/console doctrine:schema:validate
php bin/console lint:container
php bin/console lint:yaml config/
php bin/console lint:twig templates/
```

The initial v1 verification run passed **10 tests and 113 assertions**, including registration/login, first-account operator access, task transactions, agent claims/completion, receipt verification, Git indexing, worker redelivery, and dashboard reconnect behavior. This is a deliberately small smoke suite, not exhaustive concurrency, fault-injection, security, or load testing.

## Current boundaries

- Operator visibility is application-wide; project membership and tenant isolation are not modeled.
- The dashboard is primarily read-only. Project/task administration is API-driven.
- Git integration supports trusted local repositories only. There are no GitHub webhooks or remote fetches in v1, and indexing scans locally reachable history.
- SSE uses PostgreSQL commit notifications to push changes over open authenticated streams. `STATECAN_SSE_DURATION` defaults to 25 seconds; reconnects recover missed changes and recheck permissions. Each stream needs a PHP worker and a dedicated PostgreSQL listener connection. Larger installations may need Mercure or another dedicated notification service.
- Worker lifecycle and keepalive heartbeats establish recent responsiveness. Separate job-runtime, queue-backlog and outbox-age checks identify suspected stalls. Health returns HTTP 503 when coverage is missing or work is stalled; see [runtime thresholds](docs/runtime.md).
- Filesystem administrators can alter receipt files unless storage adds stronger controls; verification checks stored bytes, not the truth of reported work.
- Existing MySQL deployments require a separate data migration to PostgreSQL.

## Further reading

- [Engineering specification](instructions.md)
- [Core API, contracts, transitions, and transactions](docs/core-api.md)
- [Messenger, outbox, worker recovery, and deployment](docs/runtime.md)
- [Agent credentials, leases, receipts, and reconciliation](docs/agent-api.md)
- [Git indexing and provenance](docs/git.md)
- [Dashboard projections and live refresh](docs/observability.md)
- [Repository engineering conventions](AGENTS.md)

The Composer manifest declares this project proprietary; no separate open-source license is included.
