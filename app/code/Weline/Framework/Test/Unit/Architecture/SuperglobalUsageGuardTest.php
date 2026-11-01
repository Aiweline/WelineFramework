<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class SuperglobalUsageGuardTest extends TestCase
{
    /**
     * Keep this list small and explicit. These are the current entry/bridge
     * files that still legitimately touch superglobals during the migration.
     */
    private const ALLOWLIST = [
        'app/code/Weline/Framework/App.php',
        'app/code/Weline/Framework/Common/functions.php',
        'app/code/Weline/Framework/Context.php',
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
        'app/code/Weline/Framework/Runtime/RequestContext.php',
        'app/code/Weline/Framework/Runtime/TelemetryBroadcaster.php',
        'app/code/Weline/Framework/Runtime/WlsFiberContext.php',
        'app/code/Weline/Server/Runtime/WlsRuntime.php',
        'app/code/Weline/Framework/Router/Core.php',
        'app/code/Weline/Framework/System/File/Uploader.php',
        'app/code/Weline/Framework/View/Taglib/Generator/CodeGenerator.php',
        'app/code/Weline/Framework/event.php',

        // —— 进程 / CLI 入口：读的是进程环境，不是请求态 ——
        'app/code/Weline/Framework/Runtime/Runtime.php',
        'app/code/Weline/Framework/Runtime/Resumable/Runner/spawn.php',
        'app/code/Weline/Framework/System/Process/Processer.php',
        'app/code/Weline/Framework/System/Process/bin/posix_detached_spawn.php',
        'app/code/Weline/Framework/Console/PhpCliRuntimePreflight.php',
        'app/code/Weline/Framework/View/TemplateCompileService.php',
        'app/code/Weline/Framework/Deploy/DeployStagingSession.php',
        'app/code/Weline/Framework/Deploy/HostProcessPoolPolicy.php',
        'app/code/Weline/Framework/Database/Migration/Service/MigrationCloneService.php',

        // —— 同请求会写 $_SERVER / $_COOKIE，Context 袋不会跟着变（换读门面会读到旧值）——
        'app/code/Weline/Framework/Runtime/PostResponseTaskQueue.php',
        'app/code/Weline/Framework/Runtime/RequestLifecycleTrace.php',
        'app/code/Weline/Framework/Runtime/PanelTraceCookie.php',

        // —— 诊断 / 失效签名必须同时比较 Context 与超全局（防 under-invalidation）——
        'app/code/Weline/Framework/Runtime/InternalHomepagePrime.php',
        'app/code/Weline/Framework/Router/FullPageCacheCoordinator.php',
    ];

    public function testFrameworkProductionCodeDoesNotAddNewDirectSuperglobalUsageOutsideAllowlist(): void
    {
        $root = \dirname(__DIR__, 7);
        $frameworkRoot = $root . '/app/code/Weline/Framework';
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($frameworkRoot));

        $violations = [];

        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = \str_replace('\\', '/', $file->getPathname());
            if (\str_contains($path, '/Test/') || \str_contains($path, '/test/') || \str_contains($path, '/UnitTest/')) {
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

        self::assertSame([], $violations, "New direct superglobal usage is only allowed in explicit entry/bridge files.\n" . \implode("\n", $violations));
    }

    private function containsSuperglobalToken(string $contents): bool
    {
        $tokens = \token_get_all($contents);
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
