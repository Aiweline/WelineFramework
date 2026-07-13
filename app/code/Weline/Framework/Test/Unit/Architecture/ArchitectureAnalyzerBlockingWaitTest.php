<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Architecture\ArchitectureAnalyzer;

final class ArchitectureAnalyzerBlockingWaitTest extends TestCase
{
    #[DataProvider('nonRequestWaitProvider')]
    public function testAllowsNativeWaitOnlyInsideExplicitNonRequestImplementations(string $file): void
    {
        self::assertSame([], $this->blockingCalls($file));
    }

    public static function nonRequestWaitProvider(): array
    {
        return [
            'scheduler primitive' => ['/workspace/app/code/Weline/Framework/Runtime/SchedulerSystem.php'],
            'master cleanup bootstrap' => ['/workspace/app/code/Weline/Server/Service/MasterCleanupBootstrap.php'],
        ];
    }

    public function testReportsNativeWaitInRequestReachableService(): void
    {
        self::assertSame(
            [['usleep', 3]],
            $this->blockingCalls('/workspace/app/code/Weline/Foo/Service/RequestService.php'),
        );
    }

    private function blockingCalls(string $file): array
    {
        $method = new ReflectionMethod(ArchitectureAnalyzer::class, 'blockingCalls');
        $method->setAccessible(true);

        return $method->invoke(
            new ArchitectureAnalyzer(),
            "<?php\nnamespace Weline\\Foo\\Service;\nusleep(1000);\n",
            $file,
        );
    }
}
