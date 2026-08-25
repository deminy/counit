<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;

/**
 * The #[DoesNotPerformAssertions] attribute also works at class level: PHPUnit merges class- and
 * method-level metadata when resolving the flag, before the test method is invoked. Counit::create()
 * must therefore decline the assertion credit for every test of this class, the same way it does for
 * a method-level attribute.
 *
 * @internal
 * @coversNothing
 */
#[DoesNotPerformAssertions]
class ClassLevelDoesNotPerformAssertionsTest extends TestCase
{
    /**
     * The class-level attribute exempts this test from the no-assertions risky check; crediting it
     * would flip that into the "not expected to perform assertions" risky flag instead.
     */
    public function testDoesNotPerformAssertions(): void
    {
        sleep(1);
    }
}
