# Comparisons

_Part of the [counit](../README.md) documentation._

Both [the automatic approach](../README.md#the-automatic-approach-recommended) and
[the manual approach](../README.md#the-manual-approach) perform identically — the choice between them is about which
one you're able to use (see [The Manual Approach](../README.md#the-manual-approach) for when the automatic approach
isn't an option), not about speed. The benchmarks below confirm it, across every environment, by running _counit_'s
own sample test suites (see [Setup Test Environment](../README.md#setup-test-environment) for the Docker stack these
commands need).

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
