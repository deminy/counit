# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. The same file
is kept on both maintained branches, `master` and `0.x`; where they differ, it says so. Run `git branch --show-current`
to see which branch you are on.

**Keeping this file in sync:** whenever `CLAUDE.md` changes, make the same change on both `master` and `0.x` in the
same piece of work, so the file stays byte-for-byte identical on both branches. Write branch-specific facts as such
(e.g. "on `0.x`: ..."), never as if the current branch were the only one. Check with
`git diff master 0.x -- CLAUDE.md`, which must print nothing.

## What this is

`counit` is a small PHP library/CLI that makes time/IO-bound PHPUnit tests (`sleep()`, DB queries, HTTP calls) run
concurrently inside a single PHP process using Swoole coroutines, while staying compatible with plain PHPUnit. Without
the Swoole extension everything falls back to normal blocking PHPUnit behavior. This dual mode (coroutine vs.
blocking) is the central design constraint of the codebase: every change must behave correctly in both.

## Branches and versions

| | `master` | `0.x` |
|---|---|---|
| Release series | 1.x (install as `^1.1`) | 0.3.x (install as `^0.3`) |
| PHPUnit | `~12.5.24 \|\| ~13.0` | `~8.0 \|\| ~9.0` |
| PHP | >= 8.3 (PHPUnit 12.5), >= 8.4.1 (PHPUnit 13) | >= 7.2 |
| Test metadata | attributes (`#[DataProvider]`, ...) | annotations (`@dataProvider`, ...) |
| Registering `CounitExtension` | `<bootstrap class="..."/>` in `<extensions>` | `<extension class="..."/>` in `<extensions>` |

- PHPUnit releases before 12.5.24 are deliberately unsupported on `master`: they lack the `invokeTestMethod()` hook, so
  automatic-approach tests would silently run blocking.
- `0.x` is not bug-fix-only: compatibility work developed on `master` is back-ported wherever it applies.
- Tags carry no `v` prefix. `CHANGELOG.md` exists only on `master` and covers both series; when a change is ported,
  the 0.3.x entry is written alongside the 1.x one and may defer to it.
- The remote branches `0.3.x`, `9.x` and `10.x` are stale 2024 leftovers, not release lines; the 0.3.x *series* is
  released from `0.x`. PHPUnit 10 support was attempted once and deliberately dropped: no PHPUnit 10 release is both
  hookable and free of CVE-2026-24765.

## Architecture

### Shared core

- **`src/Helper.php`** — `isCoroutineFriendly()` is the single gate used everywhere: true only when `swoole` is loaded
  *and* code runs inside a coroutine. `coroutineHookFlags()` is the hook mask: `SWOOLE_HOOK_ALL` minus STDIO, FILE and
  PROC, because PHPUnit itself writes output/files and spawns process-isolation children *between* tests, where a
  yield would let pending coroutines run outside any assertion-counting window. Swoole only honors hook flags set
  before the scheduler starts, so the authoritative call is in the `counit` script.
- **`src/Counit.php`** — the coroutine primitive. `create(callable, int $count = 0)` runs the callback in a new
  coroutine when coroutine-friendly, otherwise calls it directly; `$count` credits assertions up front to suppress
  "did not perform any assertions" (credits are reconciled at the end of the run). A Throwable thrown before the
  coroutine first yields is rethrown synchronously; one thrown after is deferred (Swoole would otherwise kill the
  process). `createAndJoin()` waits for the coroutine to finish — the "join" used throughout. `sleep()` uses
  `Swoole\Coroutine::sleep()` when possible.
- **`src/CounitExtension.php`** — the PHPUnit extension. At the end of the run it drains all coroutines, reports the
  verdicts that only became known after a yield, and corrects the reported assertion total
  (`reported − credits + post-drain residue`) so the summary matches a blocking run exactly.
- **`counit`** (root executable) — the PHPUnit CLI entry point. With Swoole it sets the hook flags, runs PHPUnit inside
  `Swoole\Coroutine\run()`, and computes the exit code back outside the coroutine (Swoole intercepts `exit()` inside
  one). Failures that could not be reported through PHPUnit are printed after the summary and force a non-zero exit.

### The core problem

Under counit, the test body runs in a coroutine and hands control back to PHPUnit at its *first yield*. PHPUnit takes
that as "the test is over" and goes on to verify, record and count it. Everything beyond the core is one of three
remedies:

1. **Join** — wait for the body when correctness needs it, trading that test's concurrency for native PHPUnit
   behavior. Per-test joins cover `@depends`/`#[Depends]` producers (`DependencyMap`), exception expectations
   (`ExceptionExpectations`), mock invocation-count expectations (`MockExpectations`), customized post-conditions
   (`PostConditions`) and backed-up global state (`GlobalState`). Run-level joins serialize the whole run and print a
   STDERR notice: `--enforce-time-limit` (`TimeLimit`) and the `--stop-on-*` options (`VerdictSequencing`). Never add
   a run-level join for a common CI flag (`--fail-on-risky` was deliberately rejected): it would silently serialize
   real runs.
2. **Late reporting** — verdicts that only exist after a yield (failures, errors, skips, risky tests) are reported to
   PHPUnit after the end-of-run drain, so summaries, listings, exit codes and reports come out as in blocking mode.
3. **Correction** — results PHPUnit already wrote are fixed up: `JunitXmlCorrector` (per-test assertion counts,
   verdict elements, times), `HistoryCorrector` (result cache / test-run history), `Coverage` (one extra coverage
   window around the drain, so aggregate coverage is exact).

Supporting pieces: `Attribution` (attributes assertion-counter increments to the coroutine that made them),
`UselessTests` (the "no assertions" risky check), `Diagnostics` (deprecations/warnings/notices raised after a yield),
`OutputCapture` (Swoole gives every coroutine its own output-buffer stack) and `CoroutineGroup` (a nesting-safe
substitute for `Swoole\Coroutine\Scheduler`).

Each class's docblock explains its design, the alternatives rejected and its known residuals; user-facing behavior is
documented per feature in `docs/compatibility.md`.

### `master` specifics

- `TestCase` overrides `invokeTestMethod()` (PHPUnit 13, backported to 12.5.24) and wraps only the test body in
  `Counit::create(..., 1)`; `setUp()` runs outside the coroutine. PHPUnit's final `runBare()` treats the body's first
  yield as the end of the test, so `tearDown()`/`#[After]` are *taken over*: PHPUnit's cached hook collection is pointed
  at a method nothing declares, and the hooks are replayed inside the coroutine right after the body.
  `tearDownCoroutine()` (and `Counit::defer()` for the manual approach) always observe a finished body.
- `Helper::invocationRunsTests()` routes CLI commands (`--version`, `--help`, ...) and invalid usage to the blocking
  path; on any unexpected failure it keeps the concurrent path.
- Extra joins: output expectations, plus the run-level `--disallow-test-output` (`OutputExpectations`) and
  `--repeat`/`--retry` (`VerdictSequencing`).
- Late reporting replays PHPUnit's own events from `ExecutionFinished`: `LateSkips`, `LateFailures`, `UselessTests`,
  `HandlerIsolation` (error/exception-handler leak checks). Every late emitter must go through
  `JunitXmlCorrector::shieldLogger()`: an unshielded replayed event crashes the JUnit logger, whose state has already
  unwound by then.
- `Diagnostics` registers its delegating error handler *only* while the main coroutine is suspended; a permanently
  registered handler silently disables PHPUnit 12.5's own.

### `0.x` specifics

- `TestCase` overrides `runBare()` and runs the *whole* `runBare()` — `setUp()`, body, `tearDown()`, mock verification,
  post-conditions — inside the coroutine. Many `master` problems therefore never affected the automatic approach here;
  only a signal that *throws* out of `runBare()` after a yield (a skip, an incomplete, a failed verification) does. The
  manual approach shares `master`'s problems, so ports often land there only.
- PHPUnit 8/9 have no extension `bootstrap()`: run-level settings are read lazily from the first test's `TestResult`,
  and `AssertionCountListener` (a TestListener) provides the per-test seams.
- Late reporting hands verdicts to the public `TestResult::addFailure()`/`addError()` from
  `CounitExtension::executeAfterLastTest()`.
- `Diagnostics` throws converted deprecations/warnings/notices at the trigger site, as PHPUnit 8/9 do.

### Porting between `master` and `0.x`

Fixes are usually *adapted* rather than cherry-picked:

- Guard manual-approach logic in `0.x`'s `Counit::create()` with `!$caller instanceof Deminy\Counit\TestCase`, so the
  automatic wrapper's own `create()` call is not wrapped or joined twice.
- PHPUnit 8.0 lacks some APIs 9.x has (e.g. `@postCondition` exists only from 9.1): feature-detect rather than assume.
- Verify `0.x` on PHPUnit 9.6 and 8.0.x (the floor), `master` on 13.x and 12.5.x, each with and without Swoole.
- Finish each port with the "matrix follow-up": update the other branch's `docs/compatibility.md` (see below).

### Assertion counting

PHPUnit attributes assertions through a per-test window over one static counter (`Assert::$count`): reset at a test's
start, harvested at its end. Under counit a delayed assertion is either harvested into whichever test's window is open
(misattributed, but still in the total), left in the counter after the last window (the residue), or — only if
something yields between one harvest and the next reset — wiped. The hook mask prevents the last case and the
credits-plus-residue correction reconciles the other two, so run totals are exact and deterministic. Per-test numbers
are corrected in the JUnit report via `Attribution`. A miscounted run still exits 0, so tests and CI assert exact
summary lines, not just exit codes.

### Relying on PHPUnit internals

counit reaches into PHPUnit internals (private properties, `@internal` classes) where no public seam exists. Rules:

- Every such access is fail-soft: on failure, fall back to the pre-existing behavior and print a one-time STDERR
  notice (silenced with `COUNIT_SILENCE_TEARDOWN_NOTICE=1`). Never fail a run, and never degrade silently.
- Reflect a private property on its *declaring* class; it is invisible through a subclass.
- On `master`, instantiate PHPUnit internals through `Helper::newPhpunitObject()`. PHPUnit 13.4.1 added a required
  `Emitter` constructor parameter to `CliArguments\Builder` and `Metadata\Api\HookMethods`; a bare `new` broke routing,
  the hook takeover and post-condition joins at once, and every run still exited 0.
- New PHPUnit patch releases can move internals without notice. The weekly CI runs exist to catch that.

## Test suites and approaches

Consumers write tests in one of two approaches, side by side in `tests/unit/`:

- **Automatic approach** (`tests/unit/automatic/`) — extend `Deminy\Counit\TestCase`; no other changes needed.
- **Manual approach** (`tests/unit/manual/`) — extend `PHPUnit\Framework\TestCase`, wrap the body in
  `Counit::create()`, and use `Counit::sleep()` instead of `sleep()`. Documented as an escape hatch.

Use the branch's metadata style (see the table above). On `master`, PHPUnit 12+ ignores doc-comment annotations, so a
stray `@dataProvider` silently breaks a test; data providers must be static.

`tests/unit/compatibility/` checks edge cases against real PHPUnit behavior in both approaches. `tests/regression/`
holds fixtures that are *expected* to fail, skip or report risky; the compatibility workflow runs them individually
and asserts their exact output. When touching `TestCase`, `Counit::create()`, `CounitExtension` or `Helper`, run all
suites with and without Swoole: behavior regresses easily in one mode while the other stays green.

Process-isolated tests (`@runInSeparateProcess`/`#[RunInSeparateProcess]`, `--process-isolation`) run blocking in a
plain child process and count exactly as under PHPUnit. The `counit` script registers itself in PHPUnit's
isolation exclude list (`__PHPUNIT_ISOLATION_EXCLUDE_LIST`, also `__PHPUNIT_ISOLATION_BLACKLIST` before PHPUnit 9.3)
so that, run through the Composer bin proxy, the child does not re-execute the whole entry script (pinned by
`ProcessIsolationBinProxyTest`).

## Compatibility documentation

Both branches document compatibility in `docs/compatibility.md`: two matrix tables (compatible and incompatible
features, each row with a `Counit 1.x` and a `Counit 0.x` cell), followed by "Feature notes" written from that
branch's perspective.

**Whenever a compatibility issue is fixed, introduced or discovered on either branch, update the matrix tables on BOTH
branches**: move or reword the row, adjust its ✅/⚠️/❌ status and both version cells, and keep it consistent with
the branch's feature notes. The other branch's copy is updated in its own commit.

Rows are grouped by area (writing tests, fixtures and hooks, test doubles, outcomes and diagnostics, execution control,
reporting) under a bold group row, ordered from the most to the least widely used feature. Keep the first column to the
feature's name; qualifiers belong in the verdict cells.

## Local development environment

Docker Compose (`docker-compose.yml`) starts five containers: `php` (no Swoole; blocking baseline), `swoole`, `mysql`,
`redis` and `web` (`deminy/delayed-http-response:1.0.0`, serving `http://web/sleep/:seconds` for the CURL tests). The
`php`/`swoole` images are built locally from `dockerfiles/` with the build args `PHP_VERSION` and `SWOOLE_VERSION`
(defaults: PHP 8.5 and Swoole 6.2 on `master`; PHP 7.4 and Swoole 4.8 on `0.x`).

```bash
docker compose up -d --build
docker compose exec -ti swoole composer install -n
```

After switching branches, delete the gitignored `composer.lock`, then rebuild the images and reinstall dependencies:
the images and `vendor/` differ between the branches (PHPUnit 13 vs 9), and a stale stack makes `counit` refuse to start
or test the wrong version.

## Running tests

Three suites are defined in `phpunit.xml.dist`: `automatic`, `manual` and `compatibility`.

```bash
# Blocking mode (~48s per suite): plain PHPUnit, or counit without Swoole -- identical behavior.
docker compose exec -ti php    ./vendor/bin/phpunit --testsuite automatic
docker compose exec -ti php    ./counit --testsuite automatic

# counit with Swoole -- the concurrent path (~7s per suite):
docker compose exec -ti swoole ./counit --testsuite automatic
docker compose exec -ti swoole ./counit --testsuite manual

# Compatibility suite (no Docker needed, only a local PHP that the branch supports and composer install):
./counit --testsuite compatibility
```

Expected results (blocking and counit + Swoole, unless noted):

| Suite | `master` | `0.x` |
|---|---|---|
| `automatic` | `OK (16 tests, 24 assertions)` | `OK (16 tests, 24 assertions)` |
| `compatibility` | `OK (64 tests, 78 assertions)` | `OK (49 tests, 60 assertions)` |
| `manual`, blocking | `Tests: 38, Assertions: 62, Skipped: 2.` | same as `master` |
| `manual`, counit + Swoole | `Tests: 38, Assertions: 64, Skipped: 1.` | same as `master` |

`manual` differs by design: some `CoroutineGroupTest` tests only make sense inside (or outside) a coroutine. That
difference doubles as a check — a Swoole run that silently fell back to blocking mode prints the blocking line, and
CI fails on it. Always check the summary line, not just the exit code.

Six blocking-mode runs take ~290s in total; budget timeouts accordingly.

## Static analysis

PHPStan `^2.0` at level 9 over `src/` and `tests/` (`phpstan.neon.dist`). It needs the `swoole` extension loaded and
reports differently across PHP versions, so run it on the PHP and Swoole versions CI uses — PHP 8.4 with Swoole 6.2 on
`master`, PHP 8.1 with Swoole 5.1 on `0.x`:

```bash
IMAGE=phpswoole/swoole:6.2-php8.4-alpine   # on 0.x: phpswoole/swoole:5.1-php8.1-alpine
docker run --rm -v "$(pwd):/app" -w /app "$IMAGE" sh -c \
  'composer global require phpstan/phpstan=^2.0 -nq --no-progress &&
   "$(composer global config bin-dir --absolute)/phpstan" analyse --no-progress --memory-limit 2G'
```

## Coding style

Rules live in `.php-cs-fixer.dist.php`. CI checks with the command below (drop `--dry-run` to apply fixes); the image
is `jakzal/phpqa:php8.4-alpine` on `master` and `jakzal/phpqa:php8.2-alpine` on `0.x`. CI uses the *latest* fixer from
that image, so style failures can appear without a code change.

```bash
docker run -q --rm -v "$(pwd):/project" -w /project -i jakzal/phpqa:php8.4-alpine php-cs-fixer fix --dry-run
```

Docblocks must not contain the literal `*/` — e.g. writing `--stop-on-*/--repeat` ends the comment early.

## CI workflows (`.github/workflows/`)

- `unit_tests.yml` — Docker Compose based; both approaches under plain PHPUnit and under counit, with and without
  Swoole. PHPUnit ~12.5.24, ~13.0.0 and ~13.0 on `master`; ~8.0.0, ~8.0, ~9.0.0 and ~9.0 on `0.x`.
- `compatibility_tests.yml` — the `compatibility` suite plus the `tests/regression/` steps on GitHub-hosted runners,
  with and without Swoole. On `master`: PHPUnit 12.5.24 and ~12.5.24 on PHP 8.3, ~13.0.0 and ~13.0 on PHP 8.4. On
  `0.x`: PHPUnit ~8.0.0, ~8.0, ~9.0.0 and ~9.0 on PHP 7.4, plus ~8.0 and ~9.0 on PHP 8.2.
- `static_analysis.yml` — PHPStan, on PHP 8.4 (`master`) or 8.1 (`0.x`).
- `coding_style_checks.yml` — php-cs-fixer, as above.
- `syntax_checks.yml` — `phplint` on PHP 8.3–8.5 (`master`) or 7.2–8.3 (`0.x`).
- Both test workflows run weekly as well, so a breaking PHPUnit release is noticed without a push.
- The compatibility workflow `composer require`s the matrix's PHPUnit version; a plain install (there is no committed
  lock file) always resolves the newest release PHP allows.
- On `0.x` it also installs `phpunit/php-invoker` for PHPUnit 8, which only *suggests* it but needs it for
  `--enforce-time-limit`.
