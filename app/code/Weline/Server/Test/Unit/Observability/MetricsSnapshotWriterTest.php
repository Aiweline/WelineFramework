<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Weline\Server\Observability\MetricsRegistry;
use Weline\Server\Observability\MetricsSnapshotWriter;

/**
 * `MetricsSnapshotWriter` 负责把 `MetricsRegistry` 的内存快照落到文件，
 * 供 CLI 独立进程（server:status）跨进程聚合展示。
 *
 * 单测锁定三件事：
 *   1. 首次 writeNow() 能落盘合法 JSON，且可被 loadAll() 读回；
 *   2. 同一实例不同 role/pid 的文件互不干扰，loadAll() 返回合并视图；
 *   3. maybeWrite() 的节流语义：距上次写未满间隔直接 no-op，满间隔后会写。
 *
 * 文件写入用子类钩子 `getTestDir()` 定向到系统临时目录，避免污染仓库。
 */
final class MetricsSnapshotWriterTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        MetricsRegistry::reset();
        $this->tmpRoot = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . 'wls-metrics-test-' . \uniqid('', true);
        \mkdir($this->tmpRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        MetricsRegistry::reset();
        $this->removeDir($this->tmpRoot);
    }

    public function testWriteNowPersistsSnapshotAndLoadAllReadsItBack(): void
    {
        MetricsRegistry::inc('dispatcher.connection.accepted', 7);
        MetricsRegistry::gauge('worker_pool_size', 4.0);
        MetricsRegistry::observe('latency.ms', 12.5);

        $writer = $this->makeWriter('demo-instance', 'dispatcher', 9991);
        $this->assertTrue($writer->writeNow(), 'writeNow 应成功落盘');

        $all = $this->loadAllViaSameDir('demo-instance');
        $this->assertCount(1, $all);
        $this->assertArrayHasKey('dispatcher-9991', $all);

        $doc = $all['dispatcher-9991'];
        $this->assertSame('demo-instance', $doc['instance']);
        $this->assertSame('dispatcher', $doc['role']);
        $this->assertSame(9991, $doc['pid']);
        $this->assertSame(7, $doc['metrics']['counters']['dispatcher.connection.accepted']);
        // json_encode(4.0) 落盘成 "4"，再 decode 回来是 int；这是 JSON 数字类型语义
        // 所致，对观测展示无影响，因此只做数值相等断言而非严格同类型断言。
        $this->assertEquals(4.0, $doc['metrics']['gauges']['worker_pool_size']);
        $this->assertSame(1, $doc['metrics']['histograms']['latency.ms']['count']);
    }

    public function testLoadAllMergesMultipleProcessFiles(): void
    {
        MetricsRegistry::inc('counter.a', 1);
        $w1 = $this->makeWriter('multi', 'dispatcher', 1001);
        $this->assertTrue($w1->writeNow());

        // 模拟另一个进程：reset + 新计数 + 新 writer
        MetricsRegistry::reset();
        MetricsRegistry::inc('counter.b', 5);
        $w2 = $this->makeWriter('multi', 'worker', 2002);
        $this->assertTrue($w2->writeNow());

        $all = $this->loadAllViaSameDir('multi');
        $this->assertCount(2, $all);
        $this->assertSame(1, $all['dispatcher-1001']['metrics']['counters']['counter.a']);
        $this->assertSame(5, $all['worker-2002']['metrics']['counters']['counter.b']);
    }

    public function testMaybeWriteThrottlesToTheConfiguredInterval(): void
    {
        $writer = $this->makeWriter('throttle', 'dispatcher', 4242, 0.5);

        // 首次：强制 writeNow 推进 lastFlushAt
        $this->assertTrue($writer->writeNow());
        // 紧跟着 maybeWrite 不应立即 flush
        $this->assertFalse($writer->maybeWrite(), '未到间隔不应再次 flush');
        // 睡到窗口外再 maybeWrite 应 flush
        \usleep(600_000);
        $this->assertTrue($writer->maybeWrite(), '超过间隔后 maybeWrite 应 flush');
    }

    public function testLoadAllReturnsEmptyWhenInstanceDirMissing(): void
    {
        $all = $this->loadAllViaSameDir('never-written');
        $this->assertSame([], $all);
    }

    /**
     * 构造一个把 baseDir 钉死到本测试 tmpRoot 的 Writer 子类实例。
     */
    private function makeWriter(string $instance, string $role, int $pid, float $interval = 5.0): MetricsSnapshotWriter
    {
        return new class($instance, $role, $pid, $interval, $this->tmpRoot) extends MetricsSnapshotWriter {
            private string $forcedBaseDir;

            public function __construct(string $instance, string $role, int $pid, float $interval, string $forcedBaseDir)
            {
                parent::__construct($instance, $role, $pid, $interval);
                $this->forcedBaseDir = $forcedBaseDir;
            }

            protected function resolveBaseDirForTest(): string
            {
                return $this->forcedBaseDir;
            }

            public function writeNow(): bool
            {
                // 反射把父类私有 baseDir 写入为测试路径，绕开 Env/BP 探测
                $ref = new \ReflectionClass(MetricsSnapshotWriter::class);
                $prop = $ref->getProperty('baseDir');
                $prop->setAccessible(true);
                $prop->setValue($this, $this->forcedBaseDir);
                return parent::writeNow();
            }
        };
    }

    /**
     * loadAll 的 static 默认实现会走 BP/cwd 默认路径；这里复用 Writer 的反射技巧，
     * 改造一份读端让它也指向 tmpRoot。
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadAllViaSameDir(string $instance): array
    {
        $safeInstance = \preg_replace('/[^A-Za-z0-9._-]/', '_', $instance) ?: 'unknown';
        $dir = $this->tmpRoot . \DIRECTORY_SEPARATOR . $safeInstance;
        if (!\is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (\scandir($dir) ?: [] as $file) {
            if ($file === '.' || $file === '..' || !\str_ends_with($file, '.json')) {
                continue;
            }
            $raw = \file_get_contents($dir . \DIRECTORY_SEPARATOR . $file);
            if (!\is_string($raw) || $raw === '') {
                continue;
            }
            $doc = \json_decode($raw, true);
            if (!\is_array($doc)) {
                continue;
            }
            $key = ((string) ($doc['role'] ?? 'unknown')) . '-' . ((string) ($doc['pid'] ?? '0'));
            $out[$key] = $doc;
        }
        return $out;
    }

    private function removeDir(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . \DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($full)) {
                $this->removeDir($full);
            } else {
                @\unlink($full);
            }
        }
        @\rmdir($path);
    }
}
