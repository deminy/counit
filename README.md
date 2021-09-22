# Summary
[![Library Status](https://github.com/deminy/counit/workflows/Unit%20Tests/badge.svg)](https://github.com/deminy/counit/actions)
[![Latest Stable Version](https://poser.pugx.org/deminy/counit/v/stable.svg)](https://packagist.org/packages/deminy/counit)
[![Latest Unstable Version](https://poser.pugx.org/deminy/counit/v/unstable.svg)](https://packagist.org/packages/deminy/counit)
[![License](https://poser.pugx.org/deminy/counit/license.svg)](https://packagist.org/packages/deminy/counit)

This package helps to run time/IO related unit tests (e.g., sleep function calls, database queries, API calls, etc) faster using [Swoole](https://github.com/swoole).

Here is a comparison when running the same test suite using _PHPUnit_ and _counit_ for a real project. In the test suite,
many tests make calls to method _\Deminy\Counit\Counit::sleep()_ to wait something to happen (e.g., wait data to expire).

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
    "deminy/counit": "~0.1.0"
  }
}
```

# How to Use It

Folder [./tests/unit]((https://github.com/deminy/counit/tree/master/tests/unit)) contains some sample tests, where we
perform following time-related tests:

* Test _sleep()_ function calls in PHP.
* Test data expiration in Redis.

To run the sample tests, please start the Docker containers and install Composer packages first:

```bash
docker-compose up --build -d
docker exec -ti $(docker ps -qf "name=swoole") sh -c "composer install -n"
```

There are three containers started: a PHP container, a Swoole container, and a Redis container. The PHP container doesn't
have the Swoole extension installed, while the Swoole container has it installed and enabled.

We can run the tests in different environments, with or without Swoole:

`#1` Run the tests using _PHPUnit_:

```bash
docker exec -ti $(docker ps -qf "name=php")    sh -c "./vendor/bin/phpunit" # command 1.
# or,
docker exec -ti $(docker ps -qf "name=swoole") sh -c "./vendor/bin/phpunit" # command 2.
```

`#2` Run the tests using _counit_:

```bash
docker exec -ti $(docker ps -qf "name=php") sh -c "./counit" # command 3
```

```bash
docker exec -ti $(docker ps -qf "name=swoole") sh -c "./counit" # command 4
```

The first three commands take about same amount of time to finish. The last command (the fourth command) uses _counit_
and runs in the Swoole container (where the Swoole extension is enabled); thus it's faster than the others:

<table>
  <tr>
    <th>&nbsp;</th>
    <th># of Tests</th>
    <th># of Assertions</th>
    <th>Time to Finish</th>
  </tr>
  <tr>
    <td><strong>counit (without Swoole), or PHPUnit</strong></td>
    <td rowspan="2">8</td>
    <td rowspan="2">12</td>
    <td>26 seconds</td>
  </tr>
  <tr>
    <td><strong>counit (with Swoole enabled)</strong></td>
    <td>7 seconds</td>
  </tr>
</table>

# Alternatives

This package allows to use Swoole to run multiple time/IO related tests without multiprocessing, which means all tests
can run within a same process. In the PHP ecosystem, there are other options to run unit tests in parallel, most end up
using multiprocessing:

* Process isolation in PHPUnit. This allows to run tests in separate PHP processes.
* Package [pestphp/pest](https://pestphp.com)
* Package [brianium/paratest](https://github.com/paratestphp/paratest)

# License

MIT license.
