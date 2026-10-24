<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Service\ThemeStorefrontSlugFpcPublisher;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('VAR_DIR') || \define('VAR_DIR', BP . 'var' . \DIRECTORY_SEPARATOR);

/**
 * T3 验收：resolveWebsiteFace() 必须直接读 ScopeIdentity 的 websiteId / websiteCode。
 *
 * 历史缺陷：把 fromStorageScope() 返回的 ScopeIdentity 命名为 $scopeContext 后又读
 * $scopeContext->identity（该属性不存在），`?? null` 恒空 → websiteId / websiteCode /
 * origin 全丢，FPC 投影失效。
 *
 * 不触库、不发事件：只断言三元组的解析结果。origin 由本机安装探测决定（可能是空串），
 * 因此只断言它是 string，不断言具体值。
 */
final class ThemeStorefrontSlugFpcPublisherWebsiteFaceTest extends TestCase
{
    protected function setUp(): void
    {
        // 单测无 DI 上下文，注入真实纯函数 resolver（SystemConfigScopeResolver 无构造依赖）。
        // ObjectManager::setInstance() 第二参为引用，必须先落到变量。
        $resolver = new SystemConfigScopeResolver();
        ObjectManager::setInstance(ScopeHierarchyInterface::class, $resolver);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(ScopeHierarchyInterface::class);
    }

    private function publisher(): ThemeStorefrontSlugFpcPublisher
    {
        return (new \ReflectionClass(ThemeStorefrontSlugFpcPublisher::class))
            ->newInstanceWithoutConstructor();
    }

    private function contextFor(string $storageScope): ThemeEditorContext
    {
        $scope = ThemeContentScope::fromStoredOwner($storageScope, 'normal', 'default');

        return new ThemeEditorContext(
            scope: $scope,
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: 3,
            layoutType: 'default',
            layoutOption: 'default',
        );
    }

    /**
     * @return array{0:?int,1:?string,2:string}
     */
    private function face(string $storageScope): array
    {
        $method = new \ReflectionMethod(ThemeStorefrontSlugFpcPublisher::class, 'resolveWebsiteFace');
        $method->setAccessible(true);

        return $method->invoke($this->publisher(), $this->contextFor($storageScope));
    }

    public function testWebsiteScopeResolvesIdentityNotNull(): void
    {
        [$websiteId, $websiteCode, $origin] = $this->face('default.__website__.default');

        // 系统默认站 website_id=0 是合法值，必须放行（不能被 >0 过滤掉）。
        self::assertSame(0, $websiteId);
        self::assertSame('default', $websiteCode);
        self::assertIsString($origin);
    }

    public function testStoreScopeResolvesOwningWebsite(): void
    {
        [$websiteId, $websiteCode] = $this->face('default.__store__.default');

        self::assertSame(0, $websiteId);
        self::assertSame('default', $websiteCode);
    }

    public function testChannelScopeResolvesOwningWebsite(): void
    {
        [$websiteId, $websiteCode] = $this->face('default.__store__.__channel__');

        self::assertSame(0, $websiteId);
        self::assertSame('default', $websiteCode);
    }

    public function testGlobalScopeFailsClosed(): void
    {
        self::assertSame([null, null, ''], $this->face('default.default.default'));
    }

    public function testUnresolvableExternalScopeFailsClosed(): void
    {
        self::assertSame([null, null, ''], $this->face('!external!portal.root'));
    }

    public function testOriginFailureKeepsWebsiteIdentity(): void
    {
        // origin 探测在本机可能失败（无 origin）——websiteId / websiteCode 必须仍在。
        [$websiteId, $websiteCode, $origin] = $this->face('default.__website__.default');

        self::assertIsString($origin);
        self::assertSame(0, $websiteId);
        self::assertSame('default', $websiteCode);
    }
}
