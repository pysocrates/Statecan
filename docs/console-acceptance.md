# Console acceptance checks

`tests/Frontend/ConsoleAcceptanceTest.php` runs representative cross-stage checks
in real Chromium through Panther. It complements the existing Stage 1 browser
scenario rather than replacing its project totals and state-grouping coverage.
The tests use the application's real form login, operator permission, PostgreSQL
reads and browser event handling. Fixture records belong to uniquely identified
test projects and are removed after each test, including when assertions fail.
No real checkout is registered or indexed by this QA fixture.

Run against an isolated, migrated PostgreSQL test database:

```sh
php bin/console doctrine:migrations:migrate --env=test --no-interaction
PANTHER_WEB_SERVER_PORT=9095 php bin/phpunit tests/Frontend/ConsoleAcceptanceTest.php
```

Enable iconv and pdo_pgsql for both the parent PHP process and Panther's child
server through your environment's PHP INI configuration. Chromium and a matching
ChromeDriver must be available on PATH. The QA test starts four PHP server workers;
its ChromeDriver port defaults to 9595 and can be changed with
`CONSOLE_QA_CHROMEDRIVER_PORT`. Choose unique HTTP and driver ports when running
parallel suites. Do not point `PANTHER_EXTERNAL_BASE_URI` at production data.
Keep local connection details and browser settings in ignored environment files.

The shell scenario checks every view at 1440, 1024, 768 and 390 pixels, one selected
navigation item, retained navigation focus, visible control labels, keyboard skip
navigation, visible network failure/recovery, an empty Work board and real cookie
loss. Session expiry must retain the last snapshot instead of inserting a login
page into a dashboard component. It does not assert that a connected transport
means the data is fresh.

Ignored `var/console-qa/` artifacts include a mobile screenshot and observations
of document width, DOM size, navigation transfer size and Account-link visibility.
These are observations for comparison, not invented runtime health metrics or a
claim of a comprehensive accessibility audit. Absolute timing thresholds are
avoided because local browser/CI scheduling varies; scoped request counts and
bounded DOM growth will verify the targeted-update performance contract.

Cross-stage checks consume the published inspector/component contracts:
`#task-inspector`, `#inspector-content`, stable `data-inspector-section` values,
`StatecanInspector`, `StatecanConsole`, `statecan:invalidate`,
`statecan:component-updated` and `statecan:session-expired`. Root templates and CSS
remain coordinator-owned; concrete defects are reported in the shared QA handoff.

The focused-update scenario opens a task through its real link, changes another
persisted task and then the selected task through the transition service, and
waits for PostgreSQL-driven SSE reconciliation. It checks selected URL/view,
unchanged inspector DOM and zero inspector reads for unrelated task changes,
retained expansion/focus for related changes, and a bounded response to 25
invalidations. Fetch observation wraps the real fetch implementation; it does
not mock server responses or replace the event pipeline. DOM node counts detect
duplicate appended component trees.

The investigation scenario creates 32 recorded task transitions and a synthetic
indexed Git history in its own test project, without executing or registering a
real local repository. It exercises 25-row paging, bounded graph nodes, graph
zoom/fit, commit search, history search/empty state, and expanded failed-job
investigation across Runtime refresh. Populated Git, History and Runtime views
are checked for overflow at the same four widths. All fixture observations and
jobs are removed in teardown; they never affect a production database.

A passing browser suite is representative acceptance, not a complete WCAG audit,
load test or proof of absent browser leaks. Native keyboard operation, visible
focus, semantic labels, controlled session expiry and bounded requests/records
are tested explicitly. Screenshots should also be reviewed before release.

For request-count comparisons, the browser child uses the normal 25-second SSE
lifetime. The one-second server-stream unit-test default intentionally reconnects
frequently and would add reconciliation reads unrelated to the measured change.
The login helper waits for the signed-in home page before navigating, so a browser
GET cannot interrupt the form POST. Scenario setup uses visible view navigation.
After changing compiled templates between branches, clear the test cache before
running acceptance to avoid stale Twig output.

The session-loss scenario also follows the visible recovery link and signs in
again through the real form. It verifies that an unauthorized background read has
not become a saved login destination pointing at raw JSON. Separate test scenarios
start with cleared browser cookies; the expiry/recovery flow deliberately does not
clear its new anonymous session before signing in.

Acceptance found and now guards two integration regressions: a later phone CSS
rule hiding the compact Account link, and expired background reads becoming a
saved form-login destination pointing at JSON. Product owners corrected the CSS,
marked fetch requests as XMLHttpRequest, and protected native SSE/API login-target
handling centrally. The recovery scenario signs in with the anonymous session
created by the expired read intact; it does not clear the saved target to make
the check pass.
