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
        RequestContext::set('acl.enabled_menu_sources.v1', [['source_id' => 'old']]);
        ResourceTreeService::invalidateBackendMenuTreeCache();
        $generation = \w_cache('acl')->get('backend_menu_tree_generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.' . $generation, [['source_id' => 'new']]);
        self::assertSame([['source_id' => 'new']], $this->sources());
    }

    public function testObservedGenerationChangeDiscardsCurrentRequestMenuSource(): void
    {
        \w_cache('acl')->set('backend_menu_tree_generation', 'old-generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.old-generation', [['source_id' => 'old']]);
        self::assertSame([['source_id' => 'old']], $this->sources());
        \w_cache('acl')->set('backend_menu_tree_generation', 'new-generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.new-generation', [['source_id' => 'new']]);
        // Public role-tree reads synchronize before loading source definitions.
        (new \ReflectionMethod(ResourceTreeService::class, 'syncMenuTreeGeneration'))->invoke(null);
        self::assertSame([['source_id' => 'new']], $this->sources());
    }

    public function testProcessResetAlsoDropsCurrentRequestSnapshot(): void
    {
        RequestContext::set('acl.enabled_menu_sources.v1', [['source_id' => 'old']]);
        ResourceTreeService::clearProcessCache();
        self::assertFalse(RequestContext::has('acl.enabled_menu_sources.v1'));
    }

    public function testAnotherRequestAdvancingProcessGenerationCannotReviveOlderSnapshot(): void
    {
        $first = Context::getCurrent();
        \w_cache('acl')->set('backend_menu_tree_generation', 'old-generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.old-generation', [['source_id' => 'old']]);
        self::assertSame([['source_id' => 'old']], $this->sources());

        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        \w_cache('acl')->set('backend_menu_tree_generation', 'new-generation');
        \w_cache('acl')->set('acl.enabled_menu_sources.new-generation', [['source_id' => 'new']]);
        self::assertSame([['source_id' => 'new']], $this->sources());

        Context::enter($first);
        (new \ReflectionMethod(ResourceTreeService::class, 'syncMenuTreeGeneration'))->invoke(null);
        self::assertSame([['source_id' => 'new']], $this->sources());
    }

    private function sources(): array
    {
        return (new \ReflectionMethod(ResourceTreeService::class, 'loadEnabledMenuSources'))->invoke(new ResourceTreeService());
    }
}
