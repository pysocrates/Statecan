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

Cross-stage checks will consume the published inspector/component contracts:
`#task-inspector`, `#inspector-content`, stable `data-inspector-section` values,
`StatecanInspector`, `StatecanConsole`, `statecan:invalidate`,
`statecan:component-updated` and `statecan:session-expired`. Root templates and CSS
remain coordinator-owned; concrete defects are reported in the shared QA handoff.
