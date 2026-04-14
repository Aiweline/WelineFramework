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
        'app/code/Weline/Framework/Http/WlsRequest.php',
        'app/code/Weline/Framework/Http/Request/FileBag.php',
        'app/code/Weline/Framework/Http/Request/ParameterBag.php',
        'app/code/Weline/Framework/Http/Request/RequestAbstract.php',
        'app/code/Weline/Framework/Http/Request/RequestFilter.php',
        'app/code/Weline/Framework/Http/Request/ServerBag.php',
        'app/code/Weline/Framework/Exception/ExceptionBootstrap.php',
        'app/code/Weline/Framework/Module/Console/Model/Rebuild.php',
        'app/code/Weline/Framework/Output/Cli/AbstractPrint.php',
        'app/code/Weline/Framework/Runtime/FpmRuntime.php',
        'app/code/Weline/Framework/Runtime/GlobalsEmulator.php',
        'app/code/Weline/Framework/Runtime/RequestLifecycleTrace.php',
        'app/code/Weline/Framework/Runtime/RequestContext.php',
        'app/code/Weline/Framework/Runtime/TelemetryBroadcaster.php',
        'app/code/Weline/Framework/Runtime/WlsFiberContext.php',
        'app/code/Weline/Framework/Runtime/WlsRuntime.php',
        'app/code/Weline/Framework/Router/Core.php',
        'app/code/Weline/Framework/System/File/Uploader.php',
        'app/code/Weline/Framework/View/Taglib/Generator/CodeGenerator.php',
        'app/code/Weline/Framework/event.php',
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
            if (\str_contains($path, '/Test/') || \str_contains($path, '/test/') || \str_contains($path, '/UnitTest/')) {
                continue;
            }

            // Skip static/vendor code: elFinder, editor bundles, etc.
            if (\str_contains($path, '/view/statics/')) {
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

