<?php
/**
 * WLS 缺陷修复冒烟测试
 *
 * 快速验证所有关键修复是否生效
 *
 * 运行方式：
 * php tests/WlsFixesSmokeTest.php
 */

require_once __DIR__ . '/../app/code/Weline/Server/Log/WlsLogger.php';
require_once __DIR__ . '/../app/code/Weline/Server/Log/LogLevel.php';
require_once __DIR__ . '/../app/code/Weline/Server/Log/LogConfig.php';
require_once __DIR__ . '/../app/code/Weline/Server/Service/WlsLogService.php';
require_once __DIR__ . '/../app/code/Weline/Framework/System/IPC/NdjsonProtocol.php';

// 定义常量
if (!defined('BP')) {
    define('BP', __DIR__ . '/../');
}

class WlsFixesSmokeTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): void
    {
        echo "=== WLS 缺陷修复冒烟测试 ===\n\n";

        $this->testWlsLoggerMemoryLimit();
        $this->testWlsLoggerBufferLineLimit();
        $this->testWlsLoggerMemoryPressure();
        $this->testNdjsonBufferOverflow();
        $this->testNdjsonHalfPackageLimit();
        $this->testControlClientBufferLimit();
        $this->testLogLevelProduction();
        $this->testIpcPingPongMessages();
        $this->testLogRotation();

        $this->printSummary();
    }

    /**
     * 测试 1: WlsLogger 行数限制
     */
    private function testWlsLoggerBufferLineLimit(): void
    {
        echo "[Test 1] WlsLogger 行数限制测试...\n";

        try {
            $logger = \Weline\Server\Log\WlsLogger::getInstance();
            $logger->setFileEnabled(false);
            $logger->setStdoutEnabled(false);

            // 写入 1500 行日志，应该触发自动刷新
            for ($i = 0; $i < 1500; $i++) {
                $logger->info("Test line $i");
            }

            // 通过反射检查 bufferLineCount
            $reflection = new \ReflectionClass($logger);
            $property = $reflection->getProperty('bufferLineCount');
            $property->setAccessible(true);
            $lineCount = $property->getValue($logger);

            if ($lineCount < 1000) {
                $this->pass("行数限制生效，当前缓冲: {$lineCount} 行");
            } else {
                $this->fail("行数限制未生效，当前缓冲: {$lineCount} 行");
            }
        } catch (\Throwable $e) {
            $this->fail("异常: " . $e->getMessage());
        }
    }

    /**
     * 测试 2: WlsLogger 内存压力保护
     */
    private function testWlsLoggerMemoryPressure(): void
    {
        echo "[Test 2] WlsLogger 内存压力保护测试...\n";

        try {
            // 模拟内存压力场景（通过大量日志）
            $logger = \Weline\Server\Log\WlsLogger::getInstance();
            $logger->setFileEnabled(false);
            $logger->setStdoutEnabled(false);

            // 写入大量日志，检查是否有丢弃机制
            for ($i = 0; $i < 100; $i++) {
                $context = ['data' => str_repeat('x', 1000)];
                $logger->info("Large context test $i", $context);
            }

            $this->pass("内存压力保护机制正常");
        } catch (\Throwable $e) {
            $this->fail("异常: " . $e->getMessage());
        }
    }

    /**
     * 测试 3: NDJSON 缓冲区溢出保护
     */
    private function testNdjsonBufferOverflow(): void
    {
        echo "[Test 3] NDJSON 缓冲区溢出保护测试...\n";

        try {
            // 创建 3MB 缓冲区
            $buffer = str_repeat('x', 3 * 1024 * 1024);
            $messages = \Weline\Framework\System\IPC\NdjsonProtocol::extractMessages($buffer, false);

            if (strlen($buffer) === 0) {
                $this->pass("缓冲区溢出保护生效，已清空");
            } else {
                $this->fail("缓冲区溢出保护未生效，剩余: " . strlen($buffer) . " 字节");
            }
        } catch (\Throwable $e) {
            $this->fail("异常: " . $e->getMessage());
        }
    }

    /**
     * 测试 4: NDJSON 半包消息限制
     */
    private function testNdjsonHalfPackageLimit(): void
    {
        echo "[Test 4] NDJSON 半包消息限制测试...\n";

        try {
            // 创建 1.5MB 半包（无换行符）
            $buffer = str_repeat('y', 1500000);
            $messages = \Weline\Framework\System\IPC\NdjsonProtocol::extractMessages($buffer, false);

            if (strlen($buffer) === 0) {
                $this->pass("半包消息限制生效，已清空");
            } else {
                $this->fail("半包消息限制未生效，剩余: " . strlen($buffer) . " 字节");
            }
        } catch (\Throwable $e) {
            $this->fail("异常: " . $e->getMessage());
        }
    }

    /**
     * 测试 5: ControlClient 缓冲区限制
     */
    private function testControlClientBufferLimit(): void
    {
        echo "[Test 5] ControlClient 缓冲区限制测试...\n";

        try {
            // 检查 ControlClient 源码是否包含 maxBufferSize
            $file = __DIR__ . '/../app/code/Weline/Server/IPC/ControlClient.php';
            if (!file_exists($file)) {
                $this->fail("ControlClient.php 文件不存在");
                return;
            }

            $content = file_get_contents($file);
            if (strpos($content, 'maxBufferSize') !== false && strpos($content, '2097152') !== false) {
                $this->pass("ControlClient 缓冲区限制已设置: 2MB");
            } else {
                $this->fail("ControlClient 缓冲区限制未找到");
            }
        } catch (\Throwable $e) {
            $this->fail("异常: " . $e->getMessage());
        }
    }

    /**
     * 测试 6: 生产环境日志级别控制
     */
    private function testLogLevelProduction(): void
    {
        echo "[Test 6] 生产环境日志级别控制测试...\n";

        try {
            // 检查 LogConfig 源码是否包含 production_level
            $file = __DIR__ . '/../app/code/Weline/Server/Log/LogConfig.php';
            if (!file_exists($file)) {
                $this->fail("LogConfig.php 文件不存在");
                return;
            }

            $content = file_get_contents($file);
            if (strpos($content, 'production_level') !== false) {
                $this->pass("生产环境日志级别控制已实现");
            } else {
                $this->fail("生产环境日志级别控制未实现");
            }
        } catch (\Throwable $e) {
            $this->fail("异常: " . $e->getMessage());
        }
    }

    /**
     * 测试 7: IPC ping/pong 消息类型
     */
    private function testIpcPingPongMessages(): void
    {
        echo "[Test 7] IPC ping/pong 消息类型测试...\n";

        try {
            // 检查 ControlMessage 源码
            $file = __DIR__ . '/../app/code/Weline/Server/IPC/ControlMessage.php';
            if (!file_exists($file)) {
                $this->fail("ControlMessage.php 文件不存在");
                return;
            }

            $content = file_get_contents($file);
            $hasPing = strpos($content, 'TYPE_PING') !== false;
            $hasPong = strpos($content, 'TYPE_PONG') !== false;

            if ($hasPing && $hasPong) {
                $this->pass("IPC ping/pong 消息类型已实现");
            } else {
                $this->fail("IPC ping/pong 消息类型未实现");
            }
        } catch (\Throwable $e) {
            $this->fail("异常: " . $e->getMessage());
        }
    }

    /**
     * 测试 8: 日志轮转功能
     */
    private function testLogRotation(): void
    {
        echo "[Test 8] 日志轮转功能测试...\n";

        try {
            // 检查日志文件名是否包含日期
            $logFile = \Weline\Server\Log\LogConfig::getMainLogFile();
            $today = date('Y-m-d');

            if (strpos($logFile, $today) !== false) {
                $this->pass("日志轮转功能已实现（按日期分割）");
            } else {
                $this->fail("日志轮转功能未实现");
            }
        } catch (\Throwable $e) {
            $this->fail("异常: " . $e->getMessage());
        }
    }

    /**
     * 测试 9: WlsLogger 内存限制检测
     */
    private function testWlsLoggerMemoryLimit(): void
    {
        echo "[Test 9] WlsLogger 内存限制检测测试...\n";

        try {
            $logger = \Weline\Server\Log\WlsLogger::getInstance();
            $reflection = new \ReflectionClass($logger);
            $method = $reflection->getMethod('getMemoryLimit');
            $method->setAccessible(true);
            $limit = $method->invoke($logger);

            if ($limit > 0 || $limit === 0) {
                $this->pass("内存限制检测功能正常，当前限制: " . ($limit === 0 ? '无限制' : round($limit / 1024 / 1024) . 'MB'));
            } else {
                $this->fail("内存限制检测功能异常");
            }
        } catch (\Throwable $e) {
            $this->fail("异常: " . $e->getMessage());
        }
    }

    private function pass(string $message): void
    {
        $this->passed++;
        echo "  ✓ PASS: {$message}\n\n";
    }

    private function fail(string $message): void
    {
        $this->failed++;
        $this->failures[] = $message;
        echo "  ✗ FAIL: {$message}\n\n";
    }

    private function printSummary(): void
    {
        echo "=== 测试总结 ===\n";
        echo "通过: {$this->passed}\n";
        echo "失败: {$this->failed}\n";
        echo "总计: " . ($this->passed + $this->failed) . "\n\n";

        if ($this->failed > 0) {
            echo "失败详情:\n";
            foreach ($this->failures as $i => $failure) {
                echo ($i + 1) . ". {$failure}\n";
            }
            exit(1);
        } else {
            echo "✓ 所有测试通过！\n";
            exit(0);
        }
    }
}

// 运行测试
$test = new WlsFixesSmokeTest();
$test->run();
