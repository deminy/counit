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
    "deminy/counit": "^0.3"
  }
}
```

Please pick the _counit_ version matching the version of _PHPUnit_ used in your project:

| counit | PHPUnit | PHP |
|--------|--------------------|-----------|
| ^1.1   | ~13.0              | >= 8.4.1  |
| ^1.1   | ~12.5.24           | >= 8.3    |
| ^0.3   | ~8.0, ~9.0         | >= 7.2    |

This branch is the 0.x series, the maintenance line for _PHPUnit_ ~8.0 and ~9.0. New work happens in the 1.x series
and is back-ported here where it applies.

# Use "counit" in Your Project

* Write unit tests in the same way as those for _PHPUnit_. However, to make those tests faster, please write those time/IO related tests using one of the following two approaches (details will be discussed in the next sections):
  * **The automatic approach (default choice — start here)**: Use class [_Deminy\Counit\TestCase_](https://github.com/deminy/counit/blob/0.x/src/TestCase.php) instead of _PHPUnit\Framework\TestCase_ as the base class; every test method is then wrapped in a coroutine automatically.
  * **The manual approach**: only needed when a test class can't extend _Deminy\Counit\TestCase_ — most commonly because it already extends something else (a framework's own base class, or your project's shared internal one) and PHP only allows one parent. Wrap the specific test method's body in [_Deminy\Counit\Counit::create()_](https://github.com/deminy/counit/blob/0.x/src/Counit.php), and use [_Deminy\Counit\Counit::sleep()_](https://github.com/deminy/counit/blob/0.x/src/Counit.php) instead of the PHP function _sleep()_ — no change to the class's `extends` clause required.
* Use the binary executable _./vendor/bin/counit_ instead of _./vendor/bin/phpunit_ when running unit tests.
* Have the Swoole extension installed. If not installed, _counit_ will work exactly same as _PHPUnit_ (in blocking mode).
* **Register PHPUnit extension [_Deminy\Counit\CounitExtension_](https://github.com/deminy/counit/blob/0.x/src/CounitExtension.php) in your _phpunit.xml_ / _phpunit.xml.dist_.** See [Register the PHPUnit extension](#register-the-phpunit-extension) below; this package's own [phpunit.xml.dist](https://github.com/deminy/counit/blob/0.x/phpunit.xml.dist) registers it too.

<a id="register-the-phpunit-extension"></a>
## Register the PHPUnit Extension

Without the extension registered, _PHPUnit_ prints its summary while your tests' coroutines are **still running**, so
the run's reported numbers describe an unfinished run. Every compatibility guarantee in
[Compatibility with PHPUnit](docs/compatibility.md) — reported time, assertion totals, late failures, skips and risky
verdicts — assumes it is registered. Register it unless you have a specific reason not to.

On this line (_PHPUnit_ 8 and 9), the class implements _PHPUnit_'s test-hook interfaces, so it is registered as an
`<extension>`:

```xml
<extensions>
    <extension class="Deminy\Counit\CounitExtension"/>
</extensions>
```

Note that _PHPUnit_ 10 removed those interfaces, so the 1.x line uses a different element
(`<bootstrap class="Deminy\Counit\CounitExtension"/>`). A snippet copied from the 1.x documentation into a _PHPUnit_
8/9 project — or the reverse — does not work.

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
  example from a project on the 1.x line: a 358-test suite whose true wall-clock time was 9.6 seconds reported
  `Time: 00:00.415`, then took another 9 seconds to exit. Wall-clock time (e.g. `time ./vendor/bin/counit`) is the
  honest number in that situation; the extension makes _PHPUnit_'s own number honest again.
* **Assertion totals are wrong, and not even self-consistent.** Assertions performed after a yield land in whichever
  test's counting window happens to be open, and the up-front credit from `Counit::create($callable, $count)` is never
  reconciled. In the same suite, the 60 slowest tests reported 1652 assertions when run in isolation but implied 2142
  when run as part of the full suite — the same tests, the same code. With the extension registered both figures agree
  at 1646, which is also what a fully blocking _PHPUnit_ run reports. **Treat any assertion total recorded without the
  extension as unreliable**, including totals committed to a project's own documentation.
* **Late verdicts degrade to a STDERR block.** A failure, error, skip or incomplete verdict that a test reaches after a
  yield is normally replayed into the run's `TestResult` at the end of the run, so it lands in the summary, the
  listings, the exit code and the JUnit report. That replay is the extension's work. Without it the runner falls back
  to printing such verdicts to STDERR (still forcing a non-zero exit code for failures), outside the summary and
  absent from `--log-junit` output, and the affected test's recorded status stays "passed".

The extension is a reporting fix, not a performance trade-off: it waits for coroutines that were going to run anyway,
so it does not slow a suite down measurably.

# Examples

Folder [./tests/unit/automatic](https://github.com/deminy/counit/tree/0.x/tests/unit/automatic) and [./tests/unit/manual](https://github.com/deminy/counit/tree/0.x/tests/unit/manual) contain some sample tests, where we
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
Use it for every test class you can — it's the simplest and least error-prone way to get _counit_'s speedup, and
needs no ongoing discipline once adopted. The only reason not to is if a test class must extend something other than
_PHPUnit\Framework\TestCase_ already (PHP allows only one parent class) — see
[The Manual Approach](#the-manual-approach) below for that case.

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
JUnit XML report (`--log-junit`, the only _PHPUnit_ 8/9 output that shows per-test assertion counts) are corrected as
well — exact whenever counit can observe the test's yields (sleep()/usleep() calls in a namespaced test class, and
Counit::sleep()). An assertion performed after a yield counit cannot observe (e.g. hooked network IO) goes missing
from its own test's JUnit count — but is never added to another test's. See [Compatibility with PHPUnit](docs/compatibility.md).

To find more tests written using this approach, please check tests under folder [./tests/unit/automatic](https://github.com/deminy/counit/tree/0.x/tests/unit/automatic) (test suite "automatic").

<a id="the-case-by-case-style"></a>
## The Manual Approach

In this approach (previously called _the "case by case" style_), you make changes directly on a test case to make it
work asynchronously, without changing which class it extends.

**Reach for this only when the automatic approach isn't an option.** PHP allows a class to extend only one parent, so
if your test classes must already extend something else — a framework's own base class (e.g. Laravel's
_Illuminate\Foundation\Testing\TestCase_, Symfony's _KernelTestCase_/_WebTestCase_) or a shared internal base class
your project already relies on — you can't also extend _Deminy\Counit\TestCase_. The manual approach lets you speed
up individual slow test methods with that `extends` clause left untouched. It also makes trialing _counit_ on a
handful of tests lower-risk than committing a whole class hierarchy to the automatic approach up front.

It comes with real trade-offs the automatic approach doesn't have: forgetting to wrap a slow test body doesn't error,
it just silently leaves that one test blocking; calling PHP's native _sleep()_ instead of _Counit::sleep()_ inside a
coroutine blocks the whole scheduler, not just that test; and the optional assertion-count parameter below is
bookkeeping you have to get right yourself. Prefer the automatic approach whenever your test class can use it.

If what you actually want is several of your *own* coroutines running concurrently against each other — testing a
mutex, a queue, or other coroutine-native code directly, rather than speeding up one blocking call — see
[_Deminy\Counit\CoroutineGroup_](docs/coroutine-native-testing.md) instead; it's a dedicated, nesting-safe primitive
for that, usable from either approach, including from inside a manual-approach _Counit::create()_ call.

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
[Compatibility with PHPUnit](docs/compatibility.md).

The same join applies to a test that has an exception expectation registered — _expectException()_ and friends,
declared before the _Counit::create()_ call as usual: the callback's Throwable is rethrown synchronously into
_PHPUnit_'s native verification, so an exception thrown only after a sleep/IO yield matches (or mismatches) exactly
as under plain _PHPUnit_, with no test changes. See [Compatibility with PHPUnit](docs/compatibility.md).

To find more tests written using this approach, please check tests under folder [./tests/unit/manual](https://github.com/deminy/counit/tree/0.x/tests/unit/manual) (test suite "manual").

## Comparisons

Both approaches perform identically — the choice between them is about which one you're able to use (see
[The Manual Approach](#the-manual-approach) above), not about speed. **[docs/comparisons.md](docs/comparisons.md)**
has the proof: benchmarks running _counit_'s own sample test suites under every combination of _PHPUnit_/_counit_
and with/without Swoole.

# Compatibility with PHPUnit

_Counit_ is designed as a drop-in companion to _PHPUnit_, not a replacement. In short:

* When the Swoole extension is not enabled, unit tests written for _PHPUnit_ and/or _counit_ run in _PHPUnit_ and/or
  _counit_ in exactly the same way, without any changes: every _counit_ API falls back to plain blocking behavior.
* Unit tests written for _counit_ run in _PHPUnit_ without any issue, **with or without** the Swoole extension
  loaded: _counit_'s coroutine behavior activates only inside the coroutine scheduler that the _counit_ runner itself
  starts, which plain _PHPUnit_ never does — a loaded-but-idle Swoole extension changes nothing.

That leaves one combination to document: running tests **under the _counit_ runner with Swoole enabled** — the fast,
concurrent mode this package exists for. **[docs/compatibility.md](docs/compatibility.md)** covers it in full: a matrix
of compatible features, a matrix of incompatible ones (each with a _Counit 1.x_ reference column for the current
_PHPUnit_ ~12.5.24/~13.0 line), and the per-feature notes behind every ⚠️/❌ entry.

# Testing Coroutine-Native Code Directly

Everything above speeds up a test that makes one blocking call — `sleep()`, a database query, an HTTP request — by
letting it yield while other tests keep running. A different kind of test needs several of its *own* coroutines to
run concurrently against each other — one exercising a mutex, a queue, or any other coroutine-native code directly —
which needs a different tool. **[docs/coroutine-native-testing.md](docs/coroutine-native-testing.md)** covers
`Deminy\Counit\CoroutineGroup`, a nesting-safe, timeout-capable substitute for `Swoole\Coroutine\Scheduler`, including
how sequential calls to it interact with each other.

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
