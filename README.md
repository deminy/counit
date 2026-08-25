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
