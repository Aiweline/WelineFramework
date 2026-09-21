<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Changed;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Extends\Module\Weline_Framework\Changed\Capability\CdnCapability;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\CachePurger;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Manager\ObjectManager;

/** CDN Capability：默认站 site_id=0 隔离。 */
final class CdnCapabilityZeroSiteTest extends TestCase
{
    public function testCapabilityClassExistsAndSupportsCdnPurge(): void
    {
        self::assertTrue(class_exists(CdnCapability::class));
        $cap = new CdnCapability();
        self::assertSame('cdn', $cap->code());
        self::assertContains(InvalidationEffect::CODE_CDN_PURGE, $cap->supportedEffects());
    }

    public function testOldObserverHardDeleted(): void
    {
        $path = dirname(__DIR__, 4) . '/Observer/ResourceChanged.php';
        self::assertFileDoesNotExist($path);
    }
}
