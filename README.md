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
    "deminy/counit": "~0.2.0"
  }
}
```

Please pick the _counit_ version matching the version of _PHPUnit_ used in your project:

| counit | PHPUnit | PHP |
|--------|--------------------|-----------|
| ~1.0.0 | ~13.0              | >= 8.4.1  |
| ~1.0.0 | ~12.5.24           | >= 8.3    |
| ~0.2.0 | ~8.0, ~9.0         | >= 7.2    |

This branch is the ~0.2.0 series, the maintenance line for _PHPUnit_ ~8.0 and ~9.0. It receives bug fixes and security
updates only; new work happens in the ~1.0.0 series.

# Use "counit" in Your Project

* Write unit tests in the same way as those for _PHPUnit_. However, to make those tests faster, please write those time/IO related tests using one of the following two approaches (details will be discussed in the next sections):
  * **The automatic approach (recommended)**: Use class [_Deminy\Counit\TestCase_](https://github.com/deminy/counit/blob/0.2.x/src/TestCase.php) instead of _PHPUnit\Framework\TestCase_ as the base class; every test method is then wrapped in a coroutine automatically.
  * **The manual approach**: Wrap each test case inside the callback function for method [_Deminy\Counit\Counit::create()_](https://github.com/deminy/counit/blob/0.2.x/src/Counit.php), and use method [_Deminy\Counit\Counit::sleep()_](https://github.com/deminy/counit/blob/0.2.x/src/Counit.php) instead of the PHP function _sleep()_.
* Use the binary executable _./vendor/bin/counit_ instead of _./vendor/bin/phpunit_ when running unit tests.
* Have the Swoole extension installed. If not installed, _counit_ will work exactly same as _PHPUnit_ (in blocking mode).
* Optional steps:
  * use PHPUnit extension [_Deminy\Counit\CounitExtension_](https://github.com/deminy/counit/blob/0.2.x/src/CounitExtension.php) as shown in file [phpunit.xml.dist](https://github.com/deminy/counit/blob/0.2.x/phpunit.xml.dist). This is to wait the whole test suite to finish before printing out the summary information at the end.

# Examples

Folder [./tests/unit/automatic](https://github.com/deminy/counit/tree/0.2.x/tests/unit/automatic) and [./tests/unit/manual](https://github.com/deminy/counit/tree/0.2.x/tests/unit/manual) contain some sample tests, where we
have following time-related tests included:

* Test slow HTTP requests.
* Test long-running MySQL queries.
* Test data expiration in Redis.
* Test _sleep()_ function calls in PHP.

## Setup Test Environment

To run the sample tests, please start the Docker containers and install Composer packages first:

```bash
docker compose up -d --build
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
JUnit XML report (`--log-junit`, the only _PHPUnit_ 8/9 output that shows per-test assertion counts) are corrected as
well — exact whenever counit can observe the test's yields (sleep()/usleep() calls in a namespaced test class, and
Counit::sleep()). An assertion performed after a yield counit cannot observe (e.g. hooked network IO) goes missing
from its own test's JUnit count — but is never added to another test's. See [Additional Notes](#additional-notes).

To find more tests written using this approach, please check tests under folder [./tests/unit/automatic](https://github.com/deminy/counit/tree/0.2.x/tests/unit/automatic) (test suite "automatic").

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
annotation _@doesNotPerformAssertions_ or method _expectNotToPerformAssertions()_), _Counit::create()_ declines the
credit — crediting such a test would make _PHPUnit_ report it as risky.

When another test declares _@depends_ on a manual-approach test, _Counit::create()_ — called directly from that test
method — joins the coroutine instead of merely starting it: the callback runs to completion (other tests keep running
in the meantime), so a value computed inside it can be returned from the test method and reaches the dependents for
real. Alternatively, _Counit::createAndJoin()_ returns the callback's value (and rethrows its failure) directly. See
[Additional Notes](#additional-notes).

The same join applies to a test that has an exception expectation registered — _expectException()_ and friends,
declared before the _Counit::create()_ call as usual: the callback's Throwable is rethrown synchronously into
_PHPUnit_'s native verification, so an exception thrown only after a sleep/IO yield matches (or mismatches) exactly
as under plain _PHPUnit_, with no test changes. See [Additional Notes](#additional-notes).

To find more tests written using this approach, please check tests under folder [./tests/unit/manual](https://github.com/deminy/counit/tree/0.2.x/tests/unit/manual) (test suite "manual").

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
  * Tests may not have yet finished even it's marked as finished (by _PHPUnit_). Because of that, a test marked as "passed" (by PHPUnit) could still fail at a later time under _counit_. Because of this, the most reliable way to check if all test cases have passed or not is to check the exit code of _counit_.
  * The total # of assertions reported at the end of a run matches _PHPUnit_, and the per-testcase `assertions` attributes in the JUnit XML report (`--log-junit`, the only _PHPUnit_ 8/9 output that shows per-test assertion counts) are corrected before the report is written: every assertion is attributed to the test that performed it (segment accounting over the coroutine switches counit can observe), so the counts match a blocking run exactly whenever every yield is observable. A yield counit cannot observe (hooked network IO, a fully-qualified `\sleep()` call, a test class in the global namespace) leaves that test's own count too low — never another test's too high. Internally, an assertion performed after a yield still lands in whichever test's counting window _PHPUnit_ happens to have open; only the XML report is corrected.
  * Some exceptions/errors are not handled/reported the same.
* Annotation _@doesNotPerformAssertions_ and method _expectNotToPerformAssertions()_ (when called in _setUp()_ or at
  the top of the test body) are supported in both approaches: such tests report clean with zero assertions, same as
  under _PHPUnit_. Remaining limitations, both consequences of the risky verdict being rendered when the test's
  coroutine first yields:
  * A test declaring it performs no assertions but nevertheless performing one only **after** a sleep/IO yield is not
    flagged risky under _counit_, while _PHPUnit_ flags it. Run totals stay exact either way, and a *failing* late
    assertion still fails the run.
  * In a mixed suite, delayed assertions from *other* tests may land in such a test's counting window and flag it
    risky occasionally — the internal counting-window effect above (the corrected JUnit report is not affected by
    it).
  * Note a **class-level** _@doesNotPerformAssertions_ annotation is ignored by _PHPUnit_ 8/9 itself (only the
    method-level annotation is honored), with or without _counit_.
* Annotation _@depends_ (same-class, cross-class `Class::method`, and the `clone`/`shallowClone` options) works with
  exact _PHPUnit_ semantics in both approaches. _PHPUnit_ records a test's return value and verdict when its
  _runBare()_ returns — under _counit_, the coroutine's first yield: too early for either to be real. So when
  anything in the run depends on a test (_counit_ builds the reverse dependency graph up front, from _PHPUnit_'s own
  metadata), that test's coroutine is *joined*: it runs to true completion — _tearDown()_, error handling and all,
  with native semantics — before the run moves on. Dependents therefore receive the actual return value and are
  skipped when a producer fails (or turns out risky), even when that happens only after a yield. The joined producer
  gets no speedup of its own — its dependents could never have overlapped with it anyway — while every unrelated
  test still overlaps with it, including while it waits. Notes:
  * In the manual approach, _Counit::create()_ performs the join by itself when called directly from a test method
    something depends on, so the usual shape — compute into a by-ref variable inside the callback, return it from
    the test method — just works. A producer can also call _Counit::createAndJoin()_ instead, which returns the
    callback's value (and rethrows its failure) directly. No assertion credit is applied on the join path: the body
    completes before _PHPUnit_ reads the count, so the real assertions are counted — and a producer performing none
    stays risky, which is what makes _PHPUnit_ skip its dependents.
  * The whole-class form (`@depends Class::class`) requires _PHPUnit_ >= 9.3; older versions (with or without
    _counit_) warn that the target does not exist. A producer using a data provider passes _NULL_ to its
    dependents — under plain _PHPUnit_ 8/9 too; upstream behavior, not a _counit_ limitation.
* Method _expectException()_ and its friends work with exact _PHPUnit_ semantics even when the expected exception is
  thrown only after the test's first sleep/IO yield. The automatic approach already verified a matching throw
  natively — the whole _runBare()_ runs inside the coroutine — but its failing shapes surfaced only via the deferred
  end-of-run block, the manual approach failed prematurely with "exception not thrown" plus a deferred duplicate,
  and a warning/notice/deprecation expectation (_PHPUnit_ 9's _expectWarning()_ family, or
  _expectException()_ with _PHPUnit_'s _Warning_ class) broke outright: _PHPUnit_'s converting error handler is
  registered around _runBare()_ on the main coroutine and was already unregistered by the time the test's coroutine
  resumed. _Counit::create()_ now checks for a registered expectation once the body reaches its first yield and
  *joins* the coroutine — the same mechanism as for a _@depends_ producer: _PHPUnit_ keeps waiting (error handler
  still registered), the real Throwable is rethrown synchronously into its native verification, and match, mismatch,
  and never-thrown all report exactly as in blocking mode, in both approaches. No assertion credit is applied on the
  join path (the verification counts its own assertions), and only expectation-carrying tests that yield lose their
  own concurrency. An expectation declared only **after** the test's first yield is invisible at the join decision
  and keeps the old behavior — declare expectations before the first sleep/IO call.
* The timing of _tearDown()_ differs between the two approaches:
  * **The automatic approach** runs the whole test lifecycle — _setUp()_, the test method, and _tearDown()_ — inside
    one coroutine, so _tearDown()_ always observes a finished test body, exactly as under plain _PHPUnit_.
  * **The manual approach** leaves the lifecycle to _PHPUnit_: _tearDown()_ runs as soon as the callback passed to
    _Counit::create()_ first yields on a sleep/IO call — possibly while that callback is still running. Put
    order-sensitive cleanup inside a `try { ... } finally { ... }` block within the callback instead of in
    _tearDown()_.
* A _markTestSkipped()_ or _markTestIncomplete()_ call made after the test's first sleep/IO yield cannot change the
  test's status anymore: _PHPUnit_ already reported the test as passed at that yield. _counit_ lists such tests in a
  notice at the end of the run — their status remains "passed" — without failing the run, matching the exit code of a
  blocking run (where skipped/incomplete tests do not fail the run either). To have the skip honored, call it before
  the first yield.
* Option `--enforce-time-limit` (with `--default-time-limit` and the `@small`/`@medium`/`@large` size annotations)
  works with exact _PHPUnit_ semantics — at the price of the run's concurrency. _PHPUnit_ 8/9 time a limited test by
  wrapping the whole _runBare()_ call in a `pcntl_alarm()`/`SIGALRM` guard (package _phpunit/php-invoker_) and disarm
  the alarm the moment _runBare()_ returns. Under _counit_ that used to be the body's first yield: the measured window
  covered milliseconds, so an over-limit test simply passed — and on the joined paths (a _@depends_ producer, an
  _expectException()_ test), where _runBare()_ does stay alive for the test's real duration, the still-armed alarm's
  signal was delivered to whichever coroutine resumed first, aborting an unrelated test and failing a green run
  non-deterministically. So while the option is active, _counit_ joins **every** test's coroutine at its first yield
  (the same mechanism as for a _@depends_ producer): _PHPUnit_'s own timer measures the real duration and reports a
  timeout natively — a risky verdict carrying the "Execution aborted after N seconds" message, `--fail-on-risky`
  honored — and, with no concurrent test coroutines left, the alarm can only ever fire within the timed test's own
  window. Notes:
  * The whole run is serialized while the option is active: with it, _counit_ gives _PHPUnit_'s timings and
    _PHPUnit_'s speed — the option and _counit_'s concurrency are mutually exclusive by construction (per-test wall
    time is not a meaningful quantity while tests deliberately overlap). A notice on STDERR announces this; silence
    it with _COUNIT_SILENCE_TEARDOWN_NOTICE=1_.
  * No up-front assertion credit is applied on the joined path, so an aborted test that reached no assertion is
    flagged "did not perform any assertions" exactly as in blocking mode — _PHPUnit_ 8/9 count such a test's abort
    and its missing assertions as two risky entries.
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
* Annotations `@backupGlobals` and `@backupStaticAttributes` (and the matching
  `backupGlobals`/`backupStaticAttributes` configuration, the exclude lists, and `--strict-global-state`) work with
  exact _PHPUnit_ semantics — at the price of the backed-up test's concurrency. _PHPUnit_ 8/9 snapshot global state
  as (nearly) the first statement of _runBare()_ and restore as (nearly) the last. In the automatic approach the
  whole _runBare()_ runs inside the test's coroutine, so the backed-up test's own isolation was already correct —
  but the snapshot window spanned the test's entire concurrent lifetime, and the restore silently reverted every
  overlapping test's global writes (the restorer unsets every key absent from the snapshot). In the manual approach
  — whose _runBare()_ is _PHPUnit_'s own, running on the main coroutine — the restore additionally fired at the
  body's first yield: the body's own pre-yield writes were reverted mid-test and its post-yield writes leaked. An
  `@backupStaticAttributes` snapshot also captured _counit_'s **own** static bookkeeping (counit's classes are
  user-defined, so not on _PHPUnit_'s exclude list), skewing the run's reported assertion total. The fix has two
  halves, because global state — unlike a return value, an exception or an alarm — is process-wide: the backed-up
  test is *joined* (the `@depends`-producer mechanism, with no up-front assertion credit), and before its snapshot
  is taken every in-flight test coroutine is *drained* — giving the snapshot/restore pair the exclusive window
  blocking _PHPUnit_ gets for free. With that exclusive window the statics rewind becomes self-healing: everything
  _counit_ mutates inside the window belongs to the joined test itself. Notes:
  * A backed-up test first waits for everything already in flight, then runs serialized; every other test still
    overlaps normally, and a suite with no backup requests is completely unaffected. A run-wide
    `backupGlobals="true"` / `--globals-backup` (or the static-attributes equivalent) serializes the whole run.
  * `--strict-global-state` becomes correct as a side effect: both of its comparison snapshots bracket the same
    exclusive window, so its diff shows the test's real mutations — post-yield writes included — and nothing from
    bystanders.
  * A process-isolated test needs none of this and never did: isolation skips the snapshot machinery entirely (the
    child process's mutations die with it).
* Method _assertPostConditions()_ and `@postCondition`-annotated hook methods (the annotation exists as of _PHPUnit_
  9.1) work with exact _PHPUnit_ semantics.
  _PHPUnit_ 8/9 run the post-condition phase from _runBare()_, immediately after the test method invocation returns.
  In the automatic approach this was always correct — the whole _runBare()_ runs inside the test's coroutine, so the
  phase follows the truly finished body, with full concurrency kept. In the manual approach — whose _runBare()_ is
  _PHPUnit_'s own, running on the main coroutine — the phase used to fire at the body's first yield: the hooks
  inspected the test while its body was still in flight (failing loudly, or passing vacuously against pre-body
  state), and they ran even for a body that failed only after a yield, where blocking _PHPUnit_ skips the phase
  entirely. So _Counit::create()_ now detects — by reflection, per class — a caller whose class customizes the phase
  (an overridden _assertPostConditions()_, in a parent class too, or any `@postCondition` method) and *joins* its
  coroutine, the `@depends`-producer mechanism: the phase follows the real body, is skipped when the body failed,
  and a throwing hook fails/errors the test natively. Notes:
  * Only the customizing class's manual-approach tests lose their own concurrency; every other test still overlaps
    with them, including while they wait. A class customizing nothing — the overwhelming majority — is completely
    unaffected, and automatic-approach tests are never joined for this (they need no fix).
  * As on every join path, no assertion credit is applied: a customizing test performing no assertions is flagged
    risky, exactly as under blocking _PHPUnit_.
  * _assertPreConditions()_ / `@preCondition` methods need no handling in either approach: _PHPUnit_ invokes them
    right before the test method, inside the same coroutine (automatic) or before the body's first yield (manual).
  * The detection is caller-based like the _expectException()_ one: a _Counit::create()_ call made from a helper
    object rather than the test method itself is not joined.
* Option `--repeat` runs in blocking mode: repeated passes reuse the very same test objects, which cannot overlap
  with coroutines. The run behaves exactly as under plain _PHPUnit_ — correct, but without any speedup.

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
