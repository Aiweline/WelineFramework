<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\MasterProcess;
use Weline\Server\Service\ServiceOrchestrator;
use Weline\Server\Session\Server\SessionServer;

final class ExplicitWallClockBoundaryTest extends TestCase
{
    /**
     * @dataProvider wallClockBoundaryProvider
     *
     * @param class-string $class
     */
    public function testPersistentAndDiagnosticWallTimeUsesAnExplicitWallClockBoundary(string $class): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

        self::assertStringNotContainsString('\\microtime(true)', $source, $class);
        self::assertStringContainsString("new \\DateTimeImmutable('now')", $source, $class);
    }

    /** @return iterable<string,array{class-string}> */
    public static function wallClockBoundaryProvider(): iterable
    {
        yield 'start command diagnostics' => [Start::class];
        yield 'master process diagnostics' => [MasterProcess::class];
        yield 'orchestrator projections' => [ServiceOrchestrator::class];
        yield 'session audit projections' => [SessionServer::class];
    }

    /**
     * @dataProvider processElapsedProvider
     *
     * @param class-string $class
     */
    public function testRequestWallClockSkewCannotProduceNegativeProcessElapsedTime(string $class): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass($class))->getFileName());

        self::assertStringContainsString(
            'max(0.0, $wallNow - $requestStartedAt)',
            $source,
            $class,
        );
    }

    /** @return iterable<string,array{class-string}> */
    public static function processElapsedProvider(): iterable
    {
        yield 'start command' => [Start::class];
        yield 'master process' => [MasterProcess::class];
    }
}
