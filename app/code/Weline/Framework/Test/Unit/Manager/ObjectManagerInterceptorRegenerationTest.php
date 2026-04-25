<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Manager;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Block\ThemeConfig;
use Weline\Framework\Manager\ObjectManager;

final class ObjectManagerInterceptorRegenerationTest extends TestCase
{
    private string $interceptorPath;
    private bool $hadOriginalFile = false;
    private string $originalContents = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->interceptorPath = BP . 'generated' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR
            . 'Weline' . DIRECTORY_SEPARATOR . 'Backend' . DIRECTORY_SEPARATOR . 'Block' . DIRECTORY_SEPARATOR
            . 'ThemeConfig' . DIRECTORY_SEPARATOR . 'Interceptor.php';

        $this->hadOriginalFile = \is_file($this->interceptorPath);
        if ($this->hadOriginalFile) {
            $contents = \file_get_contents($this->interceptorPath);
            $this->originalContents = $contents === false ? '' : $contents;
            \unlink($this->interceptorPath);
        }

        ObjectManager::relieveMemoryPressure(true);
        ObjectManager::clearInstances();
    }

    protected function tearDown(): void
    {
        if ($this->hadOriginalFile) {
            $dir = \dirname($this->interceptorPath);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0755, true);
            }
            \file_put_contents($this->interceptorPath, $this->originalContents);
        } elseif (\is_file($this->interceptorPath)) {
            \unlink($this->interceptorPath);
        }

        ObjectManager::relieveMemoryPressure(true);
        ObjectManager::clearInstances();

        parent::tearDown();
    }

    public function testParserClassRegeneratesMissingInterceptorForRegisteredPlugin(): void
    {
        $resolvedClass = ObjectManager::parserClass(ThemeConfig::class);

        self::assertSame(ThemeConfig::class . '\\Interceptor', $resolvedClass);
        self::assertFileExists($this->interceptorPath);
        self::assertTrue(\class_exists($resolvedClass, false));
    }
}
