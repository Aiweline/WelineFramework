<?php
declare(strict_types=1);

namespace Weline\Cron\Service;

use Weline\Framework\Http\Sse\SseWriter;

/**
 * 后台 SSE：子进程执行 cron:task:run &lt;execute_name&gt; -f，可选 putenv WELINE_CRON_MANUAL_ARGS。
 * 输出合并推送，避免海量小 chunk 拖垮浏览器。
 */
final class CronManualRunStreamer
{
    private const MANUAL_ARGS_ENV = 'WELINE_CRON_MANUAL_ARGS';

    private const MANUAL_SSE_ENV = 'WELINE_CRON_MANUAL_SSE';

    private const SUFFIX_MAX = 4096;

    /** 距上次推送至少间隔（秒） */
    private const CHUNK_FLUSH_INTERVAL = 0.22;

    /** 缓冲达此字节数则尽快推送 */
    private const CHUNK_FLUSH_MIN_BYTES = 12288;

    /** 单条 SSE chunk 最大字节（JSON 不宜过大） */
    private const CHUNK_MAX_SLICE = 98304;

    public function stream(string $executeName, string $suffix, SseWriter $sse): void
    {
        $phpBinary = (\defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
        $wScript = BP . 'bin' . \DIRECTORY_SEPARATOR . 'w';
        if (!\is_file($wScript)) {
            $sse->start();
            $sse->sendError((string)__('未找到 CLI 入口：%{1}', $wScript));

            return;
        }

        $suffix = $this->sanitizeSuffix($suffix);
        $prevManual = \getenv(self::MANUAL_ARGS_ENV);
        $prevSseMirror = \getenv(self::MANUAL_SSE_ENV);
        if (!\putenv(self::MANUAL_SSE_ENV . '=1')) {
            $sse->start();
            $sse->sendError((string)__('无法设置手动运行环境变量'));

            return;
        }
        if ($suffix !== '') {
            if (!\putenv(self::MANUAL_ARGS_ENV . '=' . $suffix)) {
                $this->restoreSseMirrorEnv($prevSseMirror);
                $sse->start();
                $sse->sendError((string)__('无法设置手动运行环境变量'));

                return;
            }
        } else {
            \putenv(self::MANUAL_ARGS_ENV);
        }

        $sse->start();
        $sse->sendEvent('start', [
            'message' => (string)__('开始执行：%{1}', $executeName),
            'execute_name' => $executeName,
        ]);

        // 关闭块缓冲便于分块读取；implicit_flush 易引发极细粒度写入，由本类合并后再推 SSE
        $cmd = [
            $phpBinary,
            '-d', 'output_buffering=0',
            '-d', 'zlib.output_compression=0',
            $wScript,
            'cron:task:run',
            $executeName,
            '-f',
        ];
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @\proc_open($cmd, $descriptorspec, $pipes, BP, null);
        if (!\is_resource($process)) {
            $this->restoreManualEnv($prevManual);
            $this->restoreSseMirrorEnv($prevSseMirror);
            $sse->sendEvent('error', ['message' => (string)__('无法启动子进程')]);
            $sse->complete(['exit_code' => -1, 'message' => (string)__('proc_open 失败')]);

            return;
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $exitCode = -1;
        $pendingOut = '';
        $pendingErr = '';
        $lastChunkFlush = \microtime(true);

        $emitBuffered = static function (SseWriter $w, string $label, string &$buf) : void {
            while ($buf !== '') {
                $take = \strlen($buf) > self::CHUNK_MAX_SLICE
                    ? \substr($buf, 0, self::CHUNK_MAX_SLICE)
                    : $buf;
                $buf = \strlen($buf) > self::CHUNK_MAX_SLICE
                    ? \substr($buf, self::CHUNK_MAX_SLICE)
                    : '';
                $w->sendEvent('chunk', ['content' => $take, 'stream' => $label]);
            }
        };

        $tryFlush = function (bool $force) use ($sse, &$pendingOut, &$pendingErr, &$lastChunkFlush, $emitBuffered): void {
            $n = \strlen($pendingOut) + \strlen($pendingErr);
            if ($n === 0) {
                return;
            }
            $now = \microtime(true);
            if (
                !$force
                && ($now - $lastChunkFlush) < self::CHUNK_FLUSH_INTERVAL
                && $n < self::CHUNK_FLUSH_MIN_BYTES
            ) {
                return;
            }
            $emitBuffered($sse, 'stdout', $pendingOut);
            $emitBuffered($sse, 'stderr', $pendingErr);
            $lastChunkFlush = $now;
        };

        try {
            while (true) {
                if (!$sse->isAlive()) {
                    @\proc_terminate($process);
                    break;
                }

                $read = [$pipes[1], $pipes[2]];
                $write = null;
                $except = null;
                @\stream_select($read, $write, $except, 1);

                // 禁止用 resource 作数组键：会被转成 int，导致 fread/stream_get_contents 收到整数报错
                foreach ([[$pipes[1], 'out'], [$pipes[2], 'err']] as [$pipe, $which]) {
                    if (!\in_array($pipe, $read, true)) {
                        continue;
                    }
                    $data = @\fread($pipe, 65536);
                    if ($data !== false && $data !== '') {
                        if ($which === 'out') {
                            $pendingOut .= $data;
                        } else {
                            $pendingErr .= $data;
                        }
                    }
                }

                $tryFlush(false);

                $status = \proc_get_status($process);
                if (!$status['running']) {
                    \stream_set_blocking($pipes[1], true);
                    \stream_set_blocking($pipes[2], true);
                    foreach ([[$pipes[1], 'out'], [$pipes[2], 'err']] as [$pipe, $which]) {
                        $rest = @\stream_get_contents($pipe);
                        if ($rest !== false && $rest !== '') {
                            if ($which === 'out') {
                                $pendingOut .= $rest;
                            } else {
                                $pendingErr .= $rest;
                            }
                        }
                        @\fclose($pipe);
                    }
                    $tryFlush(true);
                    $exitCode = \proc_close($process);
                    $process = null;
                    break;
                }

                $sse->maybeHeartbeat();
            }

            if (\is_resource($process)) {
                @\fclose($pipes[1]);
                @\fclose($pipes[2]);
                $exitCode = \proc_close($process);
            }
        } finally {
            $this->restoreManualEnv($prevManual);
            $this->restoreSseMirrorEnv($prevSseMirror);
        }

        if ($exitCode !== 0) {
            $sse->sendEvent('error', [
                'message' => (string)__('进程退出码：%{1}', (string) $exitCode),
                'exit_code' => $exitCode,
            ]);
        }
        $sse->complete([
            'exit_code' => $exitCode,
            'message' => $exitCode === 0
                ? (string)__('执行完成')
                : (string)__('执行结束（非零退出码）'),
        ]);
    }

    private function sanitizeSuffix(string $suffix): string
    {
        $suffix = \str_replace(["\0", "\r", "\n"], '', $suffix);
        if (\strlen($suffix) > self::SUFFIX_MAX) {
            $suffix = \substr($suffix, 0, self::SUFFIX_MAX);
        }

        return $suffix;
    }

    private function restoreManualEnv(string|false $prev): void
    {
        if ($prev === false) {
            \putenv(self::MANUAL_ARGS_ENV);
        } else {
            \putenv(self::MANUAL_ARGS_ENV . '=' . $prev);
        }
    }

    private function restoreSseMirrorEnv(string|false $prev): void
    {
        if ($prev === false) {
            \putenv(self::MANUAL_SSE_ENV);
        } else {
            \putenv(self::MANUAL_SSE_ENV . '=' . $prev);
        }
    }
}
