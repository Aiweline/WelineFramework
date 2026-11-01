<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Project-wide superglobal usage guard.
 *
 * Rule:
 * - Only explicit framework "bridge/global" entry files are allowed to touch PHP superglobals directly.
 * - All other app/code code should use the unified access layer (e.g. w_env_* helpers / Request abstraction).
 */
final class ProjectSuperglobalUsageGuardTest extends TestCase
{
    /**
     * Keep this list small and explicit.
     *
     * These files are the current entry/bridge files that legitimately touch PHP superglobals.
     * When new violations appear, prefer fixing the code rather than expanding this allowlist.
     */
    private const ALLOWLIST = [
        'app/code/Weline/Framework/App.php',
        'app/code/Weline/Framework/Common/functions.php',
        'app/code/Weline/Framework/Context.php',
        'app/code/Weline/Framework/Cache/KeyBuilder.php',
        'app/code/Weline/Framework/Env/WelineEnv.php',
        'app/code/Weline/Framework/Api/Observer/Maintenance.php',
        'app/code/Weline/Framework/Http/Cookie.php',
        'app/code/Weline/Framework/Http/Response.php',
        'app/code/Weline/Framework/Http/Url.php',
        'app/code/Weline/Framework/Http/RequestUri.php',
        'app/code/Weline/Framework/Http/WlsRequest.php',
        'app/code/Weline/Framework/Http/Request/FileBag.php',
        'app/code/Weline/Framework/Http/Request/ParameterBag.php',
        'app/code/Weline/Framework/Http/Request/RequestAbstract.php',
        'app/code/Weline/Framework/Http/Request/RequestFilter.php',
        'app/code/Weline/Framework/Exception/ExceptionBootstrap.php',
        'app/code/Weline/Framework/Module/Console/Model/Rebuild.php',
        'app/code/Weline/Framework/Output/Cli/AbstractPrint.php',
        'app/code/Weline/Framework/Runtime/FpmRuntime.php',
        'app/code/Weline/Framework/Runtime/GlobalsEmulator.php',
        'app/code/Weline/Framework/Runtime/RequestLifecycleTrace.php',
        'app/code/Weline/Framework/Runtime/RequestContext.php',
        'app/code/Weline/Framework/Runtime/TelemetryBroadcaster.php',
        'app/code/Weline/Framework/Runtime/WlsFiberContext.php',
        'app/code/Weline/Server/Runtime/WlsRuntime.php',
        'app/code/Weline/Framework/Router/Core.php',
        'app/code/Weline/Framework/System/File/Uploader.php',
        'app/code/Weline/Framework/View/Taglib/Generator/CodeGenerator.php',
        'app/code/Weline/Framework/event.php',

        // —— 与 Framework 侧守卫同一批豁免（理由见 SuperglobalUsageGuardTest）——
        // 进程 / CLI 入口：读的是进程环境，不是请求态。
        'app/code/Weline/Framework/Runtime/Runtime.php',
        'app/code/Weline/Framework/Runtime/Resumable/Runner/spawn.php',
        'app/code/Weline/Framework/System/Process/Processer.php',
        'app/code/Weline/Framework/System/Process/bin/posix_detached_spawn.php',
        'app/code/Weline/Framework/Console/PhpCliRuntimePreflight.php',
        'app/code/Weline/Framework/View/TemplateCompileService.php',
        'app/code/Weline/Framework/Deploy/DeployStagingSession.php',
        'app/code/Weline/Framework/Deploy/HostProcessPoolPolicy.php',
        'app/code/Weline/Framework/Database/Migration/Service/MigrationCloneService.php',
        // 同请求会写 $_SERVER / $_COOKIE，Context 袋不会跟着变。
        'app/code/Weline/Framework/Runtime/PostResponseTaskQueue.php',
        'app/code/Weline/Framework/Runtime/PanelTraceCookie.php',
        // 诊断 / 失效签名必须同时比较 Context 与超全局。
        'app/code/Weline/Framework/Runtime/InternalHomepagePrime.php',
        'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php',

        // —— WLS 进程入口 / 运行时：读写的都是进程级环境（argv、WLS_* 角色标记、OPENSSL_CONF），
        //    不是请求态；这些值由 Master/Worker 启动时注入，请求 Context 不承载。——
        'app/code/Weline/Server/bin/worker.php',
        'app/code/Weline/Server/bin/worker_ssl.php',
        'app/code/Weline/Server/bin/worker_ssl_event.php',
        'app/code/Weline/Server/bin/dispatcher.php',
        'app/code/Weline/Server/bin/windows_start_process_working_directory.php',
        'app/code/Weline/Server/Security/WorkerPolicyKernel.php',
        'app/code/Weline/Server/Service/MasterLeaseRuntimeIdentity.php',
        'app/code/Weline/Server/Service/Runtime/TlsProcessProfileConfigurator.php',
        'app/code/Weline/Server/Console/Server/Start.php',

        // CLI 进程标记：记录 argv 供运维定位卡死进程，不是请求态数据。
        'app/code/Weline/Maintenance/Helper/MaintenanceSessionMarker.php',

        // phar 独立包 Web 入口：不加载框架（无 WelineEnv/RequestUri 可用），只能直读超全局。
        'app/code/Weline/Async/phar/web/api.php',
    ];

    public function testAppCodeDoesNotAddNewDirectSuperglobalUsageOutsideAllowlist(): void
    {
        $root = \dirname(__DIR__, 7);
        $codeRoot = $root . '/app/code';
        if (!\is_dir($codeRoot)) {
            self::markTestSkipped('Missing app/code directory');
        }

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($codeRoot));

        $violations = [];
        foreach ($rii as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = \str_replace('\\', '/', $file->getPathname());
            $ext = \strtolower($file->getExtension());
            if (!\in_array($ext, ['php', 'phtml'], true)) {
                continue;
            }

            // Skip tests.
            if (\str_contains($path, '/Test/') || \str_contains($path, '/test/') || \str_contains($path, '/UnitTest/')
                || \str_contains($path, '/tests/')
            ) {
                continue;
            }

            // Skip static/vendor code: elFinder, editor bundles, etc.
            if (\str_contains($path, '/view/statics/')) {
                continue;
            }

            // Skip build output: `view/tpl/` 是编译模板缓存（模块 .gitignore 已忽略），
            // 扫它只会把编译副本重复计成违规。
            if (\str_contains($path, '/view/tpl/')) {
                continue;
            }

            $relativePath = \str_replace('\\', '/', \substr($path, \strlen(\str_replace('\\', '/', $root)) + 1));
            if (\in_array($relativePath, self::ALLOWLIST, true)) {
                continue;
            }

            $contents = \file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if ($this->containsSuperglobalToken($contents)) {
                $violations[] = $relativePath;
            }
        }

        self::assertSame(
            [],
            $violations,
            "New direct superglobal usage is only allowed in explicit entry/bridge files.\n" . \implode("\n", $violations)
        );
    }

    private function containsSuperglobalToken(string $contents): bool
    {
        $tokens = @\token_get_all($contents);
        if (!\is_array($tokens)) {
            return false;
        }

        $superglobals = ['$_GET', '$_POST', '$_SERVER', '$_COOKIE', '$_FILES', '$_REQUEST'];

        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                continue;
            }

            if ($token[0] === T_VARIABLE && \in_array($token[1], $superglobals, true)) {
                return true;
            }
        }

        return false;
    }
}

