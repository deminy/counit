# Changelog

All notable changes to _counit_ are documented here. Each entry mirrors the corresponding
[GitHub release](https://github.com/deminy/counit/releases); the hashes in parentheses identify the commits that made
each change.

Two release series are maintained in parallel: the **1.x** series (branch `master`) targets PHPUnit ~12.5.24 /
~13.0, while the **0.x** series (branch `0.x`) is the maintenance line for PHPUnit ~8.0 / ~9.0. Tags carry no `v`
prefix.

## 1.1.4 - 2026-08-30

Feature release for the 1.x series: adds `CoroutineGroup` for testing coroutine-native code directly. Supported
versions are unchanged (~12.5.24 on PHP >= 8.3, ~13.0 on PHP >= 8.4.1), and no existing test or consumer project
needs changing.

### Added

- **`Deminy\Counit\CoroutineGroup`, a nesting-safe substitute for `Swoole\Coroutine\Scheduler`**, for tests that
  manage their own coroutines directly (a mutex, a queue, or other coroutine-native code). `run(...$callables)` is
  a drop-in replacement safe under plain PHPUnit and counit, with or without Swoole; `runWithTimeout(float $seconds,
  ...$callables)` adds a bounded wait. See [`docs/coroutine-native-testing.md`](docs/coroutine-native-testing.md).
  (de8cd89, 81ddb96, 7717428, e78cd02, c218692, 772cb20)

### Changes

- **"The Manual Approach" is documented as an escape hatch**, not a co-equal alternative to the automatic approach.
  (4b09c62)
- **The coroutine-native-testing guide and the benchmark comparisons moved to [`docs/`](docs/)**. (de72ab4, 4b09c62)

**Full changelog**: https://github.com/deminy/counit/compare/1.1.3...1.1.4

## 1.1.3 - 2026-08-29

Documentation release for the 1.x series. Supported versions are unchanged (~12.5.24 on PHP >= 8.3, ~13.0 on
PHP >= 8.4.1), and no test or consumer project needs changing — consumer-side PHPStan errors simply disappear.

### Changes

- **`TestCase` and `CounitExtension` are no longer marked `@internal`**: they are the documented API, and the tag
  made PHPStan >= 2.1.13 and PhpStorm report consumer suites extending `Deminy\Counit\TestCase` — counit's internal
  machinery keeps its `@internal` tags, mirroring PHPUnit's own split. (60598f4)

**Full changelog**: https://github.com/deminy/counit/compare/1.1.2...1.1.3

## 1.1.2 - 2026-08-28

Bug-fix release for the 1.x series. Supported versions are unchanged (~12.5.24 on PHP >= 8.3, ~13.0 on
PHP >= 8.4.1), and no test or consumer project needs changing.

### Bug fixes

- **Process-isolated tests no longer break when counit runs through its Composer bin proxy** (`vendor/bin/counit` —
  how every consumer invokes it). PHPUnit replays the parent's included files into a global-state-preserving
  isolated child, dropping the entry script but special-casing only its *own* bin proxy — so counit's real binary,
  the second included file behind the proxy, was re-executed in the child, and every such test errored with "Test
  was run in child process and ended unexpectedly". The `counit` script now registers its paths in
  `$GLOBALS['__PHPUNIT_ISOLATION_EXCLUDE_LIST']`; the autoloader and counit's classes still reach the child, which
  keeps its plain blocking behavior. Swoole-independent, pinned by a new compatibility test rebuilding the proxy
  shape. Isolation still gains nothing from counit; [`docs/compatibility.md`](docs/compatibility.md) has details.
  (ad211fc)

**Full changelog**: https://github.com/deminy/counit/compare/1.1.1...1.1.2

## 1.1.1 - 2026-08-27

Documentation and diagnostics release for the 1.x series. The supported PHPUnit and PHP versions are unchanged
(~12.5.24 on PHP >= 8.3, ~13.0 on PHP >= 8.4.1), and no test needs changing.

**Upgrade note.** A run whose `phpunit.xml` does not register `CounitExtension` now prints one notice line on
STDERR. Exit codes are untouched, and `COUNIT_SILENCE_TEARDOWN_NOTICE=1` silences it alongside counit's other
notices.

### Added

- **A missing `CounitExtension` registration is announced instead of quietly invalidating the run.** The extension
  is what waits for every coroutine to drain before PHPUnit takes its summary; without it nothing does, so the run
  reports a time that can understate the truth by orders of magnitude and assertion totals that are neither correct
  nor consistent between a full run and the same tests run in isolation — while exiting 0 and looking entirely
  healthy. The run's first coroutine now writes a notice to STDERR naming the omission, what it costs, and the
  `<extensions><bootstrap class="Deminy\Counit\CounitExtension"/></extensions>` element that fixes it. Registration
  is observed through the extension's own `bootstrap()` call, which PHPUnit makes once per registered extension, so
  a correctly configured run cannot be false-accused; a run without Swoole, or a suite that creates no coroutine,
  stays quiet. (bdf94f9)

### Changes

- **`CounitExtension` is documented as required, not optional.** README.md listed registering it under "Optional
  steps" and [`docs/compatibility.md`](docs/compatibility.md) never mentioned it at all, though nearly every
  guarantee in that matrix depends on it. Both now say so, and both record that the registration element differs by
  release line — `<bootstrap class>` on PHPUnit 10+ (counit `^1.1`) versus `<extension class>` on PHPUnit 8/9
  (counit `^0.3`), since the class implements different interfaces on each — so a snippet copied across lines fails
  silently. The class docblock carries the snippet too, for anyone arriving from a stack trace rather than the
  README. (8fd6b81)
- **The install instructions point at the current releases.** The documented constraint `~1.0.0` reads as
  >= 1.0.0 < 1.1.0, so anyone following the README pinned themselves to the previous line. It is now `^1.1`, which
  picks up future 1.x minors; the maintenance line's row becomes `^0.3`, which stops at the next 0.x minor — on a
  zero-major, where a breaking change may land. (054cd4c)

**Full changelog**: https://github.com/deminy/counit/compare/1.1.0...1.1.1

## 1.1.0 - 2026-08-27

A compatibility release: where a counit run used to diverge from a plain PHPUnit run, it now matches. Supported
versions are unchanged (~12.5.24 on PHP >= 8.3, ~13.0 on PHP >= 8.4.1) and no test needs changing. Feature-by-feature
detail lives in [`docs/compatibility.md`](docs/compatibility.md).

**Upgrade note.** Checks that used to stay silent under Swoole now report, so a suite that passed under 1.0.x may
legitimately fail under 1.1.0 — a post-yield deprecation/warning/notice, a post-yield skip, and the "did not perform
any assertions" risky check are all counted now, `--fail-on-*` included. Each report matches what plain PHPUnit says
about the same test. `--enforce-time-limit`, the global-state backup settings and the `--stop-on-*` family serialize
the run in exchange for exact semantics.

### Added

- **Verdicts reached after a test's first yield are PHPUnit's own again.** Failures and errors, skips and
  incompletes, risky verdicts and converted diagnostics are replayed through PHPUnit's events once every coroutine
  has drained, so summaries, listings, exit codes and the JUnit report match a blocking run. A post-yield
  `E_USER_ERROR`, which used to kill the run with exit code 255, now errors its test. (6bf1ab5, 9cc1a3b, 88da440,
  7fad7af, c78b4ad)
- **Expectations and test doubles work when the interesting thing happens after a yield**: `expectException()` and
  friends, `expectOutputString()`/`expectOutputRegex()`, and mock `->expects(...)` verification — the last of which
  used to *silently* pass a violated `never()`. Such tests are joined at their first yield; everything else keeps
  running concurrently. (01f853c, 33c98b8, a1b0cb8)
- **The test lifecycle observes a finished body.** `tearDown()`/`#[After]` hooks run after the body instead of at
  its first yield, `assertPostConditions()`/`#[PostCondition]` see a finished test, and `tearDownCoroutine()` /
  `Counit::defer()` offer body-ordered cleanup homes. (8caa214, 274dee1, 1db358c)
- **`#[Depends]` and its variants have exact semantics**: dependents receive the producer's real return value and
  are skipped when it fails, even after a yield. (1a64cb1, 94cd806, f5cbf24)
- **Runner options behave as documented**: `--enforce-time-limit` times the real test, the global-state backup
  attributes isolate what they should, the `--stop-on-*` family (plus `--repeat`/`--retry`) sequences verdicts
  correctly, and invocations that run no tests (`--version`, `--help`, `--list-*`, bad input) exit like plain
  PHPUnit instead of dying with 255. The first three serialize the run while active. (c0acef9, ba76648, 889d637,
  49f32b9)
- **Reporting is accurate**: aggregate code coverage no longer loses post-yield lines, the result cache records
  real defects and durations, and the JUnit report carries corrected per-test counts, durations and verdicts.
  (75b7c8e, 9f22e8b, d8d7ab1)

### Bug fixes

- **`--log-junit` combined with a post-yield skip killed the run** (exit code 255, zero-byte report). (e459567)
- **Only one failing repetition was reported under `--repeat`**; each occurrence now gets its own entry. (4f3a90f,
  4902866)
- **Assertions counted on the test object after its first yield were lost** from the run's total. (eb4b3f5)

### Changes

- **Renamed the two test-adaptation approaches**: the "global" style is now the **automatic approach** and the "case
  by case" style the **manual approach**, with the test suites and sample directories renamed to match
  (`--testsuite global` → `--testsuite automatic`, `tests/unit/global/` → `tests/unit/automatic/`, and likewise for
  the manual one). No PHP API changed, so consumer projects need no edits. (9397f6d)
- **The compatibility documentation moved to [`docs/compatibility.md`](docs/compatibility.md)**, leaving README.md a
  short section and a link. (ba9f057, 17c3a9a)
- **Modernized the codebase for the PHP 8.3 floor.** (2d3a23b)

**Full changelog**: https://github.com/deminy/counit/compare/1.0.2...1.1.0

## 1.0.2 - 2026-07-27

Bug-fix release for the 1.0.x series. The supported PHPUnit and PHP versions are unchanged (~12.5.24 on PHP >= 8.3,
~13.0 on PHP >= 8.4.1).

### Bug fixes

- **Stop losing assertions across process-isolated tests.** `SWOOLE_HOOK_ALL` includes `SWOOLE_HOOK_PROC`, so the
  `proc_open()` call and the pipe reads PHPUnit uses to run a `#[RunInSeparateProcess]` test yielded the main
  coroutine while it awaited the child process. No assertion-counting window is open then, so a coroutine left pending
  by an earlier test could resume in that gap and have its assertions wiped by the next test's `Assert::resetCount()`
  — the same failure mode the STDIO/file exclusions already guard against, and a silent one: the run still exited 0,
  it just reported fewer assertions than a blocking run. `SWOOLE_HOOK_PROC` is now excluded as well, so an isolated
  test blocks the run for the duration of its child process instead of yielding. It gains nothing from counit either
  way, since the child process is plain non-coroutine PHP. New compatibility tests cover both styles,
  `#[RunInSeparateProcess]` and class-level `#[RunTestsInSeparateProcesses]`, and the compatibility workflow now
  asserts the exact summary line rather than only the exit code. (339f610)

### Housekeeping

- Upgrade the CI runners to `ubuntu-24.04` and bump the actions in step, including the `isbang/compose-action` and
  `nick-invision/retry` repository renames. (3f4aa7e)

**Full changelog**: https://github.com/deminy/counit/compare/1.0.1...1.0.2

## 1.0.1 - 2026-07-22

### Changes

- **Support PHPUnit ~12.5.24 on PHP >= 8.3**, alongside the existing PHPUnit ~13.0 on PHP >= 8.4.1. PHPUnit 12.5.24 backported the `TestCase::invokeTestMethod()` hook counit relies on ([phpunit#6596](https://github.com/sebastianbergmann/phpunit/issues/6596)); the composer constraint is now `~12.5.24 || ~13.0`, the `counit` script's PHP version gate was lowered to 8.3 accordingly, and the CI matrices cover both PHPUnit lines. Earlier PHPUnit 12 releases remain deliberately unsupported — they lack the hook, so "global style" tests would silently run in blocking mode instead of failing loudly. (ddcbbbe)
- **Document the counit/PHPUnit compatibility matrix** under the README's Installation section:

  | counit | PHPUnit | PHP |
  |--------|--------------------|-----------|
  | ~1.0.0 | ~13.0              | >= 8.4.1  |
  | ~1.0.0 | ~12.5.24           | >= 8.3    |
  | ~0.2.0 | ~8.0, ~9.0         | >= 7.2    |

  (a3a77d2)

**Full changelog**: https://github.com/deminy/counit/compare/1.0.0...1.0.1

## 1.0.0 - 2026-07-22

Major release: counit now targets **PHPUnit ~13.0** on **PHP >= 8.4.1**.

### Breaking changes

- **Requires PHP >= 8.4.1 and `phpunit/phpunit` ~13.0** (previously PHP >= 7.2 with PHPUnit ~8.0 || ~9.0). If you need older PHPUnit versions, stay on the [0.2.x release series](https://github.com/deminy/counit/releases/tag/0.2.2). Internally, the PHPUnit 10+ API is used throughout: the per-test coroutine wrapping moved from `runBare()` (now final) to the `invokeTestMethod()` hook, `CounitExtension` became an event-based extension registered via the `<bootstrap>` syntax in `phpunit.xml.dist`, and the `counit` script drives `PHPUnit\TextUI\Application`. (706f8e4)

### Bug fixes

- **Fail the run when a test's failure happens after a sleep/IO yield.** `Counit::create()` returns to its caller as soon as the coroutine finishes *or yields* — that's what lets tests run concurrently. If a test's assertion or exception fired only after such a yield, PHPUnit had already recorded a pass: the failure was silently swallowed in the global style and fatally crashed the process in the case-by-case style. Such failures are now caught, reported explicitly at the end of the run, and force exit code 1. (178a60d)
- **`expectException()` no longer crashes the process under the global style**: a Throwable thrown inside the test coroutine is re-thrown where PHPUnit's exception handling can see it, since Swoole does not propagate uncaught Throwables out of a coroutine. (eb4e725)

### Improvements

- **Exact assertion totals under Swoole.** The run summary now reports exactly what a blocking PHPUnit run would (e.g. `OK (16 tests, 24 assertions)`), with no risky-test noise: STDIO/file operations are excluded from the coroutine hooks so PHPUnit's own inter-test output can no longer swallow delayed assertion counts, and an end-of-run correction reconciles the total (reported − up-front credits + post-drain residue). Per-test attribution in verbose output may still differ; see README. (27c5e47)
- Sample tests migrated to PHPUnit attributes (`#[DataProvider]`, `#[CoversNothing]`, …) with static data providers, and freed of PHP 8.5 deprecation notices (`curl_close()` removed). (dff9047, bc0215c)
- Docker images for the sample tests are now built locally via `docker-compose` (parameterized by `PHP_VERSION`/`SWOOLE_VERSION` build args); the image publishing workflow was removed. (9cd33e2)
- CI updated: PHPUnit ~13.0 matrix on PHP 8.4, syntax checks on PHP 8.4 and 8.5, static analysis with PHPStan ^2.0 at level 9. (2373e2d, 1ce4c0b)

**Full changelog**: https://github.com/deminy/counit/compare/0.2.1...1.0.0

## 0.3.4 - 2026-08-30

Feature release for the PHPUnit 8/9 maintenance series, mirroring 1.1.4. Supported versions are unchanged
(PHPUnit ~8.0 / ~9.0 on PHP >= 7.2), and no existing test or consumer project needs changing.

### Added

- **`Deminy\Counit\CoroutineGroup`** — same feature as 1.1.4, ported to this line's PHP >= 7.2 floor. (fcbfd44,
  32de793)

### Changes

- **Same doc changes as 1.1.4** ("The Manual Approach" reframed as an escape hatch; coroutine-native-testing and
  benchmark docs moved to `docs/`). (f8873e5)

**Full changelog**: https://github.com/deminy/counit/compare/0.3.3...0.3.4

## 0.3.3 - 2026-08-29

Documentation release for the PHPUnit 8/9 maintenance series, mirroring 1.1.3. Supported versions are unchanged
(PHPUnit ~8.0 / ~9.0 on PHP >= 7.2), and no test or consumer project needs changing.

### Changes

- **`TestCase` and `CounitExtension` are no longer marked `@internal`** — same change as 1.1.3, on this line's
  branch. (f4e340b)

**Full changelog**: https://github.com/deminy/counit/compare/0.3.2...0.3.3

## 0.3.2 - 2026-08-28

Bug-fix release for the PHPUnit 8/9 maintenance series, mirroring 1.1.2. Supported versions are unchanged
(PHPUnit ~8.0 / ~9.0 on PHP >= 7.2), and no test or consumer project needs changing.

### Bug fixes

- **Any process-isolated test hung the run when counit ran through its Composer bin proxy** (`vendor/bin/counit`),
  spawning child processes without bound until killed. Same root cause as 1.1.2 — the replay of the parent's
  included files re-executed counit's binary in the child — but PHPUnit 8/9 preserve global state by *default*, so
  every isolated test was affected, and the child's PHPUnit run re-discovered the isolated test class and recursed
  (observed downstream in swoole/library). The exclude-list registration covers both spellings —
  `__PHPUNIT_ISOLATION_EXCLUDE_LIST` as of PHPUnit 9.3, `__PHPUNIT_ISOLATION_BLACKLIST` since 8.0.0 — so every
  supported version is fixed; on PHP < 8 Composer's proxy includes through its `phpvfscomposer://` wrapper, a shape
  that was never affected. Verified across the support matrix, with and without Swoole. (9c34927, 76b5fb2)

**Full changelog**: https://github.com/deminy/counit/compare/0.3.1...0.3.2

## 0.3.1 - 2026-08-27

Documentation and diagnostics release for the PHPUnit 8/9 maintenance series, mirroring 1.1.1. Supported versions
are unchanged (PHPUnit ~8.0 / ~9.0 on PHP >= 7.2), and no test needs changing.

**Upgrade note.** A run whose `phpunit.xml` does not register `CounitExtension` now prints one notice line on
STDERR. Exit codes are untouched, and `COUNIT_SILENCE_TEARDOWN_NOTICE=1` silences it alongside counit's other
notices.

### Added

- **A missing `CounitExtension` registration is announced instead of quietly invalidating the run.** The extension
  is what waits for every coroutine to drain before PHPUnit takes its summary; without it nothing does, so the run
  reports a time that can understate the truth by orders of magnitude and assertion totals that are neither correct
  nor consistent between a full run and the same tests run in isolation — while exiting 0 and looking entirely
  healthy. The run's first coroutine now writes a notice to STDERR naming the omission, what it costs, and the
  `<extensions><extension class="Deminy\Counit\CounitExtension"/></extensions>` element that fixes it. PHPUnit 8/9
  have no extension `bootstrap()` method, so registration is observed through the `BeforeFirstTestHook` this class
  already implements — PHPUnit calls it for every registered extension before the first test — which no
  configuration shape can register the extension without firing; a run without Swoole, or a suite that creates no
  coroutine, stays quiet. Verified on PHPUnit 9.6.36 and on 8.0.6, the matrix floor. (d2742ec)

### Changes

- **`CounitExtension` is documented as required, not optional.** README.md listed registering it under "Optional
  steps" and [`docs/compatibility.md`](docs/compatibility.md) never mentioned it at all, though nearly every
  guarantee in that matrix depends on it. Both now say so, and both record
  that the registration element differs by release line — `<extension class>` on PHPUnit 8/9 (counit `^0.3`) versus
  `<bootstrap class>` on PHPUnit 10+ (counit `^1.1`), since the class implements different interfaces on each — so
  a snippet copied across lines fails silently. The class docblock carries the snippet too, for anyone arriving
  from a stack trace rather than the README. (9e01e12)
- **The install instructions point at the current releases.** The documented constraint `~0.2.0` reads as
  >= 0.2.0 < 0.3.0, so anyone following the README pinned themselves to the previous line. It is now `^0.3`, which
  stops at the next 0.x minor — on a zero-major, where a breaking change may land, and the opt-in boundary 0.3.0's
  behavioral changes want; the table's 1.x rows become `^1.1` in step. The branch note is corrected as well: it
  promised bug fixes and security updates only, which 0.3.0 was not — this line now takes the compatibility work
  the 1.x line develops, back-ported where it applies. (8243c5c)

**Full changelog**: https://github.com/deminy/counit/compare/0.3.0...0.3.1

## 0.3.0 - 2026-08-27

Feature release for the PHPUnit 8/9 maintenance series, bringing it in step with what the 1.x line learned about
running a suite concurrently. Supported versions are unchanged (PHPUnit ~8.0 / ~9.0 on PHP >= 7.2) and no test needs
changing; [`docs/compatibility.md`](docs/compatibility.md) has the feature-by-feature detail.

**Upgrade note.** Checks that used to stay silent under Swoole now report, so a suite that passed under 0.2.3 may
legitimately fail under 0.3.0. The sharpest case: a deprecation, warning or notice triggered after a test's first
yield reached no handler at all, so the test silently passed and the run exited 0 where plain PHPUnit errors it and
exits 2. Post-yield failures, skips and risky verdicts are likewise reported instead of swallowed, and a run whose
only failure sat in the deferred block no longer exits 0. `--enforce-time-limit`, the `@backupGlobals` family and
the `--stop-on-*` options serialize the run in exchange for exact semantics.

### Added

- **Verdicts reached after a test's first yield are recorded again.** Failures, errors, skips, incompletes, risky
  verdicts and converted diagnostics are replayed into the run's `TestResult` after the drain, so summaries,
  listings, exit codes and the JUnit report match a blocking run — retiring this line's old "deferred block plus
  exit 1" reporting for hook throws, mock violations and diagnostics alike. (2f7e05f, 0a82d37, 1948bf5, ad8a249)
- **Expectations and test doubles work when the interesting thing happens after a yield**: `expectException()` and
  friends (PHPUnit 9's `expectWarning()` family included), `expectOutputString()`/`expectOutputRegex()`, and mock
  `->expects(...)` verification — on PHPUnit 8/9 even a matcher-less stub lost its `willReturn()` configuration at
  the first yield. (7bfa59f, 0b73966, 588d1a4)
- **`@depends` (all forms) has exact semantics**, including cross-class `Class::method`, the `clone`/`shallowClone`
  variants and `Class::class` (PHPUnit >= 9.3). (82b1bcc)
- **Runner options behave as documented**: `--enforce-time-limit` times the real test, `@backupGlobals` /
  `@backupStaticAttributes` isolate what they should, `assertPostConditions()`/`@postCondition` see a finished body,
  the `--stop-on-*` family sequences verdicts correctly, `--fail-on-*` is honored, and invocations that run no tests
  exit like plain PHPUnit instead of dying with 255. (d4f1a55, 004ef0b, 5a4e8c3, 91f679b, 37d0b66, 634aa79)
- **Reporting is accurate**: aggregate code coverage no longer loses post-yield lines, the result cache and the
  JUnit report carry real durations, and per-testcase assertion counts are corrected. (6633661, 558b854, d47d7cc)
- **`@doesNotPerformAssertions` and `expectNotToPerformAssertions()`** are supported in both approaches. (4c73a4c)

### Bug fixes

- **A failing run could exit 0**: the exit-code alignment ran after the deferred-failure check and reset its forced
  exit code, so a failure that lived only in that block was reported green. (99a24a2)
- **Every failing run exited 255 on PHPUnit 8.0**, through a shutdown fatal in that same alignment. (e6634ac)
- **Assertions counted on the test object after its first yield were lost** from the run's total. (b6f837f)
- **A late skip or `--repeat` could hang or corrupt shared test objects**; `--repeat` now runs in blocking mode.
  (ea54baa)

### Changes

- **Renamed the two test-adaptation approaches** to **automatic** and **manual**, matching the 1.x line, with the
  test suites and sample directories renamed to match (`--testsuite global` → `--testsuite automatic`,
  `tests/unit/global/` → `tests/unit/automatic/`, and likewise for the manual one). No PHP API changed. (e249a43)

### Housekeeping

- Regression suites for every behavior above run across the full support matrix (PHPUnit ~8.0.0, ~8.0, ~9.0.0, ~9.0
  on PHP 7.4 and 8.2), with and without Swoole; static analysis upgraded to PHPStan ^2.0; the compatibility
  documentation moved to [`docs/compatibility.md`](docs/compatibility.md). (810fd12, 801aee9)

**Full changelog**: https://github.com/deminy/counit/compare/0.2.3...0.3.0

## 0.2.3 - 2026-07-27

Maintenance release for the PHPUnit 8/9 series.

### Bug fixes

- **Fix the hang when a test runs in a separate process.** `SWOOLE_HOOK_ALL` includes `SWOOLE_HOOK_PROC`, so the `proc_open()` call and the pipe reads PHPUnit uses to run a test annotated with `@runInSeparateProcess` were routed through Swoole. Under counit with Swoole enabled that never completed: a single isolated test hung the whole run indefinitely, with no summary and no way to tell what it was waiting for. That hook is now excluded as well, for the same reason STDIO and file operations already were — PHPUnit uses all three outside any test's assertion-counting window. An isolated test now blocks the run for the duration of its child process instead of yielding; it gains nothing from counit either way, since the child process is plain non-coroutine PHP. (2fa66b3)

### Improvements

- **Exact assertion totals under Swoole.** The run summary now reports exactly what a blocking PHPUnit run would (e.g. `OK (16 tests, 24 assertions)`), with no risky-test noise; the global style previously reported 0 assertions and flagged every asserting test as risky. An end-of-run correction reconciles the total (reported − up-front credits + post-drain residue), and the global style now credits each test one assertion up front instead of calling `expectNotToPerformAssertions()`, which used to flag a test as risky whenever one of its assertions happened to run early. Per-test attribution in verbose output may still differ; see README. (798aa61, 70e7ce4)

### Housekeeping

- Docker images for the sample tests are now built locally via `docker-compose` (parameterized by `PHP_VERSION`/`SWOOLE_VERSION` build args); the image publishing workflow was removed, having only ever run on `master`. (84312b1)
- Document the counit/PHPUnit compatibility matrix under the README's Installation section, and state plainly that this series is the maintenance line for PHPUnit ~8.0/~9.0. (69183a7)
- Upgrade the CI runners to `ubuntu-24.04` and bump the actions in step, including the `isbang/compose-action` and `nick-invision/retry` repository renames. (b357ce6)

**Full changelog**: https://github.com/deminy/counit/compare/0.2.2...0.2.3

## 0.2.2 - 2026-07-22

Maintenance release for the PHPUnit 8/9 series.

### Bug fixes

- **Fail the run when a test's failure happens after a sleep/IO yield.** `Counit::create()` returns to its caller as soon as the coroutine finishes *or yields* (e.g. on `sleep()`/IO) — that's what lets tests run concurrently. If a test's assertion or exception fired only after such a yield, the escaping Throwable fatally crashed the whole run (exit 255, no summary). Such failures are now caught, reported explicitly at the end of the run, and force exit code 1. (10cd644)
- **Fix `TestCase::tearDownAfterClass()` calling `parent::setUpBeforeClass()`** — a copy/paste bug affecting any intermediate base class implementing those hooks. (58fe360)

### Improvements

- **Exclude STDIO/file operations from the coroutine hooks.** PHPUnit's own progress output between tests was Swoole-hooked and could let pending test coroutines resume at exactly the point where their assertion counts get wiped; with the exclusion, the case-by-case suite reports stable assertion totals. Network IO and `sleep()` — what this package exists to parallelize — stay hooked, and run time is unchanged. (910ed95)

### Housekeeping

- Apply current php-cs-fixer fixes; ignore the `.phplint.cache/` directory; remove the obsolete `version` attribute from `docker-compose.yml`; bump the CI retry action. (18de171, af1c434, 33942d3, d503bbe)

**Full changelog**: https://github.com/deminy/counit/compare/0.2.1...0.2.2

## 0.2.1 - 2024-01-12

This release, version 0.2.1, incorporates various updates, encompassing enhancements in Continuous Integration (CI) jobs, code quality, and documentation. Importantly, these updates do not involve any backward-incompatible changes.

Following this release, all future updates in the 0.2.x series will exclusively address bug fixes and security improvements. There will be no new feature additions or backward-incompatible changes in the forthcoming updates within the 0.2.x series.

Please be aware that the 0.2.x series is compatible solely with PHPUnit versions 8 and 9 and only on PHP 7.2 or higher. For compatibility with PHPUnit 10, please refer to the upcoming 0.3.x series.

**Full changelog**: https://github.com/deminy/counit/compare/0.2.0...0.2.1

## 0.2.0 - 2021-09-29

### Changes

- Support two test case styles: the [_global_](https://github.com/deminy/counit/tree/0.2.0#the-global-style-recommended) style and the [_case-by-case_](https://github.com/deminy/counit/tree/0.2.0#the-case-by-case-style) style.
- More sample test cases.

**Full changelog**: https://github.com/deminy/counit/compare/0.1.1...0.2.0

## 0.1.1 - 2021-09-23

### Changes

- Mark package _phpunit/phpunit_ as required explicitly.
- Fix the time reported by PHPUnit.
- Documentation improvements.

**Full changelog**: https://github.com/deminy/counit/compare/0.1.0...0.1.1

## 0.1.0 - 2021-09-22

First release.

**Full changelog**: https://github.com/deminy/counit/releases/tag/0.1.0
