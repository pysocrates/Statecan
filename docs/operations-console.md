# Operations console — Stage 1

This increment delivers responsive navigation, an Operations landing view, and a
four-group Work board. It preserves the Symfony state engine, authentication,
operator authorization, existing endpoint fields and PostgreSQL-driven SSE.
No schema changes or frontend build tools are required.

## Operator questions answered

Operations prioritizes attention tasks and system issue categories, alongside
active work, project-wide task totals, live-lease agents, bounded transition
activity, queue backlog and fresh worker coverage. Task failures, blocked work,
review requests, expired active leases and inconsistent receipts contribute to
the attention task list. System issues link to the existing Runtime view.

An active agent is a distinct registered agent with an unexpired lease in the
selected project and a session heartbeat within five minutes. Open tasks exclude
completed and cancelled tasks. Attention counts combine distinct attention tasks
with system issue categories; they are not a count of distinct people or jobs.
Queue backlog includes delayed and in-flight normal messages, excluding the failed
transport. Runtime metrics are application-wide and are labeled separately from
project-scoped task metrics. Worker freshness uses the runtime monitor's TTL.

Totals cover the selected project, independently of the current task page.
Attention and running lists contain up to six tasks each, recent task activity
eight events. The Work board retains existing 25-task pagination and indicates
that column counts are project-wide totals, not counts on the current page.

| Work group | Original statuses |
| --- | --- |
| Ready | backlog, ready |
| In progress | claimed, running, completion_pending_evidence |
| Attention | blocked, awaiting_review, failed |
| Done | completed, cancelled |

Cards retain their original status badges. Backlog does not become claimable,
pending evidence does not mean completed, and cancelled does not mean successful.
No drag-and-drop or new state mutation operation is introduced. Long columns
scroll vertically; the layout uses four, two or one columns according to available
width. Navigation switches views locally without a page request.

## Delivery boundaries

The existing Git, History and Runtime content remains accessible in separate views.
Its structured redesign, graph controls and historical filtering belong to later
stages. Task links still use the existing page-based inspector; the contextual
inspector is Stage 2. Existing fragment refreshes remain in this increment,
with the selected view and navigation focus retained. Component-level updates,
inspector expansion/focus preservation and event-scoped reads belong to Stage 3.

Connection status is distinct from snapshot freshness. Refresh failures retain
the last snapshot and show a visible error; authentication loss stops automatic
refresh attempts. The activity list shows persisted task transitions, not invented
operational events or a log of local refreshes. Empty projects still show runtime
issues, empty board groups and clear absence-of-work messages.

## Verification

Browser tests use development-only Symfony Panther, Chromium and a matching
ChromeDriver on PATH. They use the isolated PostgreSQL test database and the
application's normal login and operator authorization. No production data or
acceptance credentials are embedded in tests.

```bash
php bin/phpunit --group browser
php bin/phpunit --exclude-group browser
```

The browser test checks all five views at exact 1440, 1024, 768 and 390 pixel
viewports for document overflow. It also covers project-wide counts with more
than one task page, original state grouping, active agent data, vertical column
scrolling, real SSE-driven task updates, retained navigation focus/selection,
network failure/recovery and an empty project. Screenshots are generated under
ignored `var/console-screenshots/` for visual review.

PHP extensions must be enabled for both PHPUnit and Panther's child PHP server.
For deployment or test environments supplying modules through command-line flags,
configure equivalent PHP INI settings for the child server. The test server runs
with four PHP workers so an SSE response does not block fragment or login requests.
Use `PANTHER_EXTERNAL_BASE_URI` only with an isolated test server configured for the
test database. Browser executables are test prerequisites, not application runtime
dependencies.
