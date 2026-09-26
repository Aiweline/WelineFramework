<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeLayoutVersionBindingResolver;
use Weline\Theme\Service\SharedChromeService;

final class ThemeLayoutVersionBindingResolverTest extends TestCase
{
    private function resolver(): ThemeLayoutVersionBindingResolver
    {
        return new ThemeLayoutVersionBindingResolver(
            (new ReflectionClass(SharedChromeService::class))->newInstanceWithoutConstructor(),
        );
    }

    public function testMatchChromeOmissionsDoesNotGuessByProjection(): void
    {
        $old = ['node_uid' => 'a', 'area' => 'footer', 'widget_module' => 'Weline_Test', 'widget_code' => 'footer', 'config' => ['title' => 'old']];
        $new = array_replace($old, ['config' => ['title' => 'new']]);
        $versions = [
            ['version_id' => 10, 'snapshot_data' => ['footer' => ['widgets' => [$old]]]],
            ['version_id' => 20, 'is_published' => true, 'snapshot_data' => ['footer' => ['widgets' => [$new]]]],
        ];
        $decisions = [['source' => 'user_deleted@10', 'slot_id' => 'footer-links', 'widget_module' => 'Weline_Test', 'widget_code' => 'links']];
        $result = $this->resolver()->matchChromeOmissions(['a' => $old], $versions, $decisions);
        self::assertFalse($result['resolved']);
        self::assertSame(ThemeLayoutVersionBindingResolver::REASON_PROJECTION_MATCHING_REMOVED, $result['reason']);
        self::assertSame([], $result['version_ids']);
        self::assertSame([], $result['omissions']);
    }

    public function testResolvePageVersionAndChromeOmissionsRefuseProjection(): void
    {
        $node = ['node_uid' => 'a', 'area' => 'footer', 'widget_code' => 'footer'];
        $page = $this->resolver()->resolvePageVersion(1, 'default', 'homepage', 'default', 'frontend', [$node]);
        self::assertFalse($page['resolved']);
        self::assertSame(ThemeLayoutVersionBindingResolver::REASON_PROJECTION_MATCHING_REMOVED, $page['reason']);

        $chrome = $this->resolver()->resolveChromeOmissions(1, 'default', [$node]);
        self::assertFalse($chrome['resolved']);
        self::assertSame(ThemeLayoutVersionBindingResolver::REASON_PROJECTION_MATCHING_REMOVED, $chrome['reason']);
    }
}
