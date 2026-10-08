<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Backend Query + FrontendRest/QueryBin shells live in owning modules;
 * Framework keeps thin @deprecated aliases; Router hardcodes owning FQCNs.
 */
final class QueryShellOwnershipContractTest extends TestCase
{
    public function testBackendQueryOwnedByBackendModule(): void
    {
        $home = dirname(__DIR__, 4) . '/Backend/Controller/Api/Query.php';
        self::assertFileExists($home);
        $src = (string)file_get_contents($home);
        self::assertStringContainsString('namespace Weline\\Backend\\Controller\\Api;', $src);
        self::assertStringContainsString('FrameworkQueryService', $src);

        $alias = dirname(__DIR__, 3) . '/Controller/Backend/Api/Query.php';
        self::assertFileExists($alias);
        $aliasSrc = (string)file_get_contents($alias);
        self::assertStringContainsString('@deprecated', $aliasSrc);
        self::assertStringContainsString('Weline\\Backend\\Controller\\Api\\Query', $aliasSrc);
    }

    public function testFrontendRestAndQueryShellsOwnedByFrontendModule(): void
    {
        $weline = dirname(__DIR__, 4);
        foreach ([
            '/Frontend/Controller/FrontendRestController.php',
            '/Frontend/Controller/Api/QueryBin.php',
            '/Frontend/Controller/Api/BinQuery.php',
            '/Frontend/Controller/Api/Stream.php',
        ] as $rel) {
            self::assertFileExists($weline . $rel, $rel);
        }

        $queryBin = (string)file_get_contents($weline . '/Frontend/Controller/Api/QueryBin.php');
        self::assertStringContainsString('namespace Weline\\Frontend\\Controller\\Api;', $queryBin);
        self::assertStringContainsString('SIGNED_PATH', $queryBin);

        foreach ([
            '/Framework/App/Controller/FrontendRestController.php',
            '/Framework/Controller/Api/QueryBin.php',
            '/Framework/Controller/Api/BinQuery.php',
            '/Framework/Controller/Api/Stream.php',
        ] as $rel) {
            $aliasSrc = (string)file_get_contents($weline . $rel);
            self::assertStringContainsString('@deprecated', $aliasSrc, $rel);
        }
    }

    public function testRouterHardcodesOwningModuleControllers(): void
    {
        $router = (string)file_get_contents(dirname(__DIR__, 3) . '/Router/Core.php');
        self::assertStringContainsString('Weline\\Backend\\Controller\\Api\\Query::class', $router);
        self::assertStringContainsString('Weline\\Frontend\\Controller\\Api\\QueryBin::class', $router);
        self::assertStringContainsString('Weline\\Frontend\\Controller\\Api\\BinQuery::class', $router);
        self::assertStringContainsString('Weline\\Frontend\\Controller\\Api\\Stream::class', $router);
        self::assertStringNotContainsString(
            'Weline\\Framework\\Controller\\Backend\\Api\\Query::class',
            $router
        );
        self::assertStringNotContainsString(
            'Weline\\Framework\\Controller\\Api\\QueryBin::class',
            $router
        );
    }
}
