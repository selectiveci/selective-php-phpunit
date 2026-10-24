# selective-php-phpunit

Selective client for PHPUnit. One warm PHP process, one method per assigned id, over the stdio transport sidecar. Pest is a later repository. This package is not published to Packagist.

## Run

```bash
composer install
vendor/bin/selective phpunit
vendor/bin/selective exec phpunit --filter testPass
```

`selective phpunit` opens a session. `selective exec phpunit` prints discovered ids and does not open a socket.

`--debug` and `--log` belong to Selective and are not passed to PHPUnit (so PHPUnit's own `--debug` is not available through this command). `--debug` prints an error's backtrace instead of the support block, says when a reconnect waits, and treats an unknown server command as an error. `--log` writes every received command and sent response to `log/<runner id>.log`.

The sidecar is `transport --stdio`. The binary is `SELECTIVE_TRANSPORT` when set, which is also how to use a glibc build. Otherwise the client uses `~/.selective/transport/<release>/transport`, downloading it on first use: the release asset for the platform (`transport-Linux-x86_64.tar.gz`, the static musl build, on Linux) is fetched from GitHub releases, or from `SELECTIVE_TRANSPORT_RELEASES` when set, and used only if its sha256 matches the one pinned in `TransportBinary`. The pin is a static musl build of transport PR #10 at 44a9c7a (`pr10-44a9c7a`) until a tagged release with `--stdio` exists; it then moves to that tag. The websocket URL is `SELECTIVE_TRANSPORT_URL` when set (used as given, plus any missing `test_map` and `remove_failed`, and `reconnect=true` on reconnect). Otherwise the client runs `transport --build-env` and builds the URL from that JSON. The URL and key stay in the child environment. They are not process arguments.

## What the session does

Discovery boots PHPUnit once in-process. The client copies `phpunit.xml` to `phpunit.selective.xml` and forces these off before `Registry::init`: `stopOnDefect`, `stopOnError`, `stopOnFailure`, `stopOnWarning`, `stopOnRisky`, `stopOnIncomplete`, `stopOnSkipped`, `stopOnNotice`, and `stopOnDeprecation`. `--stop-on-*` is stripped from the arguments. `processIsolation` and `--process-isolation` are honored: each assigned test then runs in its own PHP process. On PHPUnit 13 the client never calls `Application::run`, so PHPUnit's SIGINT and time-limit handlers are not installed. SIGINT and SIGTERM are handled by the session.

Each assigned id is built again with `TestSuite::fromClassReflector` and narrowed with `setTests()` to that one method, so `setUpBeforeClass` and `tearDownAfterClass` run once per assigned execution. An extension subscriber writes the client's own per-id store. Errors and failures are `failed`, skips and incomplete tests are `pending`, and the rest are `passed`. An assigned id with no outcome is `failed`, with a project frame first. Exit status is read from that store.

The manifest id comes from PHPUnit's own `--list-tests-xml` writer. PHPUnit 10 names are `testCaseClass` / `testCaseMethod`. PHPUnit 11 and later use a `testSuite` document with `testClass` / `testMethod`.

The client advertises `test_map` and `remove_failed=true` on every connect, and adds them to a preset `SELECTIVE_TRANSPORT_URL` that lacks them. `test_map` is `record` when the `pcov` extension is loaded, otherwise `select`, and `SELECTIVE_TEST_MAP_DISABLE` of `1`, `true`, or `yes` makes it `0`; with `SELECTIVE_TEST_MAP_XDEBUG=1` and no pcov, Xdebug traces but the client still advertises `select`. When recording, each test's covered project files become its dependencies, and the fragment is uploaded after the run by a separate PHP process (with `display_errors=stderr`, so a deprecation cannot break it). A failed upload warns and does not fail the build. When the server asks for a recording and no tracer is loaded, the client says so once.

## PHPUnit versions

The same client runs on PHPUnit 10.5, 11.5, 12.5, and 13. PHPUnit 13 needs PHP 8.4.1 or newer. The root `composer.lock` is not committed so an install on PHP 8.2 can resolve PHPUnit 10 or 11.

## Conformance

The transport's conformance runner, `transport-conformance`, drives this client through every scenario. Run it from the ordered fixture, with a fresh flake file per scenario:

```bash
cd tests/fixtures/suite
for scenario in $(transport-conformance list); do
  SELECTIVE_FLAKE_FILE=/tmp/flake-$scenario transport-conformance run --scenario "$scenario" --transport "$SELECTIVE_TRANSPORT" \
    --client "php $PWD/../../../bin/selective phpunit" --fixture-ids conformance.json
done
```

`php tests/harness/play.php` runs the unit suite plus the checks the transport scenarios do not cover: `--list-tests-xml` ids, class hooks re-entered per assigned id, and `stopOn*` forced off. It accepts `all`, `unit`, `list`, `hooks`, or `stops`.

The transport pin is `selectiveci/transport` commit `44a9c7abde5a35d869c5f71780d56e26c0c6f8bd` (`cursor/transport-foundation-f902`). CI builds `transport` and `transport-conformance` with `cargo install --locked --git https://github.com/selectiveci/transport --rev <sha> --bin transport --bin transport-conformance`, in a target directory used only for that sha; do not commit either binary. The fixture's `conformance.json` declares `before_all_seconds`, so `group_hook_time` runs against `PassTest`'s before-all hook.

## Laravel

Run `vendor/bin/selective phpunit` from the application root. `php artisan test` does not use Selective: Collision starts `vendor/phpunit/phpunit/phpunit` directly, so the run is neither split nor reported.

CI creates `laravel/laravel` 13.11.0 with `composer create-project`, installs this package from a path repository, adds `tests/fixtures/laravel/HookCounterTest.php`, and drives the app with `php tests/harness/laravel.php <app>`.

Pest is a separate package, `selective-php-pest`.
