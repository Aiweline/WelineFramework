<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\MediaManager\Service\ConnectorService;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Framework/Common/functions.php';
require_once BP . 'app/code/Weline/MediaManager/Service/ConnectorService.php';

final class ConnectorServiceAccessContextTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::resetWelineVars();
        self::assertNull(RequestContext::scopeIdentity());
    }

    public function testAuthenticatedBackendActorUsesGlobalScopeWithoutStorefrontIdentity(): void
    {
        $context = $this->invokeFileAccessContext(7);

        self::assertTrue($context->scope->isGlobal());
        self::assertSame(ScopeIdentity::global()->canonicalKey(), $context->scope->canonicalKey());
        self::assertSame(7, $context->actorId);
        self::assertSame('zh_Hans_CN', $context->localeCode);
        self::assertSame('media_manager', $context->purpose);
        self::assertNull(RequestContext::scopeIdentity());
    }

    public function testMissingBackendActorStillRejectsAbsentStorefrontIdentity(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('媒体文件访问缺少冻结的 ScopeIdentity。');

        $this->invokeFileAccessContext(null);
    }

    private function invokeFileAccessContext(?int $actorId): object
    {
        $assets = $this->createMock(FileAssetLibraryInterface::class);
        $assets->method('normalizeLocale')
            ->with('zh_Hans_CN')
            ->willReturn('zh_Hans_CN');

        $service = new ConnectorService();
        $assetsProperty = new \ReflectionProperty($service, 'assetLibrary');
        $assetsProperty->setValue($service, $assets);

        $method = new \ReflectionMethod($service, 'fileAccessContext');

        return $method->invoke($service, 'zh_Hans_CN', $actorId);
    }
}
