<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartRecoverableInstanceDetectionTest extends TestCase
{
    public function testIsServerRunningTreatsLiveWlsPortAsRunningWhenRuntimeFileIsMissing(): void
    {
        $start = $this->createStartDouble();
        $start->portOccupiedByWls = true;

        self::assertTrue($start->detect('default', 443));
    }

    public function testIsServerRunningTreatsRecoverableManagedProcessHintAsRunningWhenRuntimeFileIsMissing(): void
    {
        $start = $this->createStartDouble();
        $start->recoverableProcessHint = true;

        self::assertTrue($start->detect('default', 443));
    }

    public function testIsServerRunningFallsBackToRecoverableHintWhenRuntimeFileIsInvalid(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'wls-runtime-');
        self::assertNotFalse($file);
        \file_put_contents($file, '{invalid-json');
        $this->registerFileCleanup($file);

        $start = $this->createStartDouble($file);
        $start->recoverableProcessHint = true;

        self::assertTrue($start->detect('default', 443));
    }

    public function testIsServerRunningReturnsFalseWhenNoRuntimeSignalsExist(): void
    {
        $start = $this->createStartDouble();

        self::assertFalse($start->detect('default', 443));
    }

    private function createStartDouble(?string $runtimeFile = null): object
    {
        $missingFile = $runtimeFile;
        if ($missingFile === null) {
            $missingFile = \tempnam(\sys_get_temp_dir(), 'wls-runtime-missing-');
            self::assertNotFalse($missingFile);
            @\unlink($missingFile);
        }

        return new class($missingFile) extends Start {
            public bool $portOccupiedByWls = false;
            public bool $recoverableProcessHint = false;

            public function __construct(private readonly string $runtimeFile)
            {
            }

            public function detect(string $instanceName, int $port): bool
            {
                return $this->isServerRunning($instanceName, $port);
            }

            protected function getRuntimeInstanceFile(string $instanceName): string
            {
                unset($instanceName);

                return $this->runtimeFile;
            }

            protected function isPortOccupiedByWelineProcess(int $port): bool
            {
                unset($port);

                return $this->portOccupiedByWls;
            }

            protected function hasRecoverableManagedProcessHint(string $name): bool
            {
                unset($name);

                return $this->recoverableProcessHint;
            }
        };
    }

    private function registerFileCleanup(string $file): void
    {
        \register_shutdown_function(static function () use ($file): void {
            if (\is_file($file)) {
                @\unlink($file);
            }
        });
    }
}
