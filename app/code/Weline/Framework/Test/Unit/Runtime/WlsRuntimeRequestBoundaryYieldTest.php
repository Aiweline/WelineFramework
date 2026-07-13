<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Controller\PcController;
use Weline\Framework\Router\Core as RouterCore;
use Weline\Framework\Runtime\WlsRuntime;
use Weline\Framework\View\Template;

final class WlsRuntimeRequestBoundaryYieldTest extends TestCase
{
    public function testHandleDoesNotCooperativelyYieldBeforeResponseReturn(): void
    {
        $this->assertMethodDoesNotYield(
            WlsRuntime::class,
            'handle',
            'A normal WLS request must not yield at framework phase boundaries before its response is returned.'
        );
    }

    public function testRouterDispatchDoesNotCooperativelyYieldBeforeResponseReturn(): void
    {
        $this->assertMethodDoesNotYield(
            RouterCore::class,
            'start',
            'Router::start() is part of the normal HTTP response path and must not requeue the request fiber.'
        );
        $this->assertMethodDoesNotYield(
            RouterCore::class,
            'route',
            'Router::route() is part of the normal HTTP response path and must not requeue the request fiber.'
        );
    }

    public function testTemplateRenderingDoesNotCooperativelyYieldBeforeResponseReturn(): void
    {
        $this->assertMethodDoesNotYield(
            PcController::class,
            'fetchTemplateWithEvents',
            'Template event rendering is part of normal HTML response assembly and must not requeue the request fiber.'
        );
    }

    public function testTemplateCooperativeYieldIsOptIn(): void
    {
        $method = new ReflectionMethod(Template::class, 'cooperativeTemplateYield');
        $file = $method->getFileName();

        self::assertIsString($file);

        $lines = \file($file);
        self::assertIsArray($lines);

        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString(
            'wls.performance.template_cooperative_yield_enabled',
            $source,
            'Template-level cooperative yield must remain opt-in for normal WLS requests.'
        );
    }

    public function testWorkerRequestFiberDoesNotYieldBeforeNormalRequestHandling(): void
    {
        foreach ($this->workerEntryFiles() as $file) {
            $source = \file_get_contents($file);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                '/wlsFiberRequestContextEnter\([^)]+\);\s*try\s*\{\s*\\\\Weline\\\\Framework\\\\Runtime\\\\SchedulerSystem::yield\(\);/s',
                $source,
                $file . ' must not suspend a new request fiber before normal request handling starts.'
            );
        }
    }

    public function testWorkerConnectionLoopCleansEofAndBatchesAccepts(): void
    {
        $worker = \file_get_contents($this->workerEntryFiles()[0]);
        $workerSsl = \file_get_contents($this->workerEntryFiles()[1]);

        self::assertIsString($worker);
        self::assertIsString($workerSsl);
        self::assertStringContainsString('int $maxAcceptPerLoop = 64,', $worker);
        self::assertStringContainsString('while ($accepted < $maxAcceptPerLoop)', $worker);
        self::assertStringContainsString('if (@\feof($conn))', $worker);
        self::assertStringContainsString('if (@\feof($conn))', $workerSsl);
    }

    private function assertMethodDoesNotYield(string $className, string $methodName, string $message): void
    {
        $method = new ReflectionMethod($className, $methodName);
        $file = $method->getFileName();

        self::assertIsString($file);

        $lines = \file($file);
        self::assertIsArray($lines);

        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringNotContainsString(
            'SchedulerSystem::yield()',
            $source,
            $message
        );
    }

    /**
     * @return string[]
     */
    private function workerEntryFiles(): array
    {
        $bp = \dirname(__DIR__, 7);

        return [
            $bp . '/app/code/Weline/Server/bin/worker.php',
            $bp . '/app/code/Weline/Server/bin/worker_ssl.php',
        ];
    }
}
