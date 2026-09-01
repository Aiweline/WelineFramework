<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendQueryOperationAttackGuard;

final class FrontendQueryOperationAttackGuardTest extends TestCase
{
    private string $storeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $storeDir = \defined('BP')
            ? BP . 'var/cache/frontend_worker/operation_attack/'
            : \sys_get_temp_dir() . '/weline-operation-attack/';
        $this->storeDir = $storeDir;
        if (\is_dir($this->storeDir)) {
            foreach (\glob($this->storeDir . '*.json') ?: [] as $file) {
                @\unlink($file);
            }
        }
    }

    public function testHumanAttackRuleBlocksAfterFifthRequestWithinTenSeconds(): void
    {
        $guard = new FrontendQueryOperationAttackGuard();
        $descriptor = [
            'attack' => [
                'enabled' => true,
                'rate_limit' => '5/10s',
                'challenge' => 'human',
            ],
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $guard->assertAllowed('cart', 'miniItems', $descriptor);
        }

        try {
            $guard->assertAllowed('cart', 'miniItems', $descriptor);
            self::fail('Expected human attack guard to block the sixth request.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('operation_attack_detected', $exception->getErrorCode());
            self::assertSame(429, $exception->getHttpStatus());
        }
    }

    public function testMissingAttackDescriptorIsIgnored(): void
    {
        $guard = new FrontendQueryOperationAttackGuard();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $guard->assertAllowed('cart', 'summary', []);
        }

        self::assertTrue(true);
    }
}
