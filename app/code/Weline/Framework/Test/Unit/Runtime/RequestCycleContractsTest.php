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

    public function testFpmPreflightIsGatedByReleaseStampSoCliCanCompile(): void
    {
        $source = $this->source('Runtime/FpmRuntime.php');
        $gatePos = \strpos($source, "if (\\defined('WELINE_BUILD_ID')) {");
        $preflightPos = \strpos($source, 'ContainerRuntime::preflight();');
        self::assertNotFalse($gatePos, '无发布戳的 CLI（全新安装首次 setup:upgrade）不得被严格 preflight 拦死');
        self::assertNotFalse($preflightPos);
        self::assertLessThan($preflightPos, $gatePos);
        self::assertSame(1, \substr_count($source, 'ContainerRuntime::preflight();'));
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

    /**
     * http worker 发布任务排水契约（T5.0b，2026-11-06）。
     *
     * worker.php 唯一常规 drain 点在 sendResponseAndCleanup，此时本请求仍被
     * 计入 $activeRequests（递减在函数返回后），busy 闸会把当前请求算作待办
     * 而自我阻塞 → 发布任务只能等下一个请求顺带 drain，低流量站点表现为
     * FPC 永远 MISS。两条契约：
     *  1. 响应后 drain 必须自排除（传 $activeRequests - 1）；
     *  2. 主循环必须有空闲 drain（无 Fiber、无活跃请求、非排水退出时），
     *     兜住 keepalive 空闲期队列滞留（镜像 worker_ssl.php 同款守卫）。
     */
    public function testHttpWorkerDrainsPostResponseTasksWithoutSelfBlocking(): void
    {
        $source = $this->source('Server/bin/worker.php');
        self::assertStringContainsString(
            'wlsDrainPostResponseTasks($activeRequests - 1, $requestBuffers, $writeBuffers, $connId)',
            $source,
            '响应后 drain 必须自排除当前请求，否则 busy 闸自我阻塞（T5.0b）'
        );
        self::assertMatchesRegularExpression(
            '/wlsHttpFlushQueuedWrites\([\s\S]{0,800}?wlsDrainPostResponseTasks\(\$activeRequests, \$requestBuffers, \$writeBuffers, null\);/',
            $source,
            'http worker 主循环需要空闲 drain，兜住 keepalive 空闲期发布任务滞留（T5.0b）'
        );
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

    public function testGenerationSelfHealIsExplicitOnly(): void
    {
        $cli = $this->source('Console/Cli.php');
        self::assertStringNotContainsString('repairStaleGenerationIfNeeded', $cli, 'CLI 不得在任何命令执行前自动编译产物');
        self::assertStringNotContainsString('GenerationRepairService', $cli);
        self::assertStringNotContainsString('--skip-generation-repair', $cli);
        $compile = $this->source('Console/Console/Framework/Compile.php');
        self::assertStringContainsString('--ensure', $compile);
        $start = $this->source('Server/Console/Server/Start.php');
        self::assertStringNotContainsString('$compiler->compile(', $start, 'WLS 启动不得发布编译产物');
        self::assertStringContainsString('requirePublishedFrameworkRuntimeRegistries', $start);
        self::assertStringContainsString('php bin/w framework:compile', $start);
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
