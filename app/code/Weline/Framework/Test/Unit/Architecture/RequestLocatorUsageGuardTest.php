<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class RequestLocatorUsageGuardTest extends TestCase
{
    /**
     * Transitional allowlist for request-path service locator usage while the
     * framework is still migrating toward explicit Context-driven dependencies.
     */
    private const ALLOWLIST = [
        'app/code/Weline/Framework/App/Controller/BackendController.php',
        'app/code/Weline/Framework/App/Controller/BackendRestController.php',
        'app/code/Weline/Framework/Controller/AbstractRestController.php',
        'app/code/Weline/Framework/Controller/Core.php',
        'app/code/Weline/Framework/Controller/PcController.php',
        'app/code/Weline/Framework/Http/Observer/ResultBridgeRedirect.php',
        'app/code/Weline/Framework/Http/Request.php',
        'app/code/Weline/Framework/Http/Request/RequestAbstract.php',
        'app/code/Weline/Framework/Http/Request/ServerBag.php',
        'app/code/Weline/Framework/Http/Response.php',
        'app/code/Weline/Framework/Http/Url.php',
        'app/code/Weline/Framework/Router/CacheManager.php',
        'app/code/Weline/Framework/Router/Core.php',
        'app/code/Weline/Framework/Router/Handle.php',
        'app/code/Weline/Framework/Router/Observer/CheckFullPageCache.php',
        'app/code/Weline/Framework/Router/Service/RouteUpdateService.php',
        'app/code/Weline/Framework/Router/UrlProcessor.php',
        'app/code/Weline/Framework/Runtime/SchedulerSystem.php',
        'app/code/Weline/Framework/Runtime/StateManager.php',
        'app/code/Weline/Framework/Runtime/TelemetryBroadcaster.php',
        'app/code/Weline/Framework/Runtime/WlsFiberContext.php',
        'app/code/Weline/Framework/Runtime/WlsRuntime.php',
    ];

    public function testCriticalRequestPathDoesNotAddNewServiceLocatorUsageOutsideAllowlist(): void
    {
        $root = \dirname(__DIR__, 7);
        $targets = [
            $root . '/app/code/Weline/Framework/App/Controller',
            $root . '/app/code/Weline/Framework/Controller',
            $root . '/app/code/Weline/Framework/Http',
            $root . '/app/code/Weline/Framework/Router',
            $root . '/app/code/Weline/Framework/Runtime',
        ];

        $violations = [];

        foreach ($targets as $target) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target));
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

                if (\preg_match('/\bObjectManager::getInstance\s*\(|\bw_obj\s*\(/', $contents) === 1) {
                    $violations[] = $relativePath;
                }
            }
        }

        self::assertSame([], $violations, "New request-path service locator usage must not appear outside the explicit migration allowlist.\n" . \implode("\n", $violations));
    }
}
