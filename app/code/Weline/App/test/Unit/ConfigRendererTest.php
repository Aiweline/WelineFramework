<?php
declare(strict_types=1);

namespace {
    if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        require __DIR__ . '/../bootstrap.php';
    }
}

namespace Weline\App\Test\Unit {
    use PHPUnit\Framework\TestCase;
    use Weline\App\Service\Build\ConfigRenderer;
    use Weline\Framework\Database\Exception\LinkException;

    /**
     * 配置渲染服务单元测试
     */
    class ConfigRendererTest extends TestCase
    {
        public function testInvalidPlatformThrows(): void
        {
            try {
                (new ConfigRenderer())->render(0, 'ios11');
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('非法平台', $e->getMessage());
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'DB unavailable')) {
                    $this->markTestSkipped('DB unavailable');
                }
                throw $e;
            }
        }

        public function testIosPlatformPassesValidation(): void
        {
            try {
                (new ConfigRenderer())->render(0, 'ios');
                $this->addToAssertionCount(1);
            } catch (\RuntimeException|LinkException $e) {
                $this->assertStringNotContainsString('非法平台', $e->getMessage());
            }
        }

        public function testWebPlatformPassesValidation(): void
        {
            try {
                (new ConfigRenderer())->render(0, 'web');
                $this->addToAssertionCount(1);
            } catch (\RuntimeException|LinkException $e) {
                $this->assertStringNotContainsString('非法平台', $e->getMessage());
            }
        }
    }

    if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        $methods = ['testInvalidPlatformThrows', 'testIosPlatformPassesValidation', 'testWebPlatformPassesValidation'];
        $failures = 0;
        foreach ($methods as $method) {
            try {
                $test = (new \ReflectionClass(ConfigRendererTest::class))->newInstance($method);
                $test->{$method}();
                echo 'PASS: ' . $method . PHP_EOL;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'DB unavailable')) {
                    echo 'SKIP: ' . $method . PHP_EOL;
                    continue;
                }
                $failures++;
                echo 'FAIL: ' . $method . ': ' . $e->getMessage() . PHP_EOL;
            }
        }
        if ($failures > 0) {
            exit(1);
        }
    }
}
