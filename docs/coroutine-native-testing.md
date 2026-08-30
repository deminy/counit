# Testing Coroutine-Native Code Directly

_Part of the [counit](../README.md) documentation._

Everything in the main README's [How Does It Work](../README.md#how-does-it-work) section speeds up a test that
makes one blocking call — `sleep()`, a database query, an HTTP request — by letting it yield while other tests keep
running. A different kind of test needs several of its *own* coroutines to run concurrently against each other: one
exercising a mutex, a queue, or any other coroutine-native code directly, rather than making one call and waiting on
it. Under plain _PHPUnit_ such a test bootstraps its own _Swoole\Coroutine\Scheduler_:

```php
use Swoole\Coroutine\Scheduler;

$scheduler = new Scheduler();
$scheduler->add($coroutineA);
$scheduler->add($coroutineB);
$scheduler->start();
```

Under the `counit` runner with the Swoole extension enabled, though, the whole _PHPUnit_ run already happens inside
one coroutine (see [How Does It Work](../README.md#how-does-it-work)), and Swoole does not allow a second, nested
event loop — which is what `Scheduler::start()` starts internally, via `Event::wait()` — from inside a running one:

```
Fatal error: Swoole\Coroutine\Scheduler::start(): Unable to call Event::wait() in coroutine
```

## `CoroutineGroup::run()`

[_Deminy\Counit\CoroutineGroup::run()_](../src/CoroutineGroup.php) is a drop-in replacement for the snippet above
that is safe in both contexts — under plain _PHPUnit_ and under `counit`, with or without the Swoole extension
enabled:

```php
use Deminy\Counit\CoroutineGroup;

CoroutineGroup::run($coroutineA, $coroutineB);
```

It blocks until every given callable has finished. When nothing else is running yet (plain _PHPUnit_, or `counit`
without Swoole), it also waits out anything a callable spawns itself via `go()` / `Coroutine::create()` without
waiting for it first — the same as the `Scheduler` snippet above, since nothing else is running to end the wait
early. Once already running inside a coroutine — every test under `counit` with Swoole enabled — only the given
callables themselves are waited on; a callable that spawns further work should wait for it before returning, the
same discipline any coroutine-native code needs.

Unlike `Counit::create()`, it never returns before the work is done, so it does not participate in _counit_'s
assertion-attribution or late-failure/skip machinery: every callable runs to completion regardless of what its
siblings do, and the first Throwable any of them threw — a failed assertion among them — is rethrown once
everything has finished, exactly as it would be had that callable simply been called directly in the calling test
method instead of scheduled.

## Sequential calls to `run()` are independent of each other

Given

```php
CoroutineGroup::run($coroutineA, $coroutineB, $coroutineC);
```

and

```php
CoroutineGroup::run($coroutineA, $coroutineB);
CoroutineGroup::run($coroutineC);
```

these two are **not** equivalent once the Swoole extension is enabled. The first schedules all three callables
against each other — whichever finishes first (or yields longest) genuinely runs concurrently with the other two.
The second schedules `$coroutineA` and `$coroutineB` against each other in one group, waits for that whole group to
finish, and only then starts `$coroutineC` — which never overlaps with `$coroutineA` or `$coroutineB` at all.

This holds regardless of which of `run()`'s two Swoole-enabled shapes is active — no coroutine running yet, or
already running inside one (see the intro above) — for the same underlying reason in both cases: `run()` is an
ordinary, synchronous, blocking PHP function call. The second call's callables are not created,
added, or started until the first call has returned — exactly like any other pair of sequential statements (the same
reason `sleep(1); sleep(1);` takes two seconds, not one). What differs between the two branches is only the
*mechanism* behind that block, not the outcome:

* **No coroutine running yet** (plain _PHPUnit_, or `counit` without Swoole — `runViaScheduler()` internally): each
  call bootstraps its own `Swoole\Coroutine\Scheduler` and a fresh event loop, and `Scheduler::start()` blocks until
  that whole event loop has fully drained before returning. By the time the first call returns, its `Scheduler` and
  every coroutine it started are already gone — confirmed empirically, `Coroutine::stats()['coroutine_num']` reads
  `0` both before and after each call, and `Coroutine::getCid()` reads `-1` again in between. The second call then
  bootstraps a brand-new, entirely independent `Scheduler`.
* **Already running inside a coroutine** (`counit` with Swoole enabled — `runViaNestedCoroutines()` internally): each
  call spawns its callables directly into the one long-lived root coroutine `counit` itself opened for the whole
  test run, tracked by a fresh `Swoole\Coroutine\WaitGroup` created for that call. There is no event loop to
  bootstrap or tear down here, but the calling coroutine still blocks on that `WaitGroup::wait()` until its own
  call's group finishes before the next line of code — the next `run()` call — can execute.

In short: a single `run()` call is the unit of concurrency. Everything passed to one call is scheduled together;
nothing from a later call starts until the earlier call has returned.

## `CoroutineGroup::runWithTimeout()`

If a callable might never finish — a genuinely stuck mutex or queue, not just a slow one —
[_CoroutineGroup::runWithTimeout()_](../src/CoroutineGroup.php) gives up after a deadline and throws, instead of
risking a permanent hang:

```php
CoroutineGroup::runWithTimeout(5.0, $coroutineA, $coroutineB);
```

The deadline is only genuinely enforceable once already running inside a coroutine — every test under `counit` with
Swoole enabled, the common case — because Swoole gives no way to abandon `Scheduler::start()`'s own wait (or an
equivalent freshly-bootstrapped one) early from outside it. Everywhere else — plain _PHPUnit_, or `counit` without
Swoole — `$seconds` is accepted but not enforced, and `runWithTimeout()` behaves exactly like `run()`; see its own
docblock for the full breakdown.
