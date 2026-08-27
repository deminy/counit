# Compatibility with PHPUnit

_Part of the [counit](../README.md) documentation._

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
❌ do not rely on it under Swoole. Details for every ⚠️/❌ entry are in [Feature notes](#feature-notes).

Rows are grouped by area — writing tests, fixtures and hooks, test doubles, outcomes and diagnostics, execution
control, and reporting — and, within each area, ordered from the most widely used feature to the most niche one,
so the entries a typical suite depends on come first.

## Compatible features

| Feature | Counit 1.x | Counit 0.x |
|---|---|---|
| **Writing tests** | | |
| Test discovery and naming (`test*` methods, `#[Test]`) | ✅ | ✅ (`@test`) |
| Assertions (`assert*()`); the run's reported **total** | ✅ exact | ✅ exact |
| `#[DataProvider]` / `#[TestWith]` | ✅ (providers themselves run serialized, at collection time) | ✅ (`@dataProvider`) |
| `expectException()` and friends | ✅ exact — a test with a registered expectation (message, code and object variants included) is joined at its first yield, so PHPUnit verifies the real Throwable natively: match, mismatch, and never-thrown all report as in blocking mode, in both approaches. The expectation must be declared before the first yield (one declared only after it is invisible at the join decision and keeps the old premature-failure behavior) | ✅ — same join fix, in both approaches (the automatic approach verified a matching post-yield throw natively even before it, since the whole `runBare()` runs inside the coroutine); PHPUnit 9's `expectWarning()` family after a yield is fixed by the join as well — the converting error handler stays registered while PHPUnit waits |
| `markTestSkipped()` / `markTestIncomplete()` | ✅ counted, listed and honored by `--fail-on-skipped`/`--fail-on-incomplete` exactly as in blocking mode. Before the first yield the verdict is fully native; after it, the verdict cannot be made native (the Throwable only exists once _PHPUnit_ already reported the test as passed), so _counit_ emits _PHPUnit_'s own `Test\Skipped`/`Test\MarkedIncomplete` event at the end of the run, once every coroutine has drained — a skip signalled from a `Counit::defer()` cleanup included — and writes the real `<skipped/>` element into the JUnit report. Residuals: the test's internally recorded status stays "passed" (inert: the summary, the listings, the exit code, the JUnit report and the result cache all carry the real verdict), `--stop-on-skipped`/`--stop-on-incomplete` cannot react to a verdict learned after the run (moot in practice: an active `--stop-on-*` option joins every test — see the verdict-sequencing row), and the late S/I progress markers are appended after the progress line rather than interleaved, with the listings ordered by completion | ✅ same fix, in both approaches (both shared the divergence — the skip Throwable propagates out of the coroutine-wrapped `runBare()` into the already-returned branch): each deferred verdict is replayed into the run's `TestResult` through the public `addError()`, which classifies `SkippedTest`/`IncompleteTest` natively with no count compensation needed, and _JunitXmlCorrector_ writes the real `<skipped/>` elements there too. `--fail-on-skipped`/`--fail-on-incomplete` exist only on _PHPUnit_ 9 (exact there); stray `S`/`I` progress characters trail the progress line, like the deferred risky verdicts' stray `R` |
| `#[Requires*]` preconditions | ✅ | ✅ (`@requires`) |
| `#[DoesNotPerformAssertions]`; `expectNotToPerformAssertions()` | ✅ (method and class level; the call may sit in `setUp()` or at the top of the test body) | ✅ (`@doesNotPerformAssertions`; method level only — _PHPUnit_ 8/9 itself ignores the class-level annotation) |
| `expectOutputString()` / `expectOutputRegex()` | ✅ exact — Swoole gives every coroutine its own output-buffer stack (there never was a shared one: the body's output simply never reached _PHPUnit_'s buffer, which lives on the runner coroutine — so expectations compared against `''` unconditionally, before or after a yield). counit now captures the coroutine's output and replays it into _PHPUnit_'s buffer; a test with a registered expectation is joined at its first yield, so match, mismatch and never-printed all verify natively against the real, complete output, in both approaches. Such a test gets no concurrency of its own; every other test still overlaps with it. The expectation must be registered before the first yield (like an `expectException()`) | ✅ the automatic approach was always correct there, with full concurrency kept (the whole `runBare()` — buffer and verification included — runs inside the test's coroutine); the manual approach takes the same capture-and-replay fix, applied in `Counit::create()`/`createAndJoin()` and scoped to that approach (join detected via the public `hasExpectationOnOutput()`) |
| `#[Depends]` / `#[DependsExternal]` / `#[DependsOnClass]` | ✅ exact semantics — dependents receive the producer's real return value (deep/shallow clone variants included) and are skipped when the producer fails, even after a yield. The producer itself (for `#[DependsOnClass]`: every test of the depended-on class) is run to completion before the run moves on, so it gets no speedup of its own — its dependents could not have overlapped with it anyway, and unrelated tests still do | ✅ (`@depends`, incl. `clone`/`shallowClone` and cross-class `Class::method` targets) — same producer-join fix; there, the manual approach's `Counit::create()` even joins producers automatically. `@depends Class::class` requires PHPUnit >= 9.3 (upstream limitation) |
| **Fixtures and hooks** | | |
| `setUp()`, `assertPreConditions()` and the class-level hooks | ✅ `setUpBeforeClass()`/`tearDownAfterClass()` included (all run outside the coroutines: serialized, no speedup) | ✅ — but `setUp()` and `assertPreConditions()` run *inside* the test's coroutine there (concurrent, with speedup); only the class-level hooks are serialized. A `setUp()` that aborts after its own yield falls into the deferred post-yield reporting |
| `tearDown()` / `#[After]` hooks | ✅ run after the finished test body — and still run (natively, with blocking semantics) for a test whose `setUp()` threw or skipped (see notes for two caveats) | ✅ (`@after`) — fully native inside the coroutine, including when `setUp()` aborted |
| A `tearDown()` / `#[After]` hook that throws or skips | ✅ same late replay as the row above: a hook Throwable errors (or, for an assertion-level one, fails) the test with blocking's summary and exit code, and a skip signalled from such a hook — a test **failure** under blocking _PHPUnit_, never a skip — fails it exactly as blocking classifies it (_PHPUnit_'s own `SkippedWithMessageException` is an `AssertionFailedError`). Fully native (not replayed) semantics apply on a joined `#[Depends]` producer and on a test whose `setUp()` threw or skipped (their hooks run natively). Residual: the hooks still run at the coroutine's end — cleanup-before-verdict timing, the permanent carve-out | ✅ a hook throw after a yield is replayed through the public `addError()` — the test errors with blocking's summary and exit code 2, natively-in-effect (before a yield it errors natively as always); a hook **skip** is a genuine skip on _PHPUnit_ 8/9 — upstream semantics, exit code 0 — and 0.x honors it: natively before a yield, via the late-skip replay after one. Joined producers' hooks are fully native there too |
| `assertPostConditions()` and `#[PostCondition]` hook methods | ✅ exact — a test class customizing the post-condition phase (an overridden `assertPostConditions()`, or any `#[PostCondition]` method) has every one of its tests joined at the first yield, so _PHPUnit_ runs the phase after the finished body and a throwing hook fails/errors/skips the test natively, in both approaches; the phase is skipped when the body failed, even after a yield. Such a class's tests get no concurrency of their own — and, like every joined test, are flagged risky when they perform no assertions, exactly as in blocking mode — while every other test still overlaps with them. A class customizing nothing is untouched: _PHPUnit_ skips the empty default hook, so counit does not join | ✅ the automatic approach was always correct there, with full concurrency kept (the whole `runBare()` runs inside the coroutine, so the phase follows the finished body); the manual approach takes the same join fix, applied in `Counit::create()` and scoped to that approach — `@postCondition` annotations included, which exist upstream only as of _PHPUnit_ 9.1 (below that, _PHPUnit_ invokes `assertPostConditions()` directly and the annotation is inert, blocking mode included) |
| **Test doubles** | | |
| Stubs (`createStub()`/`createMock()` without `expects()`) | ✅ never verified by _PHPUnit_, never joined — full concurrency kept, and their `willReturn()` configuration survives every yield | ✅ — but in the manual approach any registered double joins its test at the first yield (_PHPUnit_ 8/9 verify-and-reset every registered mock; without the join, a stub's `willReturn()` configuration was stripped mid-body, post-yield calls returning null), trading that test's own concurrency for correctness; the automatic approach keeps full concurrency |
| Mock `->expects(...)` verification | ✅ exact — a test that has registered a mock carrying an invocation-count rule (`once()`, `exactly()`, `never()`, `atLeast()`, …) is joined at its first yield, so _PHPUnit_ verifies the truly finished body and classifies natively in both directions: an expectation satisfied only after a sleep/IO yield passes, and one violated after it fails. Before the fix, the premature verification not only false-failed late calls — it also silently **disarmed** a passing mock (verification strips the invocation mocker), letting a post-yield `never()` violation exit 0. Such a test gets no concurrency of its own; stubs and mocks without a count rule are never verified by _PHPUnit_ and are not joined, so they keep theirs. A mock created — or an `expects()` rule first configured — only **after** the first yield is invisible at the join decision and stays unverified (see notes) | ✅ the automatic approach delivers correct verdicts with full concurrency kept (the whole `runBare()`, verification included, runs inside the coroutine), and a post-yield violation — or a failed verification — is now replayed through the public `addFailure()`: a native failure with blocking's summary and exit code (see the late failure/error row); the manual approach takes the same join fix — deliberately wider there (**any** registered test double joins, `createStub()` included), since _PHPUnit_ 8/9 verify-and-reset every registered mock: even a matcher-less stub's `willReturn()` configuration used to be stripped at the first yield |
| **Test outcomes and diagnostics** | | |
| Exit code as the pass/fail signal | ✅ authoritative (failures after a yield force a non-zero exit) | ✅ |
| A test **failure or error** after the first yield | ✅ for a body assertion/exception or a `Counit::defer()` cleanup on a non-joined path, reported with blocking _PHPUnit_'s exact summary, listings and exit code: the verdict cannot be made native (the Throwable only exists once _PHPUnit_ already reported the test as passed), so _counit_ replays it through _PHPUnit_'s own `Test\Failed`/`Test\Errored` event at the end of the run, once every coroutine has drained — classification is upstream's own rule (an assertion-level Throwable fails the test with its comparison diff, anything else errors it), the listing shows the real message and location (data-provider identity included), `FAILURES!`/`ERRORS!` and the native exit code (1/2) match blocking mode, and the JUnit report gets the real `<failure>`/`<error>` element with recomputed aggregates. The old end-of-run STDERR block remains only as the fail-soft path (changed _PHPUnit_ internals, or no test object in scope), still forcing a non-zero exit code. Residuals: the test's internally recorded status stays "passed" (inert: the summary, the listings, the exit code, the JUnit report and the result cache all carry the real verdict), `--stop-on-failure`/`--stop-on-error` cannot react to a verdict learned after the run (moot in practice: an active `--stop-on-*` option joins every test — see the verdict-sequencing row), the late F/E progress markers trail the progress line, and the listings' late entries carry a couple of counit frames in their stack traces | ✅ same fix, on that branch's seams: each deferred verdict (its TestCase object stashed at deferral time, under collision-free keys) is replayed through the public `TestResult::addFailure()`/`addError()` — upstream's own classification — and _JunitXmlCorrector_ writes the `<failure>`/`<error>`/`<skipped>` elements the _PHPUnit_ 8/9 JUnit listener no-ops on for late verdicts, with recomputed aggregates. This also retired that branch's old post-yield reporting model (deferred block + exit 1): a post-yield error now exits 2, exactly as blocking — the automatic approach's post-yield mock violations and converted diagnostics included |
| A diagnostic triggered **after** a test's first yield | ✅ exact for deprecations, warnings and notices — _counit_ registers a converting error handler of its own for exactly the windows _PHPUnit_'s own cannot cover (while the coroutine _PHPUnit_ runs on is suspended) and hands what it catches to _PHPUnit_'s own `ErrorHandler` at trigger time, so attribution, `@`-suppression, the baseline, `#[IgnoreDeprecations]`, the deprecation filters and the issue-trigger classification are all _PHPUnit_'s own: the summary counts, the `--display-*` listings (incl. the per-test "Triggered by" blocks), a generated or consumed baseline and the `--fail-on-deprecation`/`-warning`/`-notice` exit codes all match a blocking run exactly, in both approaches. A post-yield `E_USER_ERROR` — which used to KILL the run with exit code 255 — now aborts the body through _PHPUnit_'s own throw, and the replayed `Test\Errored` (see the late failure/error row) errors the test with blocking's exact summary and exit code 2. The per-test progress marker (`D`/`W`/`N`) is printed before a late diagnostic happens and stays a `.`, late diagnostics sort at the end of their listing section, and `--stop-on-deprecation` and friends cannot react to one (moot in practice: an active `--stop-on-*` option joins every test — see the verdict-sequencing row) | ✅ same fix, in both approaches (both shared the divergence — on _PHPUnit_ 8/9 the converting handler is registered by `TestResult::run()` **outside** `runBare()`, and since that line converts diagnostics into **exceptions**, the loss was a silent false pass: blocking `Errors: N` exit 2 → `OK` exit 0): _counit_'s delegating handler hands each post-yield diagnostic to _PHPUnit_'s own converting handler, which throws the exact `Error\*` exception at the trigger site — the aborted body's Throwable is then replayed through the public `addError()` (see the late failure/error row), so the `ERRORS!` summary, the listings and exit code 2 match blocking exactly. Pre-yield conversion and `@`-suppression stay native; a run with every `convert*ToExceptions` setting off converts nothing, as in blocking |
| PHPUnit's error/exception-handler snapshot | ✅ the "test … did not remove its own error/exception handlers" risky checks are exact for every test whose yields counit can observe — the handler stacks are process-global under Swoole (unlike the output-buffer stack, which Swoole does isolate), so counit lifts each coroutine's own handlers off the shared stack while it is suspended and puts them back when it resumes. A test's handler therefore survives its own sleep/IO yield, _PHPUnit_'s snapshot/restore sees only the baseline, and the verdict — all four messages — is reported against the test that actually leaked: natively when it never yielded, through an end-of-run risky event otherwise (`Risky: N` and `--fail-on-risky` exact). No join, no serialization. A coroutine resumed at a point counit cannot observe (hooked network/DB IO, a fully-qualified `\sleep()`, a test class in the global namespace) is left to the previous behavior — silence, never a false accusation — and late verdicts sit at the end of the risky listing, so `--stop-on-risky` cannot react to them (moot in practice: an active `--stop-on-*` option joins every test — see the verdict-sequencing row) | — the check does not exist on _PHPUnit_ 8/9 |
| **Running tests: selection and execution control** | | |
| Test selection: `--filter`, `--testsuite`, `--group` | ✅ (`#[Group]` and `--exclude-group` included) | ✅ |
| `--order-by` (start order); `#[TestDox]` naming | ✅ | ✅ |
| `--fail-on-risky` / `--fail-on-incomplete` / `--fail-on-skipped` | ✅ | ✅ |
| Verdict sequencing: the `--stop-on-*` family | ✅ exact — `--stop-on-defect`/`-error`/`-failure`/`-warning`/`-risky`/`-deprecation`/`-notice`/`-skipped`/`-incomplete`, threshold forms included: these options make _PHPUnit_ decide before each test, from the verdicts it has so far, whether to start it at all; under _counit_ a post-yield verdict used to exist only once the whole loop had finished, so the run never stopped (only pre-yield failures could). While any `--stop-on-*` option is active, every test is joined at its first yield: each verdict is native and final before the next scheduling decision, and the run stops exactly where blocking stops — identical summaries and exit codes. The run serializes for the duration (STDERR notice): sequencing verdicts is inherently serial | ✅ same join fix (the seven `--stop-on-*` flags of _PHPUnit_ 8/9; the state is read lazily from the first test's `TestResult` via fail-soft reflection — that branch has no configuration seam) |
| Process isolation | ✅ exact semantics for `#[RunInSeparateProcess]`, `#[RunTestsInSeparateProcesses]` and `--process-isolation` — but no speedup, and each isolated test serializes the run | ✅ (annotations) |
| `#[BackupGlobals]` / `#[BackupStaticProperties]` / `#[WithEnvironmentVariable]` | ✅ exact — the `backupGlobals`/`backupStaticProperties` configuration, the `Exclude*FromBackup` attributes and `--strict-global-state` are covered too: a test PHPUnit brackets with a global-state snapshot is joined at its first yield, and every other test's coroutine is drained **before** its snapshot is taken, so PHPUnit's own snapshot/restore covers the real test body with nothing else running. Such a test gets no concurrency of its own and awaits everything already in flight; every other test still overlaps. A run configured with `backupGlobals="true"` / `--globals-backup` (or the static-properties equivalent) therefore serializes completely (STDERR notice). `#[BackupGlobals(false)]` cannot override a configuration-level `true` — upstream _PHPUnit_ behavior, mirrored | ✅ same drain-and-join fix (annotations `@backupGlobals`/`@backupStaticAttributes`; `#[WithEnvironmentVariable]` does not exist on _PHPUnit_ 8/9) — the failure profile it fixes differs: the whole `runBare()` runs inside the coroutine there, so the test's own isolation was already correct, but the snapshot spanned its entire concurrent lifetime and the restore reverted every overlapping test's global writes. No serialized-run STDERR notice on 0.x |
| `--enforce-time-limit` | ✅ exact, `--default-time-limit` and `#[Small]`/`#[Medium]`/`#[Large]` included — every test is joined at its first yield while the option is active, so PHPUnit times the real `runBare()` and reports a timeout natively (risky verdict, `--fail-on-risky` honored), in both approaches. The run is serialized for the duration: with the option, counit gives PHPUnit's timings and PHPUnit's speed (a STDERR notice announces this). Needs `ext-pcntl`, as under plain _PHPUnit_; marginally more lenient at the exact boundary (see notes) | ✅ same join fix (with `@small`/`@medium`/`@large` annotations) — PHPUnit 8/9's identical `pcntl_alarm()` guard over `runBare()` then times and reports natively; the risky verdict carries php-invoker's "Execution aborted" message, and the aborted test is flagged risky twice there (the abort plus its missing assertions) |
| `--repeat` / `--retry` | ✅ exact — the same verdict-sequencing join: `--repeat N` stops a test's repetitions at its first failure (the remaining ones are skipped, as in blocking mode), and `--retry N` re-attempts a test that failed only after its yield until its first success — precisely the flaky post-yield shape the flag exists for, which used to be a silent no-op (the test was recorded as passed at its first yield, so no retry ever fired). Serialized run, STDERR notice. Both options exist only as of _PHPUnit_ 13; neither is on the 12.5 line | ⚠️ `--repeat` runs in blocking mode there — correct, but without speedup; `--retry` does not exist on _PHPUnit_ 8/9 |
| **Reports, caches and CLI plumbing** | | |
| Code coverage — the **aggregate** report | ✅ exact for `--coverage-text`/`-html`/`-clover`/`-cobertura`/`-php`, the numbers coverage gates read — post-yield test code executes during _counit_'s end-of-run drain, outside every per-test coverage window, so the aggregate used to lose those lines silently (measured: 70% of lines under blocking _PHPUnit_ vs 30% under _counit_, for a fixture whose second half runs after a sleep — wrong exactly on the IO-adjacent code this package exists for). _counit_ now opens one coverage window of its own around the drain; the aggregate is a union over all windows, so it matches a blocking run exactly, with full concurrency kept. Per-test **attribution** stays wrong by construction: drain-collected lines land under a synthetic id, and lines a coroutine happens to run while a joined test's window is open are attributed to that test — do not rely on `--coverage-xml`'s per-test data (see notes) | ✅ same drain-window fix, via the public `TestResult::getCodeCoverage()`; note _PHPUnit_ 8/9 HONOR coverage annotations (unlike 10+), so a `@coversNothing` test contributes nothing there — in blocking mode too — and _PHPUnit_ 8 spells the filter option `--whitelist` |
| Result cache / `--order-by=defects` / `--order-by=duration` | ✅ exact where it used to be silently corrupted: _PHPUnit_ persists its test-run history file before _counit_'s drain, so a test failing only after its first yield was recorded as a pass — actively **erasing** a defect a previous (e.g. blocking) run had written, losing exactly the tests `--order-by=defects` exists to front-load — and every recorded duration was time-to-first-yield. _counit_ now re-persists the file once every coroutine has drained: the replayed late verdicts (failures, errors, skips, incompletes, risky) land in the defect map exactly as blocking records them, and the times are replaced with each test's measured wall-clock duration (approximate under concurrency, of blocking's magnitude — see the per-test reporting row). Residual: a test skipped after its first yield keeps a 0-second `times` entry where blocking records none (functionally identical for `--order-by=duration` — an absent entry also sorts as 0) | ✅ exact there with no re-persist needed: the deferred-verdict replays notify the cache extension's listener adapter like native verdicts, and the cache persists only after _counit_'s hook — the defect maps match blocking byte for byte; the times are overwritten with measured wall-clock durations before that persist (blocking _PHPUnit_ 8/9 record skipped tests' times too, so none are excluded). _PHPUnit_ 8 serializes the cache as an object where 9 writes JSON — corrected identically |
| Invocations that run no tests | ✅ `--version`/`--help`/`--list-*` commands, unknown options, a missing test file or configuration: byte-identical to plain _PHPUnit_ — output and exit code. _PHPUnit_ finishes these by calling `exit()` directly, which Swoole intercepts inside a coroutine: the run used to die with a bogus "An error occurred inside PHPUnit" block, exit code 255 and a raw fatal backtrace (`./counit --version` alone did). _counit_ now routes invocations that run no tests through its plain blocking branch — detected through _PHPUnit_'s own CLI parser and configuration-file finder (`-c <dir>` resolves like upstream and stays concurrent), positively identified only, so a real run can never silently lose its concurrency — and catches the intercepted exit for anything discovered later. Residual: a bail-out discovered only after the XML configuration is loaded (malformed XML, missing bootstrap, a nonexistent test-suite directory) keeps its clean message but exits 255 with _PHPUnit_'s crash block, where blocking exits 1 or 2 | ✅ same class of crash, smaller fix: nothing re-classifies the bail-out on _PHPUnit_ 8/9, so unwrapping the intercepted `Swoole\ExitException`'s status restores byte-identical output and exit codes with no pre-flight routing (a missing test file fatals upstream on _PHPUnit_ 8.0 — in plain _PHPUnit_ and _counit_ alike) |

## Incompatible features

| Feature | Counit 1.x | Counit 0.x |
|---|---|---|
| **Test outcomes and diagnostics** | | |
| Risky check "This test did not perform any assertions" (and its mirror, "…is not expected to perform assertions but performed N assertions") | ⚠️ exact for every test that never yields (the up-front assertion credit is declined once the body is known finished, so PHPUnit reaches the verdict natively, at the right moment) and for every yielding test whose yields counit can observe (`sleep()`/`usleep()` in a namespaced test class, `Counit::sleep()`): those verdicts are emitted at the end of the run through _PHPUnit_'s own risky event — they appear in the risky listing with the right location, count into `Risky: N`, and `--fail-on-risky` exits 1 exactly as in blocking mode. A test resumed at a point counit cannot observe (hooked network/DB IO, a fully-qualified `\sleep()`, a test class in the global namespace) has an untrustworthy per-test tally and is deliberately **not** reported — silence, never a false accusation. This carve-out is closed by construction, not by choice: deciding it would require observing every coroutine switch, and Swoole exposes no such hook to PHP code (`Coroutine::set()`/hook flags are configuration, `Coroutine::defer()` fires only at coroutine end, `declare(ticks)` is per-file in *user* code, and a per-coroutine assertion counter would mean patching _PHPUnit_ itself). `--stop-on-risky` cannot react to a verdict reached after the run (moot in practice: an active `--stop-on-*` option joins every test — see the verdict-sequencing row), and the deferred verdicts sit at the end of the risky listing | ⚠️ same fix, on that branch's seams (the mirror check reads "annotated with `@doesNotPerformAssertions` but performed N assertions" there): the deferred verdicts are handed to the public `TestResult::addFailure()`, and a stray `R` progress character may trail the progress line — _PHPUnit_ 8/9's printer echoes late verdicts |
| The "test printed unexpected output" check and JUnit `system-out`, for **post-yield** output of a test that registers no output expectation | ⚠️ exact while `--disallow-test-output` is active — counit then joins every test, so _PHPUnit_ sees the complete output and reports the risky verdict natively; the run serializes for the duration (STDERR notice). Without the option, such a test's post-yield output goes to the terminal in one batch instead of into _PHPUnit_'s buffer — visible, but absent from the unexpected-output annotation and `--log-junit`'s `system-out`. A test leaving its own output buffer open is likewise only reported natively when it is joined | ⚠️ never reported; in the automatic approach the post-yield stray output is swallowed by the coroutine-local buffer rather than printed |
| **Reports and artifacts** | | |
| Per-test reporting: per-testcase assertion counts and durations in `--log-junit`/`--log-otr` XML | ⚠️ JUnit counts are corrected via segment accounting: exact whenever the test's yields are observable (sleep()/usleep() in a namespaced test class, Counit::sleep()); a yield counit cannot observe (hooked network IO, a fully-qualified `\sleep()`, a test class in the global namespace) leaves that test's count too low — never another test's too high. JUnit **verdicts** are exact: a post-yield failure/error/skip gets its real `<failure>`/`<error>`/`<skipped>` element and the testsuite `failures`/`errors`/`skipped` aggregates are recomputed to match (see the late-verdict rows). JUnit **durations** are rewritten with each test's measured wall-clock time (_PHPUnit_'s own telemetry only ever sees time-to-first-yield for a non-joined test — 0.001s for a 1s test); approximate under concurrency (a coroutine also waits its turn while others run) but of blocking's magnitude, with the testsuite `time` aggregates recomputed as blocking's per-test sums. `--log-otr` is not corrected at all — counts or verdicts: its writer streams through `XMLWriter` straight to the report file while the run is still going, so there is no buffered document left to correct at the end (fixing it would mean re-parsing and rewriting the finished file). No other output surface (CLI, TestDox, TeamCity) shows per-test assertion counts at all | ⚠️ same JUnit count correction (segment accounting; exact for observable yields, own count too low otherwise); JUnit **verdicts** and **durations** are corrected there too (see the late-verdict and result-cache rows); `--log-otr` does not exist on 0.x |
| Code coverage: per-test **attribution** (`--coverage-xml` per-test data; `#[CoversClass]`/`#[CoversFunction]` target filtering of concurrent tests) | ⚠️ wrong by construction — the aggregate report is exact (see the compatible table), but which test a covered line is credited to depends on whose coverage window happened to be open when the coroutine ran it | ⚠️ same |

## Feature notes

What every ⚠️/❌ entry above means in practice — and the caveats behind some of the ✅ ones.

* Ways in which a _counit_ run does not behave exactly like a _PHPUnit_ run:
  * Tests may not have yet finished even it's marked as finished (by _PHPUnit_). Because of that, a test marked as "passed" (by PHPUnit) could still fail at a later time under _counit_. When that happens, _counit_ waits for every test to truly finish and then replays the verdict through _PHPUnit_'s own `Test\Failed`/`Test\Errored` events: the summary, the failure/error listings, the exit code and the JUnit report all end up exactly as in a blocking run (see the late failure/error matrix row). Only a verdict that cannot be replayed (changed _PHPUnit_ internals, or no test object in scope) falls back to a printed end-of-run block with a forced non-zero exit code — either way, the exit code of _counit_ is the authoritative pass/fail signal.
  * The total # of assertions reported at the end of a run matches _PHPUnit_, and the per-testcase `assertions` attributes in the JUnit XML report (`--log-junit`) are corrected before the report is written. The correction attributes every assertion to the test that performed it (segment accounting over the coroutine switches counit can observe, including sleep()/usleep() calls in namespaced test classes and assertions counted directly on the test object after a yield, e.g. from a relocated _tearDown()_), so the counts match a blocking run exactly whenever every yield is observable. A yield counit cannot observe (hooked network IO, a fully-qualified `\sleep()` call, a test class in the global namespace) leaves that test's own count too low — never another test's too high. With Swoole's preemptive scheduler enabled, segment accounting is switched off and the correction falls back to removing the up-front credit and adding post-report instance counts only.
  * Verdicts that can only be learned once every coroutine has drained — the late failure/error/skip/incomplete/
    risky replays described in the matrix — share three cosmetic residuals, stated here once: the per-test
    progress markers (`F`/`E`/`S`/`I`/`R`, and `D`/`W`/`N` for late diagnostics) are appended after the progress
    line instead of interleaved into it; the late entries sort at the end of their listing section, ordered by
    completion; and their stack traces can carry a couple of _counit_ frames on top of the test's own. The
    summary counts, the listings' content, the exit codes, the JUnit report and the result cache are all exact.
  * Aggregate code coverage is exact: since each test hands its body off to a coroutine at the first yield,
    post-yield code executes during _counit_'s end-of-run drain — outside every per-test coverage window — and the
    aggregate report used to lose those lines entirely (a coverage gate would be fed numbers less than half the
    truth, silently). _counit_ opens one additional coverage window around the drain; the aggregate is a union over
    all windows, so the report matches a blocking run exactly while keeping full concurrency. What stays wrong is
    per-test *attribution*: lines collected in the drain window are credited to a synthetic `counit-drain` id, and
    lines a coroutine runs while some joined test's coverage window is open are credited to that test (and subject
    to its `#[CoversClass]`/`#[CoversFunction]` target filtering). Aggregate percentages and line/method/class hit
    sets are reliable; `--coverage-xml`'s per-test breakdown is not.
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
* Mock `->expects(...)` expectations work with exact _PHPUnit_ semantics even when the expected call happens — or a
  violation occurs — only after the test's first sleep/IO yield. _PHPUnit_ verifies every registered mock from
  _runBare()_, right after the test-method invocation returns — under _counit_, the body's first yield — and that
  verification is not read-only: a mock whose expectation is already satisfied at that instant passes verification
  and then has its invocation mocker **stripped** (its expectations, its `willReturn()` configuration and its
  counting all go with it). So an expectation satisfied only later failed prematurely ("was never invoked"), while
  a `never()` violated — or a `once()` exceeded — only after the yield was silently allowed, exit code 0: a missed
  real bug, not just a mistimed failure. Since mocks are registered inside `setUp()`/the body (the registry is
  empty when the coroutine starts), _counit_ checks at the body's first yield whether a registered mock carries an
  invocation-count rule and then *joins* the coroutine — the same mechanism as for a registered
  `expectException()`: _PHPUnit_'s own verification covers the truly finished body, classifying satisfied,
  violated and never-satisfied natively, and a violation mid-body throws at call time inside the joined body,
  exactly as in blocking mode. Notes:
  * Only tests carrying a verifiable mock lose their own concurrency; every other test still overlaps with them,
    including while they wait. Stubs (`createStub()`) and mocks without an invocation-count rule are skipped — and
    not stripped — by _PHPUnit_'s verification itself, so they are not joined either. As on the other join paths,
    no assertion credit is applied — the verification counts its one assertion per mock natively.
  * A mock created — or an `expects()` rule first configured — only **after** the test's first yield is invisible
    at the join decision and is never verified at all (_PHPUnit_'s verification loop has already run, and
    _runBare()_ has already cleared the registry): the familiar declared-after-yield carve-out. Create and
    configure mocks before the first sleep/IO call, the overwhelmingly common shape. In these shapes _PHPUnit_'s
    "no expectations were configured" notice can also fire earlier than in blocking mode.
  * The detection reads _PHPUnit_'s internal mock registry (one private property, of the same shape on PHPUnit
    12.5 and 13); should a future _PHPUnit_ release change it, _counit_ prints a notice once and degrades to the
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
    converting handler is enabled at a time. The *separate* concern of a non-joined test's post-yield
    deprecations/warnings not being converted at all is addressed by its own mechanism — see the next bullet.
* Diagnostics (deprecations/warnings/notices) triggered after a test's first sleep/IO yield work with exact
  _PHPUnit_ semantics for the counts, the listings, the baseline and the `--fail-on-*` exit codes. _PHPUnit_'s own
  converting error handler is enabled right before the test-method invocation and disabled the moment it returns —
  under _counit_, the body's first yield — so everything a non-joined test triggered from there on used to reach
  PHP's default handler: printed raw, never counted. _counit_ now registers a delegating handler of its own for
  exactly the windows _PHPUnit_'s cannot cover — while the coroutine _PHPUnit_ runs on is suspended, which is the
  only time test coroutines can run — and hands every diagnostic to _PHPUnit_'s own `ErrorHandler` **at trigger
  time**, on the triggering coroutine's own call stack. Attribution (data sets included), `@`-suppression, the
  baseline, `#[IgnoreDeprecations]`, `#[WithoutErrorHandler]`, the deprecation source filters and the issue-trigger
  classification are therefore all upstream code, byte-identical to blocking mode. Notes:
  * The handler is deliberately **not** permanently registered (PHPUnit 12.5's `enable()` gives up when any handler
    is already on the stack — a permanent shim silently disables PHPUnit's own conversion for the whole run) and
    deliberately **not** pushed per test-coroutine slice (a coroutine resumed at an unobservable yield would be
    stranded holding it, and its next `restore_error_handler()` would pop PHPUnit's instead). Registering only
    across the main coroutine's own yields — all of which _counit_ controls — leaves _PHPUnit_'s machinery a stack
    that looks exactly as it would without _counit_.
  * A post-yield `E_USER_ERROR` is handed to the same handler, which throws for it exactly as in blocking mode: the
    body aborts into the deferred end-of-run failure block, exit code 1 (blocking errors the test natively, exit
    code 2). Before this fix it killed the whole run with exit code 255.
  * The per-test `D`/`W`/`N` progress marker is printed when the test finishes and stays a `.` for a later
    diagnostic; late diagnostics sort at the end of their listing section; `--stop-on-deprecation`/`-warning`/
    `-notice` cannot react to a diagnostic raised after the runner moved past the test.
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
* A _markTestSkipped()_ or _markTestIncomplete()_ call made after the test's first sleep/IO yield works with exact
  _PHPUnit_ semantics for the run-level surfaces. The verdict cannot be made native — the Throwable is thrown
  mid-body, so unlike an exception, output or mock expectation there is nothing registered at the first yield for a
  join decision to detect, and _PHPUnit_ has already reported the test as passed. But everything _PHPUnit_ derives
  from a skip/incomplete — the `Skipped:`/`Incomplete:` summary counts, the `--display-skipped`/
  `--display-incomplete` listings and the `--fail-on-skipped`/`--fail-on-incomplete` exit codes — comes from the
  `Test\Skipped`/`Test\MarkedIncomplete` **events**, not from the test's recorded status, so _counit_ emits those
  events itself once every coroutine has drained (the same end-of-run mechanism as the deferred risky verdicts). A
  skip signalled from a `Counit::defer()` cleanup is classified and replayed the same way (it used to be misfiled as
  a deferred *failure*, forcing exit code 1 where blocking mode exits 0). Notes:
  * The test's recorded status stays "passed": the JUnit XML report and the result cache still record a pass (the
    per-test reporting row's "trust the exit code, not the logs" advice applies), and
    `--stop-on-skipped`/`--stop-on-incomplete` cannot react to a verdict that is only learned once the run has
    already finished. A skip issued before the first yield remains fully native, `--stop-on-*` included.
  * The late `S`/`I` progress markers are appended after the progress line rather than interleaved (the printer
    rides the very events being replayed), and the skipped/incomplete listings are ordered by completion.
  * Should a future _PHPUnit_ release change the internals the replay compensates through (one reflection into the
    result collector), nothing is emitted and the tests are listed in a STDERR notice instead — degraded, never a
    wrong count.
