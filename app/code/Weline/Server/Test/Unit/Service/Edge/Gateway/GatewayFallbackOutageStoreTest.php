<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayFallbackOutageStore;

final class GatewayFallbackOutageStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-gateway-outage-' . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ((array)@\glob($this->directory . DIRECTORY_SEPARATOR . '*') as $file) {
            if (\is_string($file) && \is_file($file) && !\is_link($file)) {
                @\unlink($file);
            }
        }
        @\rmdir($this->directory);
        parent::tearDown();
    }

    public function testSameMasterRestoresOriginalOutageTimestamp(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);

        self::assertSame(1000, $store->markDown('project-a', 41, 7, 1000));
        self::assertSame(1000, $store->markDown('project-a', 41, 7, 1089));
    }

    public function testNewMasterStartsANewOutageWindowAndHealthyStateClearsIt(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);
        self::assertSame(1000, $store->markDown('project-a', 41, 7, 1000));
        self::assertSame(1089, $store->markDown('project-a', 42, 8, 1089));

        $store->clear('project-a');

        self::assertSame(1100, $store->markDown('project-a', 42, 8, 1100));
    }
}
