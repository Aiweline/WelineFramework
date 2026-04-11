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
        'app/code/Weline/Framework/Runtime/RequestContext.php',
        'app/code/Weline/Framework/Runtime/TelemetryBroadcaster.php',
        'app/code/Weline/Framework/Runtime/WlsFiberContext.php',
        'app/code/Weline/Framework/Runtime/WlsRuntime.php',
        'app/code/Weline/Framework/Router/Core.php',
        'app/code/Weline/Framework/System/File/Uploader.php',
        'app/code/Weline/Framework/View/Taglib/Generator/CodeGenerator.php',
        'app/code/Weline/Framework/event.php',
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
