<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\Dispatcher;

class DispatcherHttpsDetectionFallbackTest extends TestCase
{
    private array $cleanupFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupFiles as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
            if (\is_file($file . '.lock')) {
                @\unlink($file . '.lock');
            }
        }

        parent::tearDown();
    }

    public function testDetectHttpsEnabledReadsRuntimeInstanceFlagFirst(): void
    {
        $instanceName = 'dispatcher-https-runtime';
        $this->writeJson(
            BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json',
            ['ssl_enabled' => true]
        );
        $this->writeJson(
            BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $instanceName . '.json',
            ['ssl_cert' => '', 'ssl_key' => '']
        );

        self::assertTrue($this->detectHttpsEnabled($instanceName));
    }

    public function testDetectHttpsEnabledFallsBackToSavedConfigWhenRuntimeInstanceIsPartial(): void
    {
        $instanceName = 'dispatcher-https-config-fallback';
        $this->writeJson(
            BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $instanceName . '.json',
            ['master_enabled' => true]
        );
        $this->writeJson(
            BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $instanceName . '.json',
            ['ssl_cert' => BP . 'app/etc/ssl/example/fullchain.pem', 'ssl_key' => BP . 'app/etc/ssl/example/privkey.pem']
        );

        self::assertTrue($this->detectHttpsEnabled($instanceName));
    }

    public function testDetectHttpsEnabledReturnsFalseWithoutRuntimeOrSavedSslSignals(): void
    {
        $instanceName = 'dispatcher-https-disabled';
        $this->writeJson(
            BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $instanceName . '.json',
            ['host' => '127.0.0.1', 'port' => 8080]
        );

        self::assertFalse($this->detectHttpsEnabled($instanceName));
    }

    private function detectHttpsEnabled(string $instanceName): bool
    {
        $dispatcher = $this->newDispatcherWithoutConstructor();
        $resolver = new \ReflectionMethod(Dispatcher::class, 'detectHttpsEnabled');
        $resolver->setAccessible(true);

        return (bool) $resolver->invoke($dispatcher, $instanceName);
    }

    private function newDispatcherWithoutConstructor(): Dispatcher
    {
        $reflector = new \ReflectionClass(Dispatcher::class);
        /** @var Dispatcher $dispatcher */
        $dispatcher = $reflector->newInstanceWithoutConstructor();
        return $dispatcher;
    }

    private function writeJson(string $file, array $data): void
    {
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }

        \file_put_contents(
            $file,
            (string) \json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        $this->cleanupFiles[] = $file;
    }
}
