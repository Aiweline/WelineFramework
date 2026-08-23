<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Framework/Common/functions.php';

final class SlotRendererBackendLayoutIdentityTest extends TestCase
{
    private mixed $originalNormalizer = null;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::resetWelineVars();
        $this->originalNormalizer = ObjectManager::_getInstance(ThemeLayoutScopeNormalizer::class);

        $hierarchy = $this->createMock(ScopeHierarchyInterface::class);
        $hierarchy->method('assertWritableRawScope')
            ->willThrowException(new \InvalidArgumentException('short scope'));
        $hierarchy->method('toStorageScope')
            ->willReturnCallback(static function (ScopeIdentity $identity): string {
                return $identity->websiteCode . '.' . $identity->storeCode . '.' . $identity->channelCode;
            });
        ObjectManager::setInstance(
            ThemeLayoutScopeNormalizer::class,
            new ThemeLayoutScopeNormalizer($hierarchy)
        );
    }

    protected function tearDown(): void
    {
        RequestContext::resetWelineVars();
        if ($this->originalNormalizer !== null) {
            ObjectManager::setInstance(ThemeLayoutScopeNormalizer::class, $this->originalNormalizer);
        } else {
            ObjectManager::removeInstance(ThemeLayoutScopeNormalizer::class);
        }
        parent::tearDown();
    }

    public function testOrdinaryBackendLayoutIdentityUsesDefaultScopeWithoutFrozenIdentity(): void
    {
        self::assertNull(RequestContext::scopeIdentity());

        $service = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'currentLayoutIdentity');
        $method->setAccessible(true);

        $identity = $method->invoke($service, 'backend');

        self::assertSame('default', $identity['layout_option']);
        self::assertSame('default.default.default', $identity['scope']);
        self::assertSame('global', $identity['target_type']);
        self::assertSame(0, $identity['target_id']);
        self::assertNull(RequestContext::scopeIdentity());
    }

    public function testFrontendLayoutIdentityStillRequiresFrozenScopeIdentity(): void
    {
        self::assertNull(RequestContext::scopeIdentity());

        $service = (new \ReflectionClass(SlotRendererService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'currentLayoutIdentity');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Theme 布局渲染缺少冻结的 ScopeIdentity。');
        $method->invoke($service, 'frontend');
    }
}
