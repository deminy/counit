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
| `expectException()` and friends, exception thrown **before** the first yield | ✅ | ✅ |
| Stubs (`createStub()`, `createMock()` + `willReturn()` etc., no `expects()`) | ✅ | ✅ |
| Mock `->expects(...)` **satisfied before** the first yield | ✅ | ✅ |
| `setUp()`, `assertPreConditions()`, `setUpBeforeClass()`, `tearDownAfterClass()` | ✅ (run outside the coroutines: serialized, no speedup) | ✅ |
| `tearDown()` / `#[After]` hooks | ✅ run after the finished test body (see notes for two caveats) | ✅ (`@after`) |
| `markTestSkipped()` / `markTestIncomplete()` **before** the first yield | ✅ | ✅ |
| Process isolation (`#[RunInSeparateProcess]`, `#[RunTestsInSeparateProcesses]`, `--process-isolation`) | ✅ exact semantics — but no speedup, and each isolated test serializes the run | ✅ (annotations) |
| Test selection: `--filter`, `--testsuite`, `--group` / `#[Group]`, `--exclude-group` | ✅ | ✅ |
| `#[Requires*]` preconditions | ✅ | ✅ (`@requires`) |
| `--order-by` (start order); `#[TestDox]` naming | ✅ | ✅ |
| `--fail-on-risky` / `--fail-on-incomplete` / `--fail-on-skipped` | ✅ | ✅ |
| Exit code as the pass/fail signal | ✅ authoritative (failures after a yield force a non-zero exit) | ✅ |

## Incompatible features

| Feature | Counit 1.x | Counit 0.x (reference) |
|---|---|---|
| `#[Depends]` (and variants like `#[DependsExternal]`) | ❌ dependent tests receive `NULL` instead of the producer's return value, and "skip dependents on failure" is weakened | ❌ (`@depends`, same class of problem) |
| Mock `->expects(...)` verified for a call made **after** a yield | ❌ verified too early — false "called 0 times" failures | ✅ verified after the finished body |
| `expectException()` where the throw happens **after** a yield | ❌ the test fails "exception not thrown", and the exception resurfaces as a deferred failure | ❌ |
| `expectOutputString()` / `expectOutputRegex()` with output **after** a yield | ❌ one shared output buffer across all coroutines | ❌ |
| `markTestSkipped()` / `markTestIncomplete()` **after** a yield | ⚠️ status remains "passed"; listed in an end-of-run notice, exit code stays 0 | ⚠️ same |
| Risky check "This test did not perform any assertions" | ❌ never flagged (suppressed by the up-front assertion credit, by design) | ❌ |
| `#[BackupGlobals]` / `#[BackupStaticProperties]` / `#[WithEnvironmentVariable]` | ❌ snapshot/restore fires while other tests are mid-flight | ❌ (annotations) |
| `assertPostConditions()` | ❌ runs at the first yield, possibly before the body finished | ✅ runs after the finished body |
| Per-test reporting: per-testcase assertion counts and durations in `--log-junit`/`--log-otr` XML | ⚠️ JUnit counts are corrected via segment accounting: exact whenever the test's yields are observable (sleep()/usleep() in a namespaced test class, Counit::sleep()); a yield counit cannot observe (hooked network IO, a fully-qualified `\sleep()`, a test class in the global namespace) leaves that test's count too low — never another test's too high. `--log-otr` counts are not corrected. A failure after a yield is logged as PASSED in either XML — trust the exit code, not the logs. No other output surface (CLI, TestDox, TeamCity) shows per-test assertion counts at all | ⚠️ approximate, no XML correction |
| A `tearDown()` that throws | ⚠️ reported in the end-of-run failure block with a non-zero exit code, not as that test's own error | ⚠️ same |
| `--stop-on-failure` | ⚠️ reacts to pre-yield failures only; in-flight tests finish anyway | ⚠️ same |
| Result cache / `--order-by=defects` | ⚠️ polluted by provisional passes | ⚠️ same |
| `--enforce-time-limit` | ❌ only measures up to the first yield | ❌ |
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
* Attribute _#[Depends]_ (and its variants like _#[DependsExternal]_) doesn't work reliably in the automatic approach:
  * When a producer test declares a non-void return type, the dependent test receives _NULL_ instead of the producer's
    return value: in coroutine mode, _TestCase::invokeTestMethod()_ hands the test body off to a coroutine and returns
    _NULL_ unconditionally, so _PHPUnit_ never sees (and thus never stores or forwards) the real return value.
  * Even without return values, the "skip dependents when the dependency fails" guarantee is weakened: a dependency is
    marked as "passed" by _PHPUnit_ as soon as its coroutine first yields on a time/IO operation, so a dependent test
    can start (and run to completion) even when the dependency later fails.
  * More generally, _#[Depends]_ serializes exactly what _counit_ exists to overlap. Tests chained through _#[Depends]_
    are better kept under plain _PHPUnit_ (or restructured to share fixtures another way) than run through _counit_.
* Attribute _#[DoesNotPerformAssertions]_ (at method or class level) and method _expectNotToPerformAssertions()_ (when
  called in _setUp()_ or at the top of the test body) are supported in both approaches: such tests report clean with
  zero assertions, same as under _PHPUnit_. Two limitations remain, both consequences of the risky verdict being
  rendered when the test's coroutine first yields:
  * A test declaring it performs no assertions but nevertheless performing one only **after** a sleep/IO yield is not
    flagged risky under _counit_, while _PHPUnit_ flags it ("This test is not expected to perform assertions but
    performed 1 assertion"). Run totals stay exact either way, and a *failing* late assertion still fails the run.
  * In a mixed suite, delayed assertions from *other* tests may land in such a test's counting window and flag it
    risky occasionally — the per-test attribution caveat above, wearing a different hat.
* A related behavior note for projects running with `failOnRisky` enabled: a manual-approach test whose wrapped
  callable throws before its first yield no longer receives the assertion credit, so it may now report "This test did
  not perform any assertions" under _counit_ — which is exactly what plain _PHPUnit_ was already reporting for it.
* In the **automatic approach**, _counit_ takes the after-test hooks — _tearDown()_ and _#[After]_ methods — over from
  _PHPUnit_ and runs them inside the test's coroutine, right after the test body finishes, pass or fail. _tearDown()_
  therefore observes a finished test body, exactly as under plain _PHPUnit_ — closing a database connection or
  truncating tables there can no longer sabotage its own still-running test. Two differences from plain _PHPUnit_
  remain, both a consequence of _counit_ reporting a test as soon as its body first yields:
  * The hooks run **after** _PHPUnit_ has already reported the test's result. Ordering relative to the test body is
    preserved; ordering relative to the run's output is not.
  * A _tearDown()_ that throws cannot mark its own test as errored. _counit_ reports it in the failure block printed
    after the summary and exits with a non-zero code — as always, **the exit code of _counit_ is the authoritative
    pass/fail signal**.

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
