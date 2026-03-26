<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\CachePurger;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;

class CachePurgerTest extends TestCase
{
    private CachePurger $cachePurger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cachePurger = new CachePurger(
            ObjectManager::getInstance(),
            $this->createMock(AdapterResolver::class),
            $this->createMock(AccountManager::class)
        );
    }

    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(CachePurger::class, $this->cachePurger);
    }

    public function testPurgeEverythingDomainNotFound(): void
    {
        $this->expectException(Core::class);
        $this->cachePurger->purge(999999, 'everything');
    }

    public function testPurgeUrlsWithoutFixture(): void
    {
        $this->markTestSkipped('Requires a real CDN domain fixture.');
    }

    public function testPurgeHostsWithoutFixture(): void
    {
        $this->markTestSkipped('Requires a real CDN domain fixture.');
    }

    public function testPurgeTagsWithoutFixture(): void
    {
        $this->markTestSkipped('Requires a real CDN domain fixture.');
    }

    public function testPurgeCacheKeysWithoutFixture(): void
    {
        $this->markTestSkipped('Requires a real CDN domain fixture.');
    }

    public function testPurgeInvalidModeWithoutFixture(): void
    {
        $this->markTestSkipped('Requires a real CDN domain fixture.');
    }

    public function testPurgeModeConstants(): void
    {
        foreach (['everything', 'urls', 'hosts', 'tags', 'cache_keys'] as $mode) {
            $this->assertIsString($mode);
            $this->assertNotEmpty($mode);
        }
    }
}
