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
   * [The "global" Style](#the-global-style-recommended)
   * [The "case by case" Style](#the-case-by-case-style)
   * [Comparisons](#comparisons)
* [Additional Notes](#additional-notes)
* [Local Development](#local-development)
* [Alternatives](#alternatives)
* [TODOs](#todos)
* [License](#license)

# How Does It Work

Package _counit_ allows running multiple time/IO related tests concurrently within a single PHP process using Swoole. To know how exactly it works, I'd recommend checking this free online talk: [CSP Programming in PHP](https://nomadphp.com/video/306/csp-programming-in-php) (and here are the [slides](http://talks.deminy.in/csp.html)).

Package _counit_ is compatible with _PHPUnit_, which means:

1. Your test cases can be written in the same way as those for _PHPUnit_.
2. Your test cases can run directly under _PHPUnit_.

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
    <td rowspan="2">20</td>
    <td rowspan="2">310</td>
    <td>3 minutes and 54 seconds</td>
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

# Use "counit" in Your Project

* Write unit tests in the same way as those for _PHPUnit_. However, to make those tests faster, please write those time/IO related tests in one of the following two styles (details will be discussed in the next sections):
  * **The global style (recommended)**: Use class [_Deminy\Counit\TestCase_](https://github.com/deminy/counit/blob/master/src/TestCase.php) instead of _PHPUnit\Framework\TestCase_ as the base class.
  * **The case-by-case style**: Wrap each test case inside the callback function for method [_Deminy\Counit\Counit::create()_](https://github.com/deminy/counit/blob/master/src/Counit.php), and use method [_Deminy\Counit\Counit::sleep()_](https://github.com/deminy/counit/blob/master/src/Counit.php) instead of the PHP function _sleep()_.
* Use the binary executable _./vendor/bin/counit_ instead of _./vendor/bin/phpunit_ when running unit tests.
* Have the Swoole extension installed. If not installed, _counit_ will work exactly same as _PHPUnit_ (in blocking mode).
* Optional steps:
  * use PHPUnit extension [_Deminy\Counit\CounitExtension_](https://github.com/deminy/counit/blob/master/src/CounitExtension.php) as shown in file [phpunit.xml.dist](https://github.com/deminy/counit/blob/master/phpunit.xml.dist). This is to wait the whole test suite to finish before printing out the summary information at the end.

# Examples

Folder [./tests/unit](https://github.com/deminy/counit/tree/master/tests/unit) contains some sample tests, where we
have following time-related tests included:

* Test slow HTTP requests.
* Test long-running MySQL queries.
* Test data expiration in Redis.
* Test _sleep()_ function calls in PHP.

## Setup Test Environment

To run the sample tests, please start the Docker containers and install Composer packages first:

```bash
docker-compose up -d
docker exec -ti $(docker ps -qf "name=swoole") sh -c "composer install -n"
```

There are five containers started: a PHP container, a Swoole container, a Redis container, a MySQL container, and a web
server. The PHP container doesn't have the Swoole extension installed, while the Swoole container has it installed and enabled.

As said previously, test cases can be written in the same way as those for _PHPUnit_. However, to run time/IO related
tests faster with _counit_, we need to make some adjustments when writing those test cases; these adjustments can be
made in two different styles.

## The "global" Style (recommended)

In this style, each test case runs in a separate coroutine automatically.

For test cases written in this style, the only change to make on your existing test cases is to use class
_Deminy\Counit\TestCase_ instead of _PHPUnit\Framework\TestCase_ as the base class.

A typical test case of the global style looks like this:

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

This style assumes there is no immediate assertions in test cases, nor assertions before a sleep() function call or a
coroutine-friendly IO operation. Test cases like following still work, but they will trigger some warning messages when
tested:

```php
class SleepTest extends Deminy\Counit\TestCase
{
  public function testAssertionSuppression(): void
  {
    self::assertTrue(true, 'Trigger an immediate assertion.');
    // ......
  }
}
```

We can rewrite this test class using the "case by case" style (discussed in the next section) to eliminate the warning messages.

To find more tests written in this style, please check tests under folder [./tests/unit/global](https://github.com/deminy/counit/tree/master/tests/unit/global) (test suite "global").

## The "case by case" Style

In this style, you make changes directly on a test case to make it work asynchronously. 

For test cases written in this style, we need to use class _Deminy\Counit\Counit_ accordingly in the test cases where
we need to wait for PHP execution or to perform IO operations. Typically, following method calls will be used:

* Use method _Deminy\Counit\Counit::create()_ to wrap the test case.
* Use method _Deminy\Counit\Counit::sleep()_ instead of the PHP function _sleep()_ to wait for PHP execution. You will
  need some knowledge on Swoole if you want to make other IO related tests run asynchronously.

A typical test case of the case-by-case style looks like this:

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

To find more tests written in this style, please check tests under folder [./tests/unit/case-by-case](https://github.com/deminy/counit/tree/master/tests/unit/case-by-case) (test suite "case-by-case").

## Comparisons

Here we will run the tests under different environments, with or without Swoole.

`#1` Run the test suites using _PHPUnit_:

```bash
# To run test suite "global":
docker exec -ti $(docker ps -qf "name=php")    sh -c "./vendor/bin/phpunit --testsuite global"
# or,
docker exec -ti $(docker ps -qf "name=swoole") sh -c "./vendor/bin/phpunit --testsuite global"

# To run test suite "case-by-case":
docker exec -ti $(docker ps -qf "name=php")    sh -c "./vendor/bin/phpunit --testsuite case-by-case"
# or,
docker exec -ti $(docker ps -qf "name=swoole") sh -c "./vendor/bin/phpunit --testsuite case-by-case"
```

`#2` Run the test suites using _counit_ (without Swoole):

```bash
# To run test suite "global":
docker exec -ti $(docker ps -qf "name=php")    sh -c "./counit --testsuite global"

# To run test suite "case-by-case":
docker exec -ti $(docker ps -qf "name=php")    sh -c "./counit --testsuite case-by-case"
```

`#3` Run the test suites using _counit_  (with extension Swoole enabled):

```bash
# To run test suite "global":
docker exec -ti $(docker ps -qf "name=swoole") sh -c "./counit --testsuite global"

# To run test suite "case-by-case":
docker exec -ti $(docker ps -qf "name=swoole") sh -c "./counit --testsuite case-by-case"
```

The first two sets of commands take about same amount of time to finish. The last set of commands uses _counit_ and runs
in the Swoole container (where the Swoole extension is enabled); thus it's faster than the others:

<table>
  <tr>
    <th>&nbsp;</th>
    <th>Style</th>
    <th># of Tests</th>
    <th># of Assertions</th>
    <th>Time to Finish</th>
  </tr>
  <tr>
    <td rowspan="2"><strong>counit (without Swoole), or PHPUnit</strong></td>
    <td>global</td>
    <td rowspan="4">16</td>
    <td rowspan="4">24</td>
    <td>48 seconds</td>
  </tr>
  <tr>
    <td>case by case</td>
    <td>48 seconds</td>
  </tr>
  <tr>
    <td rowspan="2"><strong>counit (with Swoole enabled)</strong></td>
    <td>global</td>
    <td>7 seconds</td>
  </tr>
  <tr>
    <td>case by case</td>
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
  * The # of assertions reported could be different from _PHPUnit_.
  * Some exceptions/errors are not handled/reported the same.

# Local Development

There are pre-built images [deminy/counit](https://hub.docker.com/r/deminy/counit) for running the sample tests. Here are
the commands to build the images:

```bash
docker build -t deminy/counit:php-only       -f ./dockerfiles/php/Dockerfile    .
docker build -t deminy/counit:swoole-enabled -f ./dockerfiles/swoole/Dockerfile .
```

# Alternatives

This package allows to use Swoole to run multiple time/IO related tests without multiprocessing, which means all tests
can run within a single PHP process. In the PHP ecosystem, there are other options to run unit tests in parallel, most
end up using multiprocessing:

* Process isolation in PHPUnit. This allows to run tests in separate PHP processes.
* Package [brianium/paratest](https://github.com/paratestphp/paratest)
* Package [pestphp/pest](https://pestphp.com)

# TODOs

* Better integration with _PHPUnit_.
  * Deal with annotation _@doesNotPerformAssertions_ in the global style.
  * Make # of assertions consistent with the one reported from _PHPUnit_.
* Better error/exception handling.

# License

MIT license.
