# counit: to run time/IO related unit tests faster using Swoole
[![Library Status](https://github.com/deminy/counit/workflows/Unit%20Tests/badge.svg)](https://github.com/deminy/counit/actions)
[![Latest Stable Version](https://poser.pugx.org/deminy/counit/v/stable.svg)](https://packagist.org/packages/deminy/counit)
[![Latest Unstable Version](https://poser.pugx.org/deminy/counit/v/unstable.svg)](https://packagist.org/packages/deminy/counit)
[![License](https://poser.pugx.org/deminy/counit/license.svg)](https://packagist.org/packages/deminy/counit)

This package helps to run time/IO related unit tests (e.g., sleep function calls, database queries, API calls, etc)
faster using [Swoole](https://github.com/swoole).

Table of Contents
=================

* [How Does It Work](#how-does-it-work)
* [Installation](#installation)
* [Use "counit" in Your Project](#use-counit-in-your-project)
* [Examples](#examples)
   * [Setup Test Environment](#setup-test-environment)
   * [The Automatic Approach](#the-automatic-approach-recommended)
   * [The Manual Approach](#the-manual-approach)
   * [Comparisons](#comparisons)
* [Compatibility with PHPUnit](#compatibility-with-phpunit)
* [Additional Notes](#additional-notes)
* [Local Development](#local-development)
* [Alternatives](#alternatives)
* [License](#license)

# How Does It Work

Package _counit_ allows running multiple time/IO related tests concurrently within a single PHP process using Swoole.
_Counit_ is compatible with _PHPUnit_, which means:

1. Test cases can be written in the same way as those for _PHPUnit_.
2. Test cases can run directly under _PHPUnit_.

A typical test case of _counit_ looks like this:

```php
use Deminy\Counit\TestCase; // Here is the only change made for counit, comparing to test cases for PHPUnit.

class SleepTest extends TestCase
{
  public function testSleep(): void
  {
    $startTime = time();
    sleep(3);
    $endTime = time();

    self::assertEqualsWithDelta(3, ($endTime - $startTime), 1, 'The sleep() function call takes about 3 seconds to finish.');
  }
}
```

Comparing to _PHPUnit_, _counit_ could make your test cases faster. Here is a comparison when running the same test suite
using _PHPUnit_ and _counit_ for a real project. In the test suite, many tests make calls to method
_\Deminy\Counit\Counit::sleep()_ to wait something to happen (e.g., wait data to expire).

<table>
  <tr>
    <th>&nbsp;</th>
    <th># of Tests</th>
    <th># of Assertions</th>
    <th>Time to Finish</th>
  </tr>
  <tr>
    <td><strong>counit (without Swoole), or PHPUnit</strong></td>
    <td rowspan="2">44</td>
    <td rowspan="2">1148</td>
    <td>9 minutes and 18 seconds</td>
  </tr>
  <tr>
    <td><strong>counit (with Swoole enabled)</strong></td>
    <td>19 seconds</td>
  </tr>
</table>

# Installation

The package can be installed using _Composer_:

```bash
composer require deminy/counit --dev
```

Or, in your _composer.json_ file, make sure to have package _deminy/counit_ included:

```json
{
  "require-dev": {
    "deminy/counit": "~1.0.0"
  }
}
```

Please pick the _counit_ version matching the version of _PHPUnit_ used in your project:

| counit | PHPUnit | PHP |
|--------|--------------------|-----------|
| ~1.0.0 | ~13.0              | >= 8.4.1  |
| ~1.0.0 | ~12.5.24           | >= 8.3    |
| ~0.2.0 | ~8.0, ~9.0         | >= 7.2    |

# Use "counit" in Your Project

* Write unit tests in the same way as those for _PHPUnit_. However, to make those tests faster, please write those time/IO related tests using one of the following two approaches (details will be discussed in the next sections):
  * **The automatic approach (recommended)**: Use class [_Deminy\Counit\TestCase_](https://github.com/deminy/counit/blob/master/src/TestCase.php) instead of _PHPUnit\Framework\TestCase_ as the base class; every test method is then wrapped in a coroutine automatically.
  * **The manual approach**: Wrap each test case inside the callback function for method [_Deminy\Counit\Counit::create()_](https://github.com/deminy/counit/blob/master/src/Counit.php), and use method [_Deminy\Counit\Counit::sleep()_](https://github.com/deminy/counit/blob/master/src/Counit.php) instead of the PHP function _sleep()_.
* Use the binary executable _./vendor/bin/counit_ instead of _./vendor/bin/phpunit_ when running unit tests.
* Have the Swoole extension installed. If not installed, _counit_ will work exactly same as _PHPUnit_ (in blocking mode).
* Optional steps:
  * use PHPUnit extension [_Deminy\Counit\CounitExtension_](https://github.com/deminy/counit/blob/master/src/CounitExtension.php) as shown in file [phpunit.xml.dist](https://github.com/deminy/counit/blob/master/phpunit.xml.dist). This is to wait the whole test suite to finish before printing out the summary information at the end.

# Examples

Folder [./tests/unit/automatic](https://github.com/deminy/counit/tree/master/tests/unit/automatic) and [./tests/unit/manual](https://github.com/deminy/counit/tree/master/tests/unit/manual) contain some sample tests, where we
have following time-related tests included:

* Test slow HTTP requests.
* Test long-running MySQL queries.
* Test data expiration in Redis.
* Test _sleep()_ function calls in PHP.

## Setup Test Environment

To run the sample tests, please start the Docker containers and install Composer packages first:

```bash
docker compose up -d
docker compose exec -ti swoole composer install -n
```

There are five containers started: a PHP container, a Swoole container, a Redis container, a MySQL container, and a web
server. The PHP container doesn't have the Swoole extension installed, while the Swoole container has it installed and enabled.

As said previously, test cases can be written in the same way as those for _PHPUnit_. However, to run time/IO related
tests faster with _counit_, we need to make some adjustments when writing those test cases; these adjustments can be
made using two different approaches.

<a id="the-global-style-recommended"></a>
## The Automatic Approach (recommended)

In this approach (previously called _the "global" style_), each test case runs in a separate coroutine automatically.

For test cases written using this approach, the only change to make on your existing test cases is to use class
_Deminy\Counit\TestCase_ instead of _PHPUnit\Framework\TestCase_ as the base class.

A typical test case of the automatic approach looks like this:

```php
use Deminy\Counit\TestCase; // Here is the only change made for counit, comparing to test cases for PHPUnit.

class SleepTest extends TestCase
{
  public function testSleep(): void
  {
    $startTime = time();
    sleep(3);
    $endTime = time();

    self::assertEqualsWithDelta(3, ($endTime - $startTime), 1, 'The sleep() function call takes about 3 seconds to finish.');
  }
}
```

When customized method _setUpBeforeClass()_ and _tearDownAfterClass()_ are defined in the test cases, please make sure
to call their parent methods accordingly in these customized methods.

The total # of assertions reported at the end of a run matches _PHPUnit_ exactly, and the per-testcase counts in the
JUnit XML report (`--log-junit`) are corrected as well — exact whenever counit can observe the test's yields
(sleep()/usleep() calls in a namespaced test class, and Counit::sleep()). An assertion performed after a yield counit
cannot observe (e.g. hooked network IO) goes missing from its own test's JUnit count — but is never added to another
test's. See [Additional Notes](#additional-notes).

To find more tests written using this approach, please check tests under folder [./tests/unit/automatic](https://github.com/deminy/counit/tree/master/tests/unit/automatic) (test suite "automatic").

<a id="the-case-by-case-style"></a>
## The Manual Approach

In this approach (previously called _the "case by case" style_), you make changes directly on a test case to make it work asynchronously.

For test cases written using this approach, we need to use class _Deminy\Counit\Counit_ accordingly in the test cases where
we need to wait for PHP execution or to perform IO operations. Typically, following method calls will be used:

* Use method _Deminy\Counit\Counit::create()_ to wrap the test case.
* Use method _Deminy\Counit\Counit::sleep()_ instead of the PHP function _sleep()_ to wait for PHP execution. You will
  need some knowledge on Swoole if you want to make other IO related tests run asynchronously.

A typical test case of the manual approach looks like this:

```php
use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

class SleepTest extends TestCase
{
  public function testSleep(): void
  {
    Counit::create(function () { // To create a new coroutine manually to run the test case.
      $startTime = time();
      Counit::sleep(3); // Call this method instead of PHP function sleep().
      $endTime = time();

      self::assertEqualsWithDelta(3, ($endTime - $startTime), 1, 'The sleep() function call takes about 3 seconds to finish.');
    });
  }
}
```

In case you need to suppress warning message "This test did not perform any assertions" or to make the number of
assertions match, you can include a 2nd parameter when creating the new coroutine:

```php
use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

class SleepTest extends TestCase
{
  public function testSleep(): void
  {
    Counit::create( // To create a new coroutine manually to run the test case.
      function () {
        $startTime = time();
        Counit::sleep(3); // Call this method instead of PHP function sleep().
        $endTime = time();

        self::assertEqualsWithDelta(3, ($endTime - $startTime), 1, 'The sleep() function call takes about 3 seconds to finish.');
      },
      1 // Optional. To suppress warning message "This test did not perform any assertions", and to make the counters match.
    );
  }
}
```

The 2nd parameter is a request rather than a command: for a test that declares it performs no assertions (through
attribute _#[DoesNotPerformAssertions]_ or method _expectNotToPerformAssertions()_), _Counit::create()_ declines the
credit — crediting such a test would make _PHPUnit_ report it as risky.

To find more tests written using this approach, please check tests under folder [./tests/unit/manual](https://github.com/deminy/counit/tree/master/tests/unit/manual) (test suite "manual").

## Comparisons

Here we will run the tests under different environments, with or without Swoole.

`#1` Run the test suites using _PHPUnit_:

```bash
# To run test suite "automatic":
docker compose exec -ti php    ./vendor/bin/phpunit --testsuite automatic
# or,
docker compose exec -ti swoole ./vendor/bin/phpunit --testsuite automatic

# To run test suite "manual":
docker compose exec -ti php    ./vendor/bin/phpunit --testsuite manual
# or,
docker compose exec -ti swoole ./vendor/bin/phpunit --testsuite manual
```

`#2` Run the test suites using _counit_ (without Swoole):

```bash
# To run test suite "automatic":
docker compose exec -ti php    ./counit --testsuite automatic

# To run test suite "manual":
docker compose exec -ti php    ./counit --testsuite manual
```

`#3` Run the test suites using _counit_  (with extension Swoole enabled):

```bash
# To run test suite "automatic":
docker compose exec -ti swoole ./counit --testsuite automatic

# To run test suite "manual":
docker compose exec -ti swoole ./counit --testsuite manual
```

The first two sets of commands take about same amount of time to finish. The last set of commands uses _counit_ and runs
in the Swoole container (where the Swoole extension is enabled); thus it's faster than the others:

<table>
  <tr>
    <th>&nbsp;</th>
    <th>Approach</th>
    <th># of Tests</th>
    <th># of Assertions</th>
    <th>Time to Finish</th>
  </tr>
  <tr>
    <td rowspan="2"><strong>counit (without Swoole), or PHPUnit</strong></td>
    <td>automatic</td>
    <td rowspan="4">16</td>
    <td rowspan="4">24</td>
    <td>48 seconds</td>
  </tr>
  <tr>
    <td>manual</td>
    <td>48 seconds</td>
  </tr>
  <tr>
    <td rowspan="2"><strong>counit (with Swoole enabled)</strong></td>
    <td>automatic</td>
    <td>7 seconds</td>
  </tr>
  <tr>
    <td>manual</td>
    <td>7 seconds</td>
  </tr>
</table>

# Compatibility with PHPUnit

_Counit_ is designed as a drop-in companion to _PHPUnit_, not a replacement. In short:

* When the Swoole extension is not enabled, unit tests written for _PHPUnit_ and/or _counit_ run in _PHPUnit_ and/or
  _counit_ in exactly the same way, without any changes: every _counit_ API falls back to plain blocking behavior.
* Unit tests written for _counit_ run in _PHPUnit_ without any issue, **with or without** the Swoole extension
  loaded: _counit_'s coroutine behavior activates only inside the coroutine scheduler that the _counit_ runner itself
  starts, which plain _PHPUnit_ never does — a loaded-but-idle Swoole extension changes nothing.

The two matrices below therefore describe the one remaining combination: running tests **under the _counit_ runner
with Swoole enabled** — the fast, concurrent mode this package exists for. The _Counit 0.x_ column (the maintenance
line for _PHPUnit_ ~8.0/~9.0, which uses annotations instead of attributes and a different internal architecture) is
included for reference only. Legend: ✅ behaves as under plain _PHPUnit_; ⚠️ works, with documented differences;
❌ do not rely on it under Swoole. Details for every ⚠️/❌ entry are in [Additional Notes](#additional-notes).

## Compatible features

| Feature | Counit 1.x | Counit 0.x |
|---|---|---|
| Test discovery and naming (`test*` methods, `#[Test]`) | ✅ | ✅ (`@test`) |
| Assertions (`assert*()`); the run's reported **total** | ✅ exact | ✅ exact |
| `#[DataProvider]` / `#[TestWith]` | ✅ (providers themselves run serialized, at collection time) | ✅ (`@dataProvider`) |
| `#[DoesNotPerformAssertions]`; `expectNotToPerformAssertions()` in `setUp()` or at the top of the test body | ✅ (method and class level) | ✅ (`@doesNotPerformAssertions`; method level only — _PHPUnit_ 8/9 itself ignores the class-level annotation) |
| `expectException()` and friends (message/code/object variants included) | ✅ exact — a test with a registered expectation is joined at its first yield, so PHPUnit verifies the real Throwable natively: match, mismatch, and never-thrown all report as in blocking mode, in both approaches. The expectation must be declared before the first yield (one declared only after it is invisible at the join decision and keeps the old premature-failure behavior) | ✅ — same join fix, in both approaches (the automatic approach verified a matching post-yield throw natively even before it, since the whole `runBare()` runs inside the coroutine); PHPUnit 9's `expectWarning()` family after a yield is fixed by the join as well — the converting error handler stays registered while PHPUnit waits |
| `expectOutputString()` / `expectOutputRegex()` | ✅ exact — Swoole gives every coroutine its own output-buffer stack (there never was a shared one: the body's output simply never reached _PHPUnit_'s buffer, which lives on the runner coroutine — so expectations compared against `''` unconditionally, before or after a yield). counit now captures the coroutine's output and replays it into _PHPUnit_'s buffer; a test with a registered expectation is joined at its first yield, so match, mismatch and never-printed all verify natively against the real, complete output, in both approaches. Such a test gets no concurrency of its own; every other test still overlaps with it. The expectation must be registered before the first yield (like an `expectException()`) | ✅ the automatic approach was always correct there, with full concurrency kept (the whole `runBare()` — buffer and verification included — runs inside the test's coroutine); the manual approach takes the same capture-and-replay fix, applied in `Counit::create()`/`createAndJoin()` and scoped to that approach (join detected via the public `hasExpectationOnOutput()`) |
| Stubs (`createStub()`, `createMock()` + `willReturn()` etc., no `expects()`) | ✅ | ✅ |
| Mock `->expects(...)` **satisfied before** the first yield | ✅ | ✅ |
| `setUp()`, `assertPreConditions()`, `setUpBeforeClass()`, `tearDownAfterClass()` | ✅ (run outside the coroutines: serialized, no speedup) | ✅ — but `setUp()` and `assertPreConditions()` run *inside* the test's coroutine there (concurrent, with speedup); only the class-level hooks are serialized. A `setUp()` that aborts after its own yield falls into the deferred post-yield reporting |
| `tearDown()` / `#[After]` hooks | ✅ run after the finished test body — and still run (natively, with blocking semantics) for a test whose `setUp()` threw or skipped (see notes for two caveats) | ✅ (`@after`) — fully native inside the coroutine, including when `setUp()` aborted |
| `assertPostConditions()` and `#[PostCondition]` hook methods | ✅ exact — a test class customizing the post-condition phase (an overridden `assertPostConditions()`, or any `#[PostCondition]` method) has every one of its tests joined at the first yield, so _PHPUnit_ runs the phase after the finished body and a throwing hook fails/errors/skips the test natively, in both approaches; the phase is skipped when the body failed, even after a yield. Such a class's tests get no concurrency of their own — and, like every joined test, are flagged risky when they perform no assertions, exactly as in blocking mode — while every other test still overlaps with them. A class customizing nothing is untouched: _PHPUnit_ skips the empty default hook, so counit does not join | ✅ the automatic approach was always correct there, with full concurrency kept (the whole `runBare()` runs inside the coroutine, so the phase follows the finished body); the manual approach takes the same join fix, applied in `Counit::create()` and scoped to that approach — `@postCondition` annotations included, which exist upstream only as of _PHPUnit_ 9.1 (below that, _PHPUnit_ invokes `assertPostConditions()` directly and the annotation is inert, blocking mode included) |
| `markTestSkipped()` / `markTestIncomplete()` **before** the first yield | ✅ | ✅ |
| Process isolation (`#[RunInSeparateProcess]`, `#[RunTestsInSeparateProcesses]`, `--process-isolation`) | ✅ exact semantics — but no speedup, and each isolated test serializes the run | ✅ (annotations) |
| `#[Depends]` / `#[DependsExternal]` (incl. deep/shallow clone variants) and `#[DependsOnClass]` | ✅ exact semantics — dependents receive the producer's real return value and are skipped when the producer fails, even after a yield. The producer itself (for `#[DependsOnClass]`: every test of the depended-on class) is run to completion before the run moves on, so it gets no speedup of its own — its dependents could not have overlapped with it anyway, and unrelated tests still do | ✅ (`@depends`, incl. `clone`/`shallowClone` and cross-class `Class::method` targets) — same producer-join fix; there, the manual approach's `Counit::create()` even joins producers automatically. `@depends Class::class` requires PHPUnit >= 9.3 (upstream limitation) |
| Test selection: `--filter`, `--testsuite`, `--group` / `#[Group]`, `--exclude-group` | ✅ | ✅ |
| `#[Requires*]` preconditions | ✅ | ✅ (`@requires`) |
| `--order-by` (start order); `#[TestDox]` naming | ✅ | ✅ |
| `--fail-on-risky` / `--fail-on-incomplete` / `--fail-on-skipped` | ✅ | ✅ |
| `--enforce-time-limit` (with `--default-time-limit`, `#[Small]`/`#[Medium]`/`#[Large]`) | ✅ exact — every test is joined at its first yield while the option is active, so PHPUnit times the real `runBare()` and reports a timeout natively (risky verdict, `--fail-on-risky` honored), in both approaches. The run is serialized for the duration: with the option, counit gives PHPUnit's timings and PHPUnit's speed (a STDERR notice announces this). Needs `ext-pcntl`, as under plain _PHPUnit_; marginally more lenient at the exact boundary (see notes) | ✅ same join fix (with `@small`/`@medium`/`@large` annotations) — PHPUnit 8/9's identical `pcntl_alarm()` guard over `runBare()` then times and reports natively; the risky verdict carries php-invoker's "Execution aborted" message, and the aborted test is flagged risky twice there (the abort plus its missing assertions) |
| `#[BackupGlobals]` / `#[BackupStaticProperties]` / `#[WithEnvironmentVariable]` (incl. the `backupGlobals`/`backupStaticProperties` configuration, the `Exclude*FromBackup` attributes and `--strict-global-state`) | ✅ exact — a test PHPUnit brackets with a global-state snapshot is joined at its first yield, and every other test's coroutine is drained **before** its snapshot is taken, so PHPUnit's own snapshot/restore covers the real test body with nothing else running. Such a test gets no concurrency of its own and awaits everything already in flight; every other test still overlaps. A run configured with `backupGlobals="true"` / `--globals-backup` (or the static-properties equivalent) therefore serializes completely (STDERR notice). `#[BackupGlobals(false)]` cannot override a configuration-level `true` — upstream _PHPUnit_ behavior, mirrored | ✅ same drain-and-join fix (annotations `@backupGlobals`/`@backupStaticAttributes`; `#[WithEnvironmentVariable]` does not exist on _PHPUnit_ 8/9) — the failure profile it fixes differs: the whole `runBare()` runs inside the coroutine there, so the test's own isolation was already correct, but the snapshot spanned its entire concurrent lifetime and the restore reverted every overlapping test's global writes. No serialized-run STDERR notice on 0.x |
| PHPUnit's error/exception-handler snapshot (the "test … did not remove its own error/exception handlers" risky checks) | ✅ exact for every test whose yields counit can observe — the handler stacks are process-global under Swoole (unlike the output-buffer stack, which Swoole does isolate), so counit lifts each coroutine's own handlers off the shared stack while it is suspended and puts them back when it resumes. A test's handler therefore survives its own sleep/IO yield, _PHPUnit_'s snapshot/restore sees only the baseline, and the verdict — all four messages — is reported against the test that actually leaked: natively when it never yielded, through an end-of-run risky event otherwise (`Risky: N` and `--fail-on-risky` exact). No join, no serialization. A coroutine resumed at a point counit cannot observe (hooked network/DB IO, a fully-qualified `\sleep()`, a test class in the global namespace) is left to the previous behavior — silence, never a false accusation — and late verdicts sit at the end of the risky listing, so `--stop-on-risky` cannot react to them | — the check does not exist on _PHPUnit_ 8/9 |
| Exit code as the pass/fail signal | ✅ authoritative (failures after a yield force a non-zero exit) | ✅ |

## Incompatible features

| Feature | Counit 1.x | Counit 0.x (reference) |
|---|---|---|
| Mock `->expects(...)` verified for a call made **after** a yield | ❌ verified too early — false "called 0 times" failures. A test joined for another reason (a `#[Depends]` producer, a registered `expectException()`, a post-condition-customizing class, …) is verified after its finished body, incidentally correct | ✅ verified after the finished body |
| `markTestSkipped()` / `markTestIncomplete()` **after** a yield | ⚠️ status remains "passed"; listed in an end-of-run notice, exit code stays 0 | ⚠️ same |
| Risky check "This test did not perform any assertions" (and its mirror, "…is not expected to perform assertions but performed N assertions") | ⚠️ exact for every test that never yields (the up-front assertion credit is declined once the body is known finished, so PHPUnit reaches the verdict natively, at the right moment) and for every yielding test whose yields counit can observe (`sleep()`/`usleep()` in a namespaced test class, `Counit::sleep()`): those verdicts are emitted at the end of the run through _PHPUnit_'s own risky event — they appear in the risky listing with the right location, count into `Risky: N`, and `--fail-on-risky` exits 1 exactly as in blocking mode. A test resumed at a point counit cannot observe (hooked network/DB IO, a fully-qualified `\sleep()`, a test class in the global namespace) has an untrustworthy per-test tally and is deliberately **not** reported — silence, never a false accusation. `--stop-on-risky` cannot react to a verdict reached after the run, and the deferred verdicts sit at the end of the risky listing | ⚠️ same fix, on that branch's seams (the mirror check reads "annotated with `@doesNotPerformAssertions` but performed N assertions" there): the deferred verdicts are handed to the public `TestResult::addFailure()`, and a stray `R` progress character may trail the progress line — _PHPUnit_ 8/9's printer echoes late verdicts |
| A diagnostic (deprecation/warning/notice) triggered by a non-joined test **after** its first yield | ❌ _PHPUnit_'s own converting error handler is disabled the moment the test-method invocation returns — the first yield — so a post-yield `trigger_error()` is never converted or counted by _PHPUnit_: it prints as a raw PHP message instead of entering `Deprecations:`/`Warnings:` (blocking mode counts it). Incidentally correct for any joined test, whose body finishes before the handler is disabled | ⚠️ correct in the automatic approach (the whole `runBare()`, handler registration included, runs inside the coroutine); the manual approach has the same gap |
| The "test printed unexpected output" check and JUnit `system-out`, for **post-yield** output of a test that registers no output expectation | ⚠️ exact while `--disallow-test-output` is active — counit then joins every test, so _PHPUnit_ sees the complete output and reports the risky verdict natively; the run serializes for the duration (STDERR notice). Without the option, such a test's post-yield output goes to the terminal in one batch instead of into _PHPUnit_'s buffer — visible, but absent from the unexpected-output annotation and `--log-junit`'s `system-out`. A test leaving its own output buffer open is likewise only reported natively when it is joined | ⚠️ never reported; in the automatic approach the post-yield stray output is swallowed by the coroutine-local buffer rather than printed |
| Per-test reporting: per-testcase assertion counts and durations in `--log-junit`/`--log-otr` XML | ⚠️ JUnit counts are corrected via segment accounting: exact whenever the test's yields are observable (sleep()/usleep() in a namespaced test class, Counit::sleep()); a yield counit cannot observe (hooked network IO, a fully-qualified `\sleep()`, a test class in the global namespace) leaves that test's count too low — never another test's too high. `--log-otr` counts are not corrected. A failure after a yield is logged as PASSED in either XML — trust the exit code, not the logs. No other output surface (CLI, TestDox, TeamCity) shows per-test assertion counts at all | ⚠️ same JUnit correction (segment accounting; exact for observable yields, own count too low otherwise); `--log-otr` does not exist on 0.x |
| A `tearDown()` / `#[After]` hook that throws or skips | ⚠️ reported in the end-of-run failure block with a non-zero exit code, not as that test's own error; a skip signalled from such a hook — a test **failure** under blocking _PHPUnit_, never a skip — is reported the same way. Fully native semantics apply on a joined `#[Depends]` producer and on a test whose `setUp()` threw or skipped (their hooks run natively) | ⚠️ a hook throw after a yield lands in the deferred block (before one, it errors the test natively); a hook **skip** is a genuine skip on _PHPUnit_ 8/9 — upstream semantics, exit code 0 — and 0.x honors it: natively before a yield, via the benign end-of-run notice after one. Joined producers' hooks are fully native there too |
| `--stop-on-failure` | ⚠️ reacts to pre-yield failures only; in-flight tests finish anyway | ⚠️ same |
| Result cache / `--order-by=defects` | ⚠️ polluted by provisional passes | ⚠️ same |
| Code coverage | ⚠️ per-test attribution wrong by construction; aggregate coverage unverified | ⚠️ same |
| `--repeat` | (option removed in _PHPUnit_ 10+) | ⚠️ runs in blocking mode — correct, but without speedup |

# Additional Notes

Since this package allows running multiple tests simultaneously, we should not use same resources in different tests;
otherwise, racing conditions could happen. For example, if multiple tests use the same Redis key, some of them could
fail occasionally. In this case, we should use different Redis keys in different test cases. Method
_\Deminy\Counit\Helper::getNewKey()_ and _\Deminy\Counit\Helper::getNewKeys()_ can be used to generate random and unique
test keys.

The package works best for tests that have function call _sleep()_ in use; It can also help to run some IO related tests
faster, with limitations apply. Here is a list of limitations of this package:

* The package makes tests running faster by performing time/IO operations simultaneously. For functions/extensions that
  work in blocking mode only, this package can't make their function calls faster. Here are some extensions that work in
  blocking mode only: _MongoDB_, _Couchbase_, and some ODBC drivers.
* The package doesn't work exactly the same as when running under _PHPUnit_:
  * Tests may not have yet finished even it's marked as finished (by _PHPUnit_). Because of that, a test marked as "passed" (by PHPUnit) could still fail at a later time under _counit_. When that happens, _counit_ reports the failure at the end of the run and exits with a non-zero code, so the most reliable way to check if all test cases have passed or not is to check the exit code of _counit_.
  * The total # of assertions reported at the end of a run matches _PHPUnit_, and the per-testcase `assertions` attributes in the JUnit XML report (`--log-junit`) are corrected before the report is written. The correction attributes every assertion to the test that performed it (segment accounting over the coroutine switches counit can observe, including sleep()/usleep() calls in namespaced test classes and assertions counted directly on the test object after a yield, e.g. from a relocated _tearDown()_), so the counts match a blocking run exactly whenever every yield is observable. A yield counit cannot observe (hooked network IO, a fully-qualified `\sleep()` call, a test class in the global namespace) leaves that test's own count too low — never another test's too high. With Swoole's preemptive scheduler enabled, segment accounting is switched off and the correction falls back to removing the up-front credit and adding post-report instance counts only.
  * Some exceptions/errors are not handled/reported the same.
* Tests using PHPUnit's process isolation (attributes _#[RunInSeparateProcess]_ and _#[RunTestsInSeparateProcesses]_,
  or option `--process-isolation`) behave and are counted exactly as under _PHPUnit_, but they gain nothing from this
  package: _PHPUnit_ runs each of them in a separate child process, which is plain non-coroutine PHP, so the test body
  there runs in blocking mode. Worse, such a test blocks the entire run for as long as its child process takes, since
  no other coroutine can make progress meanwhile. Mixing a few isolated tests into a suite is fine; running a whole
  suite under `--process-isolation` defeats the purpose of _counit_.
* Attribute _#[Depends]_ (and its variants) works with exact _PHPUnit_ semantics in the automatic approach. _PHPUnit_
  records a producer test's return value and verdict at the end of _runBare()_ — which, with the body handed off to a
  coroutine, would be the body's first yield: too early for either to be real. So when anything in the run depends on
  a test (_counit_ builds the reverse dependency graph up front, from _PHPUnit_'s own metadata), that test's coroutine
  is *joined*: _invokeTestMethod()_ only returns once the body has truly finished, returning its real value and
  rethrowing its real failure. Dependents therefore receive the actual return value (deep/shallow clone variants
  included) and are skipped when a producer fails — even when the failure happens only after a yield. Notes:
  * The joined producer gets no concurrency of its own — its dependents could never have overlapped with it anyway —
    but every unrelated test still overlaps with it, including while it waits. A _#[Depends]_ chain runs in exactly
    its blocking-mode duration; everything else is unaffected. For _#[DependsOnClass]_, every test of the depended-on
    class is joined (the class-passed verdict needs all of them finished).
  * A joined producer's _tearDown()_/_#[After]_ hooks are handed back to _PHPUnit_'s native invocation for that run:
    with the body complete inside _invokeTestMethod()_, the native timing is correct again — so a throwing
    _tearDown()_ errors the producer exactly as under blocking _PHPUnit_ (and skips its dependents), instead of
    surfacing in the deferred end-of-run block as it does for non-joined tests. The same goes for the producer's
    whole after-test phase — including `assertPostConditions()`, which every class customizing it now gets correct
    for all of its tests, joined or not (see its own bullet below). _tearDownCoroutine()_ stays counit-owned and is not
    part of this hand-back: it still runs inside the coroutine right after the body — for a joined producer its
    failure is always attributed to the body path (the join runs to completion, so the before/after-first-yield
    split documented for non-joined tests does not arise).
  * In the manual approach, a producer computing its return value inside _Counit::create()_ still loses it (the
    callable's result is only available if it finishes before its first yield). Use _Counit::createAndJoin()_ instead
    for such producers: it runs the callable in a coroutine, waits for it, and returns its value / rethrows its
    failure — while other tests' coroutines keep running. (On the 0.2.x line, _Counit::create()_ itself joins such
    producers when called directly from the depended-on test method, so the usual by-ref shape works with no test
    changes; _Counit::createAndJoin()_ exists there as well.)
  * A producer with a data provider passes _NULL_ to its dependents — under plain _PHPUnit_ too (it refuses to record
    return values for data-provider tests); this is upstream behavior, not a _counit_ limitation.
* Method _expectException()_ and its friends work with exact _PHPUnit_ semantics even when the expected exception is
  thrown only after the test's first sleep/IO yield. _PHPUnit_ verifies the expectation the moment the test method
  invocation returns — under _counit_, the body's first yield: too early, so such tests used to fail with "exception
  not thrown" while the real Throwable could only be reported in the deferred end-of-run block. Since the expectation
  is declared inside the body (it does not exist yet when the coroutine starts), _counit_ checks for a registered
  expectation once the body reaches its first yield and then *joins* the coroutine — the same mechanism as for a
  _#[Depends]_ producer: the real Throwable is rethrown synchronously into _PHPUnit_'s native verification, no
  assertion credit is applied (the verification counts its own assertions), and the test's after-test hooks are
  handed back to _PHPUnit_'s native invocation — which also guarantees that a _tearDown()_ throwing the very class
  the test expects errors the test instead of falsely satisfying the expectation. Notes:
  * Only expectation-carrying tests that yield lose their own concurrency; every other test still overlaps with
    them, including while they wait.
  * An expectation declared only **after** the test's first yield is invisible at the join decision and keeps the
    old behavior; declare expectations before the first sleep/IO call (the overwhelmingly common shape).
  * The detection reads _PHPUnit_'s internal expectation state (kept in two different shapes across PHPUnit 12.5
    and 13); should a future _PHPUnit_ release change it, _counit_ prints a notice once and degrades to the
    previous behavior — loud, never silent.
* Methods _expectOutputString()_ and _expectOutputRegex()_ work with exact _PHPUnit_ semantics — at the price of
  the expecting test's concurrency. The root cause here is not timing but visibility: Swoole gives every coroutine
  its **own** output-buffer stack (a coroutine starts at `ob_get_level() === 0` no matter what its creator had
  open), while _PHPUnit_ opens the test's output buffer on the runner coroutine at the top of _runBare()_ — so
  nothing a test body echoed from inside its coroutine ever reached that buffer. Expectations compared against an
  empty string unconditionally — a yield was not even needed — the output leaked raw into the progress output, and
  the "printed unexpected output" machinery never saw a byte. A join alone cannot fix this (the joined body still
  writes into its own coroutine's stack); _counit_ therefore **captures** each coroutine's output in a buffer of
  its own and **replays** it on the calling coroutine, inside _PHPUnit_'s still-open buffer: immediately for a
  body that never yielded, after the join for a test with a registered expectation (detected through the public
  _expectsOutput()_ — no reflection involved), and, for a test joined for any *other* reason, incidentally as
  well. The capture buffer is opened in whichever shape the running _PHPUnit_ uses for its own, so the
  `ob_flush()` corner behaves per version exactly as in blocking mode. Notes:
  * Only output-expecting tests that yield lose their own concurrency; every other test still overlaps with them,
    including while they wait. Like the other join paths, no assertion credit is applied — the output verification
    counts its own assertion natively.
  * An expectation must be registered — or `getActualOutputForAssertion()`/`getActualOutput()` first called —
    before the test's first yield; one only reached after it is invisible at the join decision and keeps the old
    behavior (a retrieval after a yield returns `''`, producing a loud failure, never a silent pass). Both are
    exact under `--disallow-test-output`, which joins every test (see the matrix row on unexpected output).
  * A body that leaves its own `ob_start()` open (or closes a buffer that is not its own) has the level mismatch
    reproduced on the runner coroutine, so _PHPUnit_ itself reports the native "did not close its own output
    buffers" verdict — for joined tests; a non-joined test's mismatch is contained to its coroutine.
  * Post-yield output of a test that is never joined cannot be replayed into the right buffer anymore (_PHPUnit_
    already stopped it); it reaches the terminal in one contiguous batch at body end — where it already went
    before this fix, just no longer interleaved.
* Option `--enforce-time-limit` works with exact _PHPUnit_ semantics — at the price of the run's concurrency.
  _PHPUnit_ times a limited test by wrapping the whole _runBare()_ call in a `pcntl_alarm()`/`SIGALRM` guard
  (package _phpunit/php-invoker_) and disarms the alarm the moment _runBare()_ returns. Under _counit_ that used to
  be the body's first yield: the measured window covered milliseconds, so an over-limit test simply passed — and on
  the joined paths (a _#[Depends]_ producer, an _expectException()_ test), where _runBare()_ does stay alive for the
  test's real duration, the still-armed alarm's signal was delivered to whichever coroutine resumed first, aborting
  an unrelated test and failing a green run non-deterministically. So while the option is active, _counit_ joins
  **every** test's coroutine at its first yield (the same mechanism as for a _#[Depends]_ producer): _PHPUnit_'s own
  timer measures the real duration and reports a timeout natively — risky verdict, exact message, `--fail-on-risky`
  honored — and, with no concurrent test coroutines left, the alarm can only ever fire within the timed test's own
  window. Notes:
  * The whole run is serialized while the option is active: with it, _counit_ gives _PHPUnit_'s timings and
    _PHPUnit_'s speed — the option and _counit_'s concurrency are mutually exclusive by construction (per-test wall
    time is not a meaningful quantity while tests deliberately overlap). A notice on STDERR announces this; silence
    it with _COUNIT_SILENCE_TEARDOWN_NOTICE=1_.
  * Enforcement needs the `pcntl` extension in both modes — plain _PHPUnit_ silently skips enforcement without it
    (the mechanism is POSIX-only) — and _counit_ then skips the joining too, keeping full concurrency. The Docker
    images used for local development install `pcntl` so the time-limit regression tests can run there.
  * At the exact boundary — a `sleep(N)` under an N-second limit — _counit_ is marginally more lenient than
    blocking _PHPUnit_: a hooked sleep is not interrupted by `SIGALRM` the way blocking `sleep()` is (the timeout
    is only thrown once the sleep completes and the coroutine resumes), so a test at exactly its limit can pass
    under _counit_ where blocking mode — itself flaky at the boundary — sometimes aborts it. Choose limits with
    margin.
  * A test body that never yields was timed exactly even before this fix, and still is: the alarm dispatches on the
    running code just as under plain _PHPUnit_.
  * The 0.2.x line carries the same join-based fix (the root cause is identical there: _PHPUnit_ 8/9's
    _TestResult::run()_ wraps _runBare()_ in the same `pcntl_alarm()` guard, and 0.2.x's _runBare()_ override used
    to return at the body's first yield), with two cosmetic differences: the risky verdict carries php-invoker's
    "Execution aborted after N seconds" message rather than _PHPUnit_'s "This test was aborted" wording, and
    _PHPUnit_ 8/9 count an aborted test's abort and its missing assertions as two risky entries.
* Attributes `#[BackupGlobals]`, `#[BackupStaticProperties]` and `#[WithEnvironmentVariable]` (and the
  `backupGlobals`/`backupStaticProperties` configuration) work with exact _PHPUnit_ semantics — at the price of the
  backed-up test's concurrency. _PHPUnit_ takes the global-state snapshot (and sets the environment variables) at
  the very top of _runBare()_ — before _setUp()_ — and restores at its very bottom. Under _counit_ that restore used
  to fire at the body's first yield: the test's **own** pre-yield mutations (an injected environment variable
  included) were reverted while the body still needed them, its post-yield mutations escaped the restore and leaked
  for the rest of the run, and on the joined paths (a _#[Depends]_ producer, an _expectException()_ test, a
  `--enforce-time-limit` run) the still-open window spanned the test's whole duration while other coroutines ran
  inside it — whose global writes the restore then silently reverted. Worse, a `#[BackupStaticProperties]` snapshot
  also captured _counit_'s **own** static bookkeeping (counit's classes are user-defined, so not on _PHPUnit_'s
  exclude list), skewing the run's reported assertion total. The fix has two halves, because global state — unlike a
  return value, an exception or an alarm — is process-wide: the backed-up test is *joined* at its first yield (the
  _#[Depends]_-producer mechanism, so the restore follows the real body and the natively-run after-test hooks), and
  before its snapshot is taken every in-flight test coroutine is *drained* (at the `Test\PreparationStarted` event,
  emitted just ahead of the snapshot) — giving the snapshot/restore pair the exclusive window blocking _PHPUnit_
  gets for free. Notes:
  * A backed-up test first waits for everything already in flight, then runs serialized; every other test still
    overlaps normally, and a suite with no backup requests is completely unaffected. When the *configuration* makes
    every test backed up (`backupGlobals="true"`, `--globals-backup`, or the static-properties equivalent), the
    whole run serializes — announced once on STDERR; silence it with _COUNIT_SILENCE_TEARDOWN_NOTICE=1_.
  * `--strict-global-state` becomes correct as a side effect: both of its comparison snapshots now bracket the same
    exclusive window, so it flags exactly the tests blocking _PHPUnit_ flags — no more missed post-yield mutations,
    and no more false positives from bystanders' writes on the joined paths.
  * After a `#[BackupStaticProperties]` restore, _counit_ repairs its own rewound after-test hook takeover state
    (the restore replaces it with serialized clones); without the repair, _tearDown()_ would run twice for every
    later test of the class. The joined path applies no up-front assertion credit, so totals stay exact and a
    backed-up test performing no assertions stays risky, as under blocking _PHPUnit_.
  * The resolution mirrors _PHPUnit_'s own, quirk included: a method-level attribute beats a class-level one, and
    `#[BackupGlobals(false)]` merely declines to force backup on — it cannot override a configuration-level `true`.
  * _PHPUnit_'s *unconditional* error/exception-handler snapshot — the "did not remove its own exception handlers"
    risky check — is covered separately, without any join: the handler stacks are process-wide state like the
    globals, but per-coroutine isolation is possible for them. See the handler-snapshot bullet below.
  * A process-isolated test needs none of this and never did: isolation skips the snapshot machinery entirely (the
    child process's mutations die with it), and `#[WithEnvironmentVariable]` still applies inside the child.
* Method _assertPostConditions()_ and _#[PostCondition]_ hook methods work with exact _PHPUnit_ semantics — at the
  price of the customizing class's concurrency. _PHPUnit_ runs the post-condition phase from _runBare()_, immediately
  after the test method invocation returns — under _counit_, the body's first yield: the hooks used to inspect the
  test while its body was still in flight (failing loudly, or passing vacuously against pre-body state), and they ran
  even for a body that failed only after a yield, where blocking _PHPUnit_ skips the phase entirely. The hooks cannot
  be relocated into the coroutine the way _tearDown()_ is: _PHPUnit_ derives the test's verdict from whether they
  threw, so a relocated failure could only ever be deferred to the end of the run instead of failing the test. So a
  test class that customizes the phase — an overridden _assertPostConditions()_ (in a parent class too), or any
  method carrying _#[PostCondition]_ — has every one of its tests *joined* at the first yield (the
  _#[Depends]_-producer mechanism): the phase follows the truly finished body, is skipped when the body failed, and a
  throwing hook fails/errors/skips the test natively. Notes:
  * Only the customizing class's tests lose their own concurrency; every other test still overlaps with them,
    including while they wait. A class customizing nothing — the overwhelming majority — is completely unaffected:
    _PHPUnit_'s own hook invoker skips the empty default method, so _counit_ does not join. (An *empty* override
    still joins — proving emptiness by reflection is not worth it.)
  * As on every join path, no assertion credit is applied: a customizing test performing no assertions is flagged
    risky, exactly as under blocking _PHPUnit_.
  * `assertPreConditions()` / `#[PreCondition]` need no handling at all: _PHPUnit_ invokes them before the test
    method — i.e. before the test's coroutine exists (see the setup-hooks row above).
  * In the manual approach, the detection is caller-based like the _expectException()_ one: a _Counit::create()_
    call made from a helper object rather than the test method itself is not joined.
* Attribute _#[DoesNotPerformAssertions]_ (at method or class level) and method _expectNotToPerformAssertions()_ (when
  called in _setUp()_ or at the top of the test body) are supported in both approaches: such tests report clean with
  zero assertions, same as under _PHPUnit_. Two limitations remain, both consequences of the risky verdict being
  rendered when the test's coroutine first yields:
  * A test declaring it performs no assertions but nevertheless performing one only **after** a sleep/IO yield is
    flagged risky ("This test is not expected to perform assertions but performed 1 assertion") through the deferred
    end-of-run pass described in the risky-check bullet below — provided its yields are observable; one resumed at a
    point _counit_ cannot observe stays silent. Run totals stay exact either way, and a *failing* late assertion
    still fails the run.
  * In a mixed suite, delayed assertions from *other* tests may land in such a test's counting window and flag it
    risky occasionally — the per-test attribution caveat above, wearing a different hat.
* The risky check "This test did not perform any assertions" works with near-exact _PHPUnit_ semantics. The check is
  decided from the count _PHPUnit_ reads the moment the test method invocation returns — under _counit_, the body's
  first yield — and whether the still-running body will assert later is unknowable at that instant; that is why
  _counit_ credits one assertion up front (suppressing FALSE risky verdicts for post-yield assertions), which used
  to also suppress every TRUE one. Two mechanisms now restore the true verdicts:
  * The credit is **declined whenever the body finished before _Counit::create()_ returned** — a never-yielding
    test's count is already final, so _PHPUnit_ reaches the verdict natively, at the right moment (correct progress
    marker and listing position, `--stop-on-risky` works). This also covers a body that throws synchronously.
  * A yielding no-assertion test is reported at the **end of the run through _PHPUnit_'s own risky event** — it
    appears in the risky listing with the right location, counts into `Risky: N`, and `--fail-on-risky` exits 1
    exactly as in blocking mode (the event is a real _PHPUnit_ verdict, not a _counit_ notice). A test is only
    reported when _counit_ can *prove* its count: its coroutine must never have been resumed at a point _counit_
    cannot observe (hooked network/DB IO, a fully-qualified `\sleep()`, a test class in the global namespace — the
    same caveat documented for the JUnit per-test counts), because such a test's own tally is an undercount and
    reporting it would be a false accusation — _counit_'s own CURL sample tests would be flagged. Unprovable cases
    stay silent; tests _PHPUnit_ already flagged itself, tests that declared they perform no assertions, and tests
    that errored/skipped/went incomplete — natively or only after their report — are exempt, mirroring blocking
    _PHPUnit_'s own gates. The deferred verdicts sit at the end of the risky listing, and `--stop-on-risky` cannot
    react to them. `--do-not-report-useless-tests` disables all of it, as under _PHPUnit_.
* _PHPUnit_'s error/exception-handler snapshot (the "did not remove its own error/exception handlers" risky checks)
  works with exact _PHPUnit_ semantics for every test whose yields _counit_ can observe — with no join and no
  serialization. Unlike the output-buffer stack, which Swoole gives each coroutine privately, the two handler stacks
  are **process-global** under Swoole; _PHPUnit_ snapshots them at the top of _runBare()_ and compares/restores at
  its bottom — under _counit_, the body's first yield — unconditionally, for every test. That used to break four
  ways: a perfectly legal handler spanning a yield was stripped mid-body *and* falsely flagged (its own later
  `restore_error_handler()` then popped _PHPUnit_'s converting handler), a handler registered only after the first
  yield leaked unreported into the rest of the run — where it could silently swallow other tests' deprecations and
  warnings — and a bystander whose snapshot window covered someone else's leak was blamed for it. _counit_ now
  supplies the isolation Swoole does not: at every observation point, the handlers the running coroutine pushed are
  lifted off the shared stack when it suspends and put back when it resumes — between two observation points on one
  coroutine nothing else can run, so everything above the slice's starting depth is provably that coroutine's own.
  A suspended test's handler is simply not on the stack while other tests run; whatever a coroutine still holds
  when it finishes is its leak, reported with _PHPUnit_'s exact wording against the right test — natively for a
  test that never yielded (it is left entirely alone), through the end-of-run risky event otherwise. Notes:
  * The stacks are read with the same public-API walk _PHPUnit_ itself uses (`set_*_handler()` returns the previous
    handler; pop to the bottom; re-push) — no reflection into anything, and the measured cost is well under a
    microsecond per yield.
  * The same trust guard as the no-assertions check applies: a coroutine resumed at a point _counit_ cannot observe
    is handed back everything lifted on its behalf and left to the previous behavior — silence, never a false
    accusation.
  * A handler re-pushed after a yield sits on whatever base the shared stack has at that moment, so an error it
    declines can fall through differently than under blocking _PHPUnit_ — unavoidable while only one test's
    converting handler is enabled at a time. And the *separate* gap that a non-joined test's post-yield
    deprecations/warnings are not converted at all (the converting handler is disabled at the first yield) is not
    addressed by this isolation — see its own row in the incompatible-features table.
* In the **automatic approach**, _counit_ takes the after-test hooks — _tearDown()_ and _#[After]_ methods — over from
  _PHPUnit_ and runs them inside the test's coroutine, right after the test body finishes, pass or fail. _tearDown()_
  therefore observes a finished test body, exactly as under plain _PHPUnit_ — closing a database connection or
  truncating tables there can no longer sabotage its own still-running test. Two differences from plain _PHPUnit_
  remain, both a consequence of _counit_ reporting a test as soon as its body first yields:
  * The hooks run **after** _PHPUnit_ has already reported the test's result. Ordering relative to the test body is
    preserved; ordering relative to the run's output is not.
  * A _tearDown()_ that throws cannot mark its own test as errored. _counit_ reports it in the failure block printed
    after the summary and exits with a non-zero code — as always, **the exit code of _counit_ is the authoritative
    pass/fail signal**. A skip signalled from _tearDown()_ — which blocking _PHPUnit_ turns into a test *failure*,
    never a skip — is reported through the same block: it fails the run, it is never silently dropped.

  The relocated hooks only exist for a test whose body actually starts. When _setUp()_ — or another before-test
  hook — throws or skips, the body never runs and no coroutine exists, so _counit_ hands the hooks straight back to
  _PHPUnit_, which runs them natively, synchronously, with its exact blocking semantics: _tearDown()_ still runs, an
  exception it raises after a failed _setUp()_ is swallowed (the _setUp()_ error stands), and one raised after a
  _setUp()_ skip errors the test.

  The takeover reaches into _PHPUnit_ internals; should a future _PHPUnit_ release change them, _counit_ leaves
  _PHPUnit_'s own (too early) hook timing untouched and prints a notice — once per class; silence it with environment
  variable _COUNIT_SILENCE_TEARDOWN_NOTICE=1_ — so the degradation is loud, never silent.
* In the **manual approach**, _counit_ is not in charge of the test lifecycle, so _tearDown()_ still runs at the
  body's first yield. Register cleanup with _Counit::defer(callable)_ inside the callback passed to
  _Counit::create()_ instead; deferred callbacks run inside the coroutine, in reverse registration order, once the
  body finishes — and at the same point in blocking mode. The automatic approach additionally offers the
  _tearDownCoroutine()_ hook (declared on _Deminy\Counit\TestCase_), which runs right after the test body, before the
  relocated _tearDown()_/_#[After]_ hooks and before deferred callbacks.
* Cleanup that destroys state **other** tests read (truncating shared tables, flushing caches) is incompatible with
  concurrent execution no matter where it runs — give each test disjoint state (see _Helper::getNewKey()_) or run
  that test class under plain _PHPUnit_.
* A _markTestSkipped()_ or _markTestIncomplete()_ call made after the test's first sleep/IO yield cannot change the
  test's status anymore: _PHPUnit_ already reported the test as passed at that yield. _counit_ lists such tests in a
  notice at the end of the run — their status remains "passed" — without failing the run, matching the exit code of a
  blocking run (where skipped/incomplete tests do not fail the run either). To have the skip honored, call it before
  the first yield.

# Local Development

Docker images used to run the sample tests are built locally via `docker-compose.yml`, which builds the `php` and
`swoole` services from the Dockerfiles under `./dockerfiles`. See [Setup Test Environment](#setup-test-environment).

# Alternatives

This package allows to use Swoole to run multiple time/IO related tests without multiprocessing, which means all tests
can run within a single PHP process. To understand how exactly it works, I'd recommend checking this free online talk:
[CSP Programming in PHP](https://nomadphp.com/video/306/csp-programming-in-php) (and here are the [slides](http://talks.deminy.in/csp.html)).

In the PHP ecosystem, there are other options to run unit tests in parallel, most end up using multiprocessing:

* Process isolation in PHPUnit. This allows to run tests in separate PHP processes.
* Package [brianium/paratest](https://github.com/paratestphp/paratest)
* Package [pestphp/pest](https://pestphp.com)

# License

MIT license.
