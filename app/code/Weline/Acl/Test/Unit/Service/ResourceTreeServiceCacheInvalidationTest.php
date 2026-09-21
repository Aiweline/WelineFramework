<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Service\ResourceTreeService;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;

final class ResourceTreeServiceCacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        \w_cache('acl')->clear();
        ResourceTreeService::clearProcessCache();
    }

    protected function tearDown(): void
    {
        ResourceTreeService::clearProcessCache();
        Context::leave();
    }

    public function testLocalInvalidationDiscardsCurrentRequestMenuSource(): void
    {
        RequestContext::set('acl.enabled_menu_sources.v1', [$this->row('old')]);
        ResourceTreeService::invalidateBackendMenuTreeCache();
        $generation = \w_cache('acl')->get('backend_menu_tree_generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.' . $generation, [$this->row('new')]);
        self::assertSame([$this->row('new')], $this->sources());
    }

    public function testObservedGenerationChangeDiscardsCurrentRequestMenuSource(): void
    {
        \w_cache('acl')->set('backend_menu_tree_generation', 'old-generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.old-generation', [$this->row('old')]);
        self::assertSame([$this->row('old')], $this->sources());
        \w_cache('acl')->set('backend_menu_tree_generation', 'new-generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.new-generation', [$this->row('new')]);
        // Public role-tree reads synchronize before loading source definitions.
        (new \ReflectionMethod(ResourceTreeService::class, 'syncMenuTreeGeneration'))->invoke(null);
        self::assertSame([$this->row('new')], $this->sources());
    }

    public function testProcessResetAlsoDropsCurrentRequestSnapshot(): void
    {
        RequestContext::set('acl.enabled_menu_sources.v1', [$this->row('old')]);
        ResourceTreeService::clearProcessCache();
        self::assertFalse(RequestContext::has('acl.enabled_menu_sources.v1'));
    }

    public function testEnabledMenuSourcesCacheDropsResourceMetadata(): void
    {
        $generation = 'slim-menu-sources';
        \w_cache('acl')->set('backend_menu_tree_generation', $generation);
        \w_cache('acl')->set('acl.enabled_menu_sources.' . $generation, [[
            'source_id' => 'menu.a',
            'source_name' => 'A',
            'type' => 'menus',
            'icon' => 'i',
            'route' => '/a',
            'module' => 'Weline_Test',
            'order' => 1,
            'is_enable' => 1,
            'is_backend' => 1,
            'parent_source' => '',
            'resource_metadata' => \str_repeat('x', 1024 * 1024),
            'document' => 'should-drop',
            'class' => 'Fat\\Class',
        ]]);

        $rows = $this->sources();
        self::assertCount(1, $rows);
        self::assertSame('menu.a', $rows[0]['source_id']);
        self::assertArrayNotHasKey('resource_metadata', $rows[0]);
        self::assertArrayNotHasKey('document', $rows[0]);
        self::assertArrayNotHasKey('class', $rows[0]);
        self::assertSame('/a', $rows[0]['route']);

        $process = (new \ReflectionProperty(ResourceTreeService::class, 'enabledMenuSourcesCache'))->getValue();
        self::assertIsArray($process);
        self::assertArrayNotHasKey('resource_metadata', $process['data'][0]);
        self::assertLessThan(4096, \strlen(\serialize($process['data'])));

        $shared = \w_cache('acl')->get('acl.enabled_menu_sources.' . $generation);
        self::assertIsArray($shared);
        self::assertArrayNotHasKey('resource_metadata', $shared[0]);
    }

    public function testAnotherRequestAdvancingProcessGenerationCannotReviveOlderSnapshot(): void
    {
        $first = Context::getCurrent();
        \w_cache('acl')->set('backend_menu_tree_generation', 'old-generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.old-generation', [$this->row('old')]);
        self::assertSame([$this->row('old')], $this->sources());

        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        \w_cache('acl')->set('backend_menu_tree_generation', 'new-generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.new-generation', [$this->row('new')]);
        self::assertSame([$this->row('new')], $this->sources());

        Context::enter($first);
        (new \ReflectionMethod(ResourceTreeService::class, 'syncMenuTreeGeneration'))->invoke(null);
        self::assertSame([$this->row('new')], $this->sources());
    }

    /**
     * @return array{
     *   source_id: string,
     *   source_name: string,
     *   type: string,
     *   icon: string,
     *   route: string,
     *   module: string,
     *   order: int,
     *   is_enable: int,
     *   is_backend: int,
     *   parent_source: string
     * }
     */
    private function row(string $sourceId): array
    {
        return [
            'source_id' => $sourceId,
            'source_name' => '',
            'type' => '',
            'icon' => '',
            'route' => '',
            'module' => '',
            'order' => 0,
            'is_enable' => 0,
            'is_backend' => 0,
            'parent_source' => '',
        ];
    }

    private function sources(): array
    {
        return (new \ReflectionMethod(ResourceTreeService::class, 'loadEnabledMenuSources'))->invoke(new ResourceTreeService());
    }
}
