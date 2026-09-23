<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeLayoutVersionBindingResolver;
use Weline\Theme\Service\SharedChromeService;

final class ThemeLayoutVersionBindingResolverTest extends TestCase
{
    private function resolver(): ThemeLayoutVersionBindingResolver
    {
        return new ThemeLayoutVersionBindingResolver((new ReflectionClass(SharedChromeService::class))->newInstanceWithoutConstructor());
    }
    public function testHistoricalChromeUsesItsOwnOmissions(): void
    {
        $old = ['node_uid' => 'a', 'area' => 'footer', 'widget_module' => 'Weline_Test', 'widget_code' => 'footer', 'config' => ['title' => 'old']];
        $new = array_replace($old, ['config' => ['title' => 'new']]);
        $versions = [['version_id' => 10, 'snapshot_data' => ['footer' => ['widgets' => [$old]]]], ['version_id' => 20, 'is_published' => true, 'snapshot_data' => ['footer' => ['widgets' => [$new]]]]];
        $decisions = [['source' => 'user_deleted@10', 'slot_id' => 'footer-links', 'widget_module' => 'Weline_Test', 'widget_code' => 'links']];
        $result = $this->resolver()->matchChromeOmissions(['a' => $old], $versions, $decisions);
        self::assertTrue($result['resolved']);
        self::assertSame([10], $result['version_ids']);
        self::assertSame('links', $result['omissions'][0]['widget_code']);
    }
    public function testIdenticalChromeWithDifferentUninstallHistoryIsUnresolved(): void
    {
        $node = ['node_uid' => 'a', 'area' => 'footer', 'widget_code' => 'footer'];
        $snapshot = ['footer' => ['widgets' => [$node]]];
        $versions = [['version_id' => 10, 'snapshot_data' => $snapshot], ['version_id' => 20, 'snapshot_data' => $snapshot]];
        $decisions = [['source' => 'user_deleted@10', 'slot_id' => 'footer-links', 'widget_module' => 'Weline_Test', 'widget_code' => 'links']];
        self::assertFalse($this->resolver()->matchChromeOmissions(['a' => $node], $versions, $decisions)['resolved']);
        self::assertTrue($this->resolver()->matchChromeOmissions(['a' => $node], $versions, [])['resolved']);
    }
}
