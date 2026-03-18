<?php
declare(strict_types=1);

namespace Weline\Cron\Service;

use Weline\Framework\Http\Sse\SseWriter;

/**
 * 后台 SSE：子进程执行 cron:task:run &lt;execute_name&gt; -f，可选 putenv WELINE_CRON_MANUAL_ARGS。
 */
final class CronManualRunStreamer
{
    private const MANUAL_ARGS_ENV = 'WELINE_CRON_MANUAL_ARGS';

    private const SUFFIX_MAX = 4096;

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
        if ($suffix !== '') {
            if (!\putenv(self::MANUAL_ARGS_ENV . '=' . $suffix)) {
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

        $cmd = [$phpBinary, $wScript, 'cron:task:run', $executeName, '-f'];
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @\proc_open($cmd, $descriptorspec, $pipes, BP, null);
        if (!\is_resource($process)) {
            $this->restoreManualEnv($prevManual);
            $sse->sendEvent('error', ['message' => (string)__('无法启动子进程')]);
            $sse->complete(['exit_code' => -1, 'message' => (string)__('proc_open 失败')]);

            return;
        }

        \fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $exitCode = -1;

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

                foreach ([$pipes[1] => 'stdout', $pipes[2] => 'stderr'] as $pipe => $stream) {
                    if (!\in_array($pipe, $read, true)) {
                        continue;
                    }
                    $data = @\fread($pipe, 8192);
                    if ($data !== false && $data !== '') {
                        $sse->sendEvent('chunk', ['content' => $data, 'stream' => $stream]);
                    }
                }

                $status = \proc_get_status($process);
                if (!$status['running']) {
                    \stream_set_blocking($pipes[1], true);
                    \stream_set_blocking($pipes[2], true);
                    foreach ([$pipes[1] => 'stdout', $pipes[2] => 'stderr'] as $pipe => $stream) {
                        $rest = @\stream_get_contents($pipe);
                        if ($rest !== false && $rest !== '') {
                            $sse->sendEvent('chunk', ['content' => $rest, 'stream' => $stream]);
                        }
                        @\fclose($pipe);
                    }
                    $exitCode = \proc_close($process);
                    $process = null;
                    break;
                }

                $sse->sendHeartbeat();
            }

            if (\is_resource($process)) {
                @\fclose($pipes[1]);
                @\fclose($pipes[2]);
                $exitCode = \proc_close($process);
            }
        } finally {
            $this->restoreManualEnv($prevManual);
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
}
