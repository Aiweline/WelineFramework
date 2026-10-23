<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * 请求周期架构契约：把已经修过的「构建期信任 / 请求期无 stat / 并发边界」钉死，
 * 防止后续改动悄悄把旧路径加回来。
 */
final class RequestCycleContractsTest extends TestCase
{
    private function source(string $relative): string
    {
        // 向上找：既支持 app/ 根（bootstrap.php、autoload.php），也支持 Framework/ 根。
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            $dir = \dirname($dir);
            foreach ([$dir . '/' . $relative, $dir . '/Framework/' . $relative] as $candidate) {
                if (\is_file($candidate)) {
                    return (string)\file_get_contents($candidate);
                }
            }
        }

        self::fail('source not found: ' . $relative);
    }

    public function testDiDoesNotHashSourcesOnRequestPath(): void
    {
        $source = $this->source('Manager/ObjectManager.php');
        self::assertStringNotContainsString('hash_file(', $source, 'DI 热路径不得逐类 hash_file');
        self::assertStringContainsString("ReleaseStamp::hasArtifact('compiled_factories.php')", $source);
        self::assertStringContainsString('getSourceSignatureCallCount', $source);
    }

    public function testReleaseStampLoadsBeforeExceptionBootstrap(): void
    {
        $source = $this->source('bootstrap.php');
        $stampPos = \strpos($source, 'ReleaseStamp::isValid(');
        $exceptionPos = \strpos($source, 'ExceptionBootstrap::init(');
        self::assertNotFalse($stampPos);
        self::assertNotFalse($exceptionPos);
        self::assertLessThan($exceptionPos, $stampPos, '发布戳必须先于任何 DI 解析');
        self::assertStringContainsString('WELINE_BUILD_ID', $source);
    }

    public function testGenerationValidationRequiresReleaseStamp(): void
    {
        $source = $this->source('Compilation/FrameworkCompileManifest.php');
        self::assertStringContainsString('ReleaseStamp::generationMatches(', $source);
    }

    public function testEventRegistryTrustsStampAndKeepsNoRequestToken(): void
    {
        $source = $this->source('Event/EventRegistry.php');
        self::assertStringNotContainsString('registryValidatedRequestId', $source);
        self::assertStringContainsString('canTrustRegistryStamp', $source);
        self::assertStringContainsString('getRegistryStatCallCount', $source);
    }

    public function testTaglibCallbacksDoNotCaptureTemplate(): void
    {
        $source = $this->source('View/Taglib.php');
        self::assertStringNotContainsString('use ($template)', $source);
    }

    public function testUrlGuardKeepsOnlyLastMatch(): void
    {
        $source = $this->source('Router/UrlGuard/BoundedUrlGuard.php');
        self::assertStringNotContainsString('cachedMatches', $source);
        self::assertStringContainsString('lastMatchResult', $source);
    }

    public function testPostResponseQueueIsBounded(): void
    {
        $source = $this->source('Runtime/PostResponseTaskQueue.php');
        self::assertStringContainsString('maxPendingTasks(', $source);
        self::assertStringContainsString('droppedTasks', $source);
    }

    public function testFpmSessionWritesCookieParamsBeforeSessionStart(): void
    {
        $source = $this->source('Session/Strategy/FpmStrategy.php');
        $paramsPos = \strpos($source, 'session_set_cookie_params(');
        $startPos = \strpos($source, '\\session_start();', $paramsPos === false ? 0 : $paramsPos);
        self::assertNotFalse($paramsPos);
        self::assertNotFalse($startPos);
        self::assertLessThan($startPos, $paramsPos);
    }

    public function testSessionReassertIsGuardedByWasStarted(): void
    {
        $source = $this->source('App.php');
        self::assertStringContainsString('$wasStarted', $source);
    }

    public function testAutoloadFiltersClassmapByModuleRoot(): void
    {
        $source = $this->source('autoload.php');
        self::assertStringContainsString('weline_filter_local_classmap', $source);
        self::assertStringNotContainsString('!\\is_string($path) || !\\is_file($path)', $source);
    }

    public function testAutoloadTrustsStampedClassmap(): void
    {
        $source = $this->source('autoload.php');
        self::assertStringContainsString('WELINE_CLASSMAP_TRUSTED', $source);
        self::assertStringContainsString("runtime_artifacts']['classmap.php']", $source);
    }

    public function testFpcFingerprintMemoLivesOnRequestContext(): void
    {
        $source = $this->source('Cache/StorefrontCacheKeyContextResolver.php');
        self::assertStringContainsString('FINGERPRINT_MEMO_KEY', $source);
        self::assertStringContainsString('RequestContext::isInitialized()', $source);
    }

    public function testGenerationSelfHealsOnControlPlane(): void
    {
        $cli = $this->source('Console/Cli.php');
        self::assertStringContainsString('repairStaleGenerationIfNeeded', $cli);
        self::assertStringContainsString('GenerationRepairService', $cli);
        $compile = $this->source('Console/Console/Framework/Compile.php');
        self::assertStringContainsString('--ensure', $compile);
    }

    public function testScopeGuardExistsForSharedFiles(): void
    {
        $guard = $this->source('dev/tools/scope-guard.php');
        self::assertStringContainsString('CLEAN', $guard);
        self::assertStringContainsString('CONFLICT', $guard);
    }

    public function testRequestPathHasNoDebugStderrWrites(): void
    {
        foreach ([
            'Cache/StorefrontCacheKeyContextResolver.php',
            'Cache/Namespace/NamespaceGenerationSnapshot.php',
            'Router/FullPageCacheCoordinator.php',
        ] as $relative) {
            self::assertStringNotContainsString('fwrite(STDERR', $this->source($relative), $relative);
        }
    }
}
