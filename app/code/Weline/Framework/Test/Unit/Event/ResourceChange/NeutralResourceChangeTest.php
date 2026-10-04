<?php
declare(strict_types=1);
namespace Weline\Framework\Test\Unit\Event\ResourceChange;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Async\ContextSnapshot;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;

final class NeutralResourceChangeTest extends TestCase
{
    public function testNeutralChangeKeepsNamespaceImpactWithoutWebsiteZero(): void
    {
        $factory = new ResourceChangeFactory(new ContextSnapshot());
        $change = $factory->create('theme_layout', '3', 'publish', 4, null, null, [], ['revision'=>4],
            ['revision'], ['namespaces'=>['owner/portal/main/theme'], 'urls'=>[]], resourceScope:'!external!portal.main', siteId:null);
        self::assertFalse($change->hasWebsiteContext());
        self::assertNull($change->websiteId());
        self::assertNull($change->websiteCode());
        self::assertNull($change->toArray()['website']);
        self::assertSame(['owner/portal/main/theme'], $change->toArray()['impact']['namespaces']);
        self::assertSame('!external!portal.main', $change->resourceScope());
        self::assertNull($change->toArray()['context']['website_id']);
        (new ContextSnapshot())->validate($change->toArray()['context']);
    }

    public function testWebsiteZeroRemainsARealExplicitWebsiteContext(): void
    {
        $change = (new ResourceChangeFactory(new ContextSnapshot()))->create('theme_layout', '3', 'publish', 4,
            0, 'default', [], ['revision'=>4], [], ['namespaces'=>[], 'urls'=>[]]);
        self::assertTrue($change->hasWebsiteContext());
        self::assertSame(0, $change->websiteId());
        self::assertSame('default', $change->websiteCode());
    }
}
