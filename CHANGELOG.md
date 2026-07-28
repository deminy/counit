# Changelog

All notable changes to _counit_ are documented here. Each entry mirrors the corresponding
[GitHub release](https://github.com/deminy/counit/releases); the hashes in parentheses identify the commits that made
each change.

Two release series are maintained in parallel: the **1.0.x** series targets PHPUnit ~12.5.24 / ~13.0, while the
**0.2.x** series is the maintenance line for PHPUnit ~8.0 / ~9.0. Tags carry no `v` prefix.

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
