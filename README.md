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
   * [Register the PHPUnit Extension](#register-the-phpunit-extension)
* [Examples](#examples)
   * [Setup Test Environment](#setup-test-environment)
   * [The Automatic Approach](#the-automatic-approach-recommended)
   * [The Manual Approach](#the-manual-approach)
   * [Comparisons](#comparisons)
* [Compatibility with PHPUnit](#compatibility-with-phpunit)
* [Testing Coroutine-Native Code Directly](#testing-coroutine-native-code-directly)
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
    "deminy/counit": "^1.1"
  }
}
```

Please pick the _counit_ version matching the version of _PHPUnit_ used in your project:

| counit | PHPUnit | PHP |
|--------|--------------------|-----------|
| ^1.1   | ~13.0              | >= 8.4.1  |
| ^1.1   | ~12.5.24           | >= 8.3    |
| ^0.3   | ~8.0, ~9.0         | >= 7.2    |

# Use "counit" in Your Project

* Write unit tests in the same way as those for _PHPUnit_. However, to make those tests faster, please write those time/IO related tests using one of the following two approaches (details will be discussed in the next sections):
  * **The automatic approach (recommended)**: Use class [_Deminy\Counit\TestCase_](https://github.com/deminy/counit/blob/master/src/TestCase.php) instead of _PHPUnit\Framework\TestCase_ as the base class; every test method is then wrapped in a coroutine automatically.
  * **The manual approach**: Wrap each test case inside the callback function for method [_Deminy\Counit\Counit::create()_](https://github.com/deminy/counit/blob/master/src/Counit.php), and use method [_Deminy\Counit\Counit::sleep()_](https://github.com/deminy/counit/blob/master/src/Counit.php) instead of the PHP function _sleep()_.
* Use the binary executable _./vendor/bin/counit_ instead of _./vendor/bin/phpunit_ when running unit tests.
* Have the Swoole extension installed. If not installed, _counit_ will work exactly same as _PHPUnit_ (in blocking mode).
* **Register PHPUnit extension [_Deminy\Counit\CounitExtension_](https://github.com/deminy/counit/blob/master/src/CounitExtension.php) in your _phpunit.xml_ / _phpunit.xml.dist_.** See [Register the PHPUnit extension](#register-the-phpunit-extension) below; this package's own [phpunit.xml.dist](https://github.com/deminy/counit/blob/master/phpunit.xml.dist) registers it too.

<a id="register-the-phpunit-extension"></a>
## Register the PHPUnit Extension

Without the extension registered, _PHPUnit_ prints its summary while your tests' coroutines are **still running**, so
the run's reported numbers describe an unfinished run. Every compatibility guarantee in
[Compatibility with PHPUnit](docs/compatibility.md) — reported time, assertion totals, late failures, skips and risky
verdicts — assumes it is registered. Register it unless you have a specific reason not to.

The syntax depends on your _PHPUnit_ version. For _PHPUnit_ 10 and above (counit ^1.1):

```xml
<extensions>
    <bootstrap class="Deminy\Counit\CounitExtension"/>
</extensions>
```

For _PHPUnit_ 8 and 9 (counit ^0.3), where the class implements the older hook interfaces:

```xml
<extensions>
    <extension class="Deminy\Counit\CounitExtension"/>
</extensions>
```

A run that creates its first coroutine without the extension registered says so once on STDERR, so the failure mode
below is not a silent one:

```
counit notice: PHPUnit extension Deminy\Counit\CounitExtension is not registered, so nothing waits for the tests'
coroutines: [...]. Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.
```

### What goes wrong without it

* **The reported time is not the real time.** A test's coroutine returns to _PHPUnit_ at its first yield, so _PHPUnit_
  reports the test as finished and moves on while the body is still sleeping. With nothing waiting for those
  coroutines, the summary is printed almost immediately and the process then sits silent until they drain. A real
  example: a 358-test suite whose true wall-clock time was 9.6 seconds reported `Time: 00:00.415`, then took another 9
  seconds to exit. Wall-clock time (e.g. `time ./vendor/bin/counit`) is the honest number in that situation; the
  extension makes _PHPUnit_'s own number honest again.
* **Assertion totals are wrong, and not even self-consistent.** Assertions performed after a yield land in whichever
  test's counting window happens to be open, and the up-front credit from `Counit::create($callable, $count)` is never
  reconciled. In the same suite, the 60 slowest tests reported 1652 assertions when run in isolation but implied 2142
  when run as part of the full suite — the same tests, the same code. With the extension registered both figures agree
  at 1646, which is also what a fully blocking _PHPUnit_ run reports. **Treat any assertion total recorded without the
  extension as unreliable**, including totals committed to a project's own documentation.
* **Late verdicts degrade to a STDERR block.** A failure, error, skip or risky verdict that a test reaches after a
  yield is normally replayed through _PHPUnit_'s own events at the end of the run, so it lands in the summary, the
  listings, the exit code and the JUnit report. That replay is the extension's work. Without it the runner falls back
  to printing such verdicts to STDERR (still forcing a non-zero exit code for failures), outside the summary and
  absent from `--log-junit` output, and the affected test's recorded status stays "passed".

The extension is a reporting fix, not a performance trade-off: it waits for coroutines that were going to run anyway,
so it does not slow a suite down measurably (~0.01s on the 358-test suite above).

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

With [the PHPUnit extension registered](#register-the-phpunit-extension), the total # of assertions reported at the end
of a run matches _PHPUnit_ exactly, and the per-testcase counts in the
JUnit XML report (`--log-junit`) are corrected as well — exact whenever counit can observe the test's yields
(sleep()/usleep() calls in a namespaced test class, and Counit::sleep()). An assertion performed after a yield counit
cannot observe (e.g. hooked network IO) goes missing from its own test's JUnit count — but is never added to another
test's. See [Compatibility with PHPUnit](docs/compatibility.md).

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

That leaves one combination to document: running tests **under the _counit_ runner with Swoole enabled** — the fast,
concurrent mode this package exists for. **[docs/compatibility.md](docs/compatibility.md)** covers it in full: a matrix
of compatible features, a matrix of incompatible ones (each with a _Counit 0.x_ reference column for the _PHPUnit_
~8.0/~9.0 maintenance line), and the per-feature notes behind every ⚠️/❌ entry.

# Testing Coroutine-Native Code Directly

Everything above speeds up a test that makes one blocking call — `sleep()`, a database query, an HTTP request — by
letting it yield while other tests keep running. A different kind of test needs several of its *own* coroutines to
run concurrently against each other: one exercising a mutex, a queue, or any other coroutine-native code directly,
rather than making one call and waiting on it. Under plain _PHPUnit_ such a test bootstraps its own
_Swoole\Coroutine\Scheduler_:

```php
use Swoole\Coroutine\Scheduler;

$scheduler = new Scheduler();
$scheduler->add($coroutineA);
$scheduler->add($coroutineB);
$scheduler->start();
```

Under the `counit` runner with the Swoole extension enabled, though, the whole _PHPUnit_ run already happens inside
one coroutine (see [How Does It Work](#how-does-it-work)), and Swoole does not allow a second, nested event loop —
which is what `Scheduler::start()` starts internally, via `Event::wait()` — from inside a running one:

```
Fatal error: Swoole\Coroutine\Scheduler::start(): Unable to call Event::wait() in coroutine
```

[_Deminy\Counit\CoroutineGroup::run()_](src/CoroutineGroup.php) is a drop-in replacement for the snippet
above that is safe in both contexts — under plain _PHPUnit_ and under `counit`, with or without the Swoole extension
enabled:

```php
use Deminy\Counit\CoroutineGroup;

CoroutineGroup::run($coroutineA, $coroutineB);
```

It blocks until every given callable — and everything it spawns via `go()` / `Coroutine::create()` — has finished,
exactly like the `Scheduler` snippet above. Unlike `Counit::create()`, it never returns before the work is done, so
it does not participate in _counit_'s assertion-attribution or late-failure/skip machinery: an assertion or exception
inside one of the callables behaves exactly as it would running synchronously in the calling test method, in every
context.

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
* The package doesn't work exactly the same as when running under _PHPUnit_ — see
  [Compatibility with PHPUnit](docs/compatibility.md) for the two feature matrices and the per-feature details. The
  one rule to remember: a test _PHPUnit_ has already marked "passed" can still fail later under _counit_, so the exit
  code of the run — not the summary line alone — is the authoritative pass/fail signal.

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
