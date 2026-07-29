<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayBackendCapabilityDeclaration;

final class GatewayBackendCapabilityDeclarationTest extends TestCase
{
    public function testStatelessDeclarationIsBoundToRuntimeGeneration(): void
    {
        $result = (new GatewayBackendCapabilityDeclaration())->resolve(
            ['backend_capability' => 'STATELESS'],
            17,
        );

        self::assertSame('stateless', $result['backend_capability']);
        self::assertSame('runtime_config', $result['backend_capability_source']);
        self::assertSame(17, $result['backend_capability_generation']);
    }

    public function testAbsentDeclarationRemainsDynamicAndFailClosed(): void
    {
        self::assertSame(
            [
                'backend_capability' => 'dynamic',
                'backend_capability_source' => 'runtime_derived',
                'backend_capability_generation' => 9,
            ],
            (new GatewayBackendCapabilityDeclaration())->resolve([], 9),
        );
    }

    /** @return iterable<string,array{string}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'shared session must be probed' => ['shared_session'];
        yield 'unknown mode' => ['sticky'];
        yield 'isolated is not a distribution claim' => ['isolated'];
    }

    #[DataProvider('invalidDeclarations')]
    public function testUnsafeOrUnknownDeclarationsAreRejected(string $mode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GatewayBackendCapabilityDeclaration())->resolve(
            ['backend_capability' => $mode],
            4,
        );
    }
}
