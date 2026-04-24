<?php
declare(strict_types=1);

/**
 * Weline Server - 指标快照写盘器
 *
 * `MetricsRegistry` 是进程内内存状态；多进程 WLS（Master + Dispatcher + 多个 Worker）
 * 每个进程各自持有一份。为了让 `server:status`（CLI 独立进程）或任何外部 exporter
 * 能看到"整个实例此刻的指标视图"，各业务进程按节流窗口把 snapshot 写到文件：
 *
 *     var/wls/metrics/{instance_name}/{role}-{pid}.json
 *
 * 这里有意**不走 WlsLogger / 不走 IPC**：
 *   - 避免与日志管道耦合，写失败也不能影响业务；
 *   - 跨进程聚合天然适合文件系统（外部 exporter 也可直接读）；
 *   - 写入原子性依赖 `rename()`（tmp → final），避免 reader 读半截。
 *
 * 调用者协议：
 *   - Dispatcher / ServiceOrchestrator 在主循环的 tick 里调 `maybeWrite()`；
 *     若距上次写入未达 `flushIntervalSec`，方法直接返回，开销可忽略。
 *   - 进程退出前调一次 `writeNow()` 让最后一帧落盘。
 */

namespace Weline\Server\Observability;

use Weline\Framework\App\Env;

class MetricsSnapshotWriter
{
    /**
     * 默认 flush 间隔：每 5 秒一次。
     *
     * 太短（<1s）会让 Dispatcher 热路径出现可见 I/O；太长（>30s）则让运维侧观测延迟过大。
     * 5s 是线上"肉眼观察 server:status"合理节奏。
     */
    public const DEFAULT_FLUSH_INTERVAL_SEC = 5.0;

    private string $instanceName;
    private string $role;
    private int $pid;
    private float $flushIntervalSec;
    private float $lastFlushAt = 0.0;
    private ?string $baseDir = null;

    public function __construct(
        string $instanceName,
        string $role,
        ?int $pid = null,
        float $flushIntervalSec = self::DEFAULT_FLUSH_INTERVAL_SEC
    ) {
        $this->instanceName = $instanceName !== '' ? $instanceName : 'default';
        $this->role = $role !== '' ? $role : 'unknown';
        $this->pid = $pid ?? (\getmypid() ?: 0);
        $this->flushIntervalSec = \max(0.5, $flushIntervalSec);
    }

    /**
     * 节流写入：距上次写超过 flushIntervalSec 才真正落盘。
     * 返回值表示本次是否确实 flush。
     */
    public function maybeWrite(): bool
    {
        $now = \microtime(true);
        if (($now - $this->lastFlushAt) < $this->flushIntervalSec) {
            return false;
        }

        return $this->writeNow();
    }

    /**
     * 强制立即写入（进程退出前 / 调试 / 关键事件后调用）。
     *
     * 失败不抛异常，统一吞 —— 观测性基础设施不应让业务路径失败。
     */
    public function writeNow(): bool
    {
        $this->lastFlushAt = \microtime(true);

        try {
            $baseDir = $this->resolveBaseDir();
            if ($baseDir === null) {
                return false;
            }

            $targetDir = $baseDir . \DIRECTORY_SEPARATOR . $this->sanitize($this->instanceName);
            if (!\is_dir($targetDir) && !@\mkdir($targetDir, 0775, true) && !\is_dir($targetDir)) {
                return false;
            }

            $fileName = $this->sanitize($this->role) . '-' . $this->pid . '.json';
            $finalPath = $targetDir . \DIRECTORY_SEPARATOR . $fileName;
            $tmpPath = $finalPath . '.tmp.' . $this->pid;

            $payload = [
                'instance' => $this->instanceName,
                'role' => $this->role,
                'pid' => $this->pid,
                'written_at' => \date(\DATE_ATOM),
                'metrics' => MetricsRegistry::snapshot(),
            ];

            $json = \json_encode(
                $payload,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT
            );
            if ($json === false) {
                return false;
            }

            if (@\file_put_contents($tmpPath, $json, \LOCK_EX) === false) {
                return false;
            }

            // Windows 下 rename 目标已存在会失败，先 unlink 兜底
            if (\is_file($finalPath) && !@\unlink($finalPath)) {
                // rename 会在多数 FS 覆盖成功；unlink 失败非致命
            }

            return @\rename($tmpPath, $finalPath);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 进程退出时最后一次落盘并清理 tmp 文件。
     */
    public function shutdown(): void
    {
        $this->writeNow();
    }

    /**
     * 读取某实例下所有进程的最新指标文件，供 `server:status` 聚合展示。
     *
     * @return array<string, array<string, mixed>>  key = "role-pid"
     */
    public static function loadAll(string $instanceName): array
    {
        $writer = new self($instanceName, 'reader', 0, self::DEFAULT_FLUSH_INTERVAL_SEC);
        $baseDir = $writer->resolveBaseDir();
        if ($baseDir === null) {
            return [];
        }

        $targetDir = $baseDir . \DIRECTORY_SEPARATOR . $writer->sanitize($instanceName);
        if (!\is_dir($targetDir)) {
            return [];
        }

        $out = [];
        $handle = @\opendir($targetDir);
        if ($handle === false) {
            return [];
        }

        try {
            while (($file = \readdir($handle)) !== false) {
                if ($file === '.' || $file === '..' || !\str_ends_with($file, '.json')) {
                    continue;
                }
                $path = $targetDir . \DIRECTORY_SEPARATOR . $file;
                $raw = @\file_get_contents($path);
                if ($raw === false || $raw === '') {
                    continue;
                }
                $decoded = \json_decode($raw, true);
                if (!\is_array($decoded)) {
                    continue;
                }
                $key = (string) ($decoded['role'] ?? 'unknown')
                    . '-' . (string) ($decoded['pid'] ?? '0');
                $out[$key] = $decoded;
            }
        } finally {
            \closedir($handle);
        }

        return $out;
    }

    /**
     * 解析指标基目录；优先 env.php 指定，否则落到 `var/wls/metrics`。
     *
     * 约定目录下再按 instanceName 分子目录，确保多实例互不干扰。
     */
    private function resolveBaseDir(): ?string
    {
        if ($this->baseDir !== null) {
            return $this->baseDir;
        }

        // 配置覆盖：env.php["wls"]["observability"]["metrics_dir"]
        try {
            $config = Env::getInstance()->getConfig() ?: [];
            $custom = $config['wls']['observability']['metrics_dir'] ?? null;
            if (\is_string($custom) && $custom !== '') {
                $this->baseDir = \rtrim($custom, "\\/");
                return $this->baseDir;
            }
        } catch (\Throwable) {
            // 配置读取失败不影响默认路径
        }

        // 默认路径：基于 BP（项目根）下 var/wls/metrics
        // 不走 Env 的 var 路径 API（框架本版 Env 未提供 getVarPath），
        // 直接按约定目录结构落盘；老板/运维侧 `server:status` 读取同路径。
        $root = \defined('BP') ? (string) \constant('BP') : (\getcwd() ?: \sys_get_temp_dir());
        $this->baseDir = \rtrim($root, "\\/") . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR . 'wls' . \DIRECTORY_SEPARATOR . 'metrics';
        return $this->baseDir;
    }

    /**
     * 去除会破坏路径的字符（避免写进奇怪目录）。
     */
    private function sanitize(string $value): string
    {
        $safe = \preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
        return $safe !== null && $safe !== '' ? $safe : 'unknown';
    }
}
