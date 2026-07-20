<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

use DateTimeImmutable;
use RuntimeException;

/**
 * Starts a Runner outside the HTTP/WLS request Fiber.
 */
final class RuntimeRunnerProcessLauncher implements RuntimeRunnerProcessLauncherInterface
{
    public function launch(RuntimeRunnerCommand $command): RuntimeProcessIdentity
    {
        $argv = $command->toArgv();
        $spawn = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'spawn.php';
        $process = $command->invocation->process;
        $logFile = rtrim(BP, '/\\') . '/var/process/' . $process->processName . '.log';
        $readyFile = rtrim(BP, '/\\') . '/var/process/' . $process->processName . '.ready';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        @unlink($readyFile);

        // Bypass Processer::create()/nohup. Pass a ready-file path so spawn.php
        // publishes the detached grandchild PID after posix_setsid().
        $cmd = 'cd ' . escapeshellarg($command->projectRoot)
            . ' && ( '
            . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($spawn)
            . ' ' . escapeshellarg($readyFile)
            . ' ' . implode(' ', array_map('escapeshellarg', $argv))
            . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $! '
            . ')';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $processHandle = @proc_open($cmd, $descriptors, $pipes, $command->projectRoot);
        if (!is_resource($processHandle)) {
            throw new RuntimeException('Unable to start the resumable task Runner process.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $output = '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            stream_set_blocking($pipes[1], true);
            $output = trim((string)stream_get_contents($pipes[1]));
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        proc_close($processHandle);

        $helperPid = ctype_digit($output) ? (int)$output : 0;
        if ($helperPid < 1) {
            throw new RuntimeException('Unable to start the resumable task Runner process (no PID).');
        }

        $pid = 0;
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            if (is_file($readyFile)) {
                $payload = trim((string)@file_get_contents($readyFile));
                if ($payload !== '' && ctype_digit($payload)) {
                    $pid = (int)$payload;
                    if ($pid > 0) {
                        break;
                    }
                }
            }
            // Helper may still be starting; keep waiting while it is alive.
            if ($helperPid > 1 && function_exists('posix_kill') && !@posix_kill($helperPid, 0)) {
                // Helper exited before publishing READY — give the grandchild a
                // brief grace period, then fail closed.
                usleep(50_000);
                if (is_file($readyFile)) {
                    $payload = trim((string)@file_get_contents($readyFile));
                    if ($payload !== '' && ctype_digit($payload) && (int)$payload > 0) {
                        $pid = (int)$payload;
                    }
                }
                break;
            }
            usleep(10_000);
        }
        @unlink($readyFile);

        if ($pid < 1) {
            @file_put_contents(
                $logFile,
                date('c') . " launcher failed helper_pid={$helperPid} ready_missing\n",
                FILE_APPEND
            );
            throw new RuntimeException('Unable to resolve the detached resumable task Runner PID.');
        }

        return $command->invocation->process->withStartedProcess(
            $pid,
            implode(' ', $argv),
            new DateTimeImmutable('now'),
        );
    }
}
