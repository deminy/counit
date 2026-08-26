<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * The producer side of the cross-class dependency case in DependsExternalUserTest. Kept
 * alphabetically ahead of its consumer so the class runs first. Yields on purpose: the joined
 * producer must have truly finished -- real return value recorded -- before its cross-class
 * dependent resolves its input.
 *
 * @internal
 * @coversNothing
 */
class DependsExternalProducerTest extends TestCase
{
    public function testExternalProducer(): string
    {
        sleep(1);
        self::assertTrue(true);

        return 'external-value';
    }
}
