<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Theme\Model\WelineTheme;

class ThemePreviewGenerator
{
    public const PREVIEW_DIR = 'theme_previews';
    private const SCREENSHOT_TIMEOUT_SECONDS = 60;
    private const PROCESS_POLL_INTERVAL_US = 100000;

    /**
     * 默认并发数量（建议 2-4，Windows 上不要太高）
     */
    public const DEFAULT_CONCURRENCY = 2;

    public static function generatePreviewImage(WelineTheme $theme, string $area = 'frontend', bool $force = false): string|false
    {
        $themeId = $theme->getId();
        if (!$themeId) {
            return false;
        }

        $previewPath = self::getPreviewImagePath($themeId, $area);
        if (!$force && is_file($previewPath)) {
            return $previewPath;
        }

        $previewUrl = self::getPreviewUrl($themeId, $area);

        try {
            return self::captureScreenshot($previewUrl, $previewPath);
        } catch (\Exception $e) {
            Env::log_error('theme_preview', __('生成主题预览图失败：%{1}', [$e->getMessage()]));
            throw $e;
        }
    }

    /**
     * 并发生成多个主题的预览图
     *
     * @param array $tasks 任务数组，每项格式：
     *   - theme: WelineTheme 主题对象
     *   - area: string 区域（frontend/backend）
     *   - force: bool 是否强制重新生成
     * @param int $concurrency 并发数量
     * @param callable|null $progressCallback 进度回调，签名：function(int $completed, int $total, string $message)
     * @return array 结果数组 ['success' => int, 'failed' => int, 'results' => [...]]
     */
    public static function generatePreviewImagesBatch(
        array $tasks,
        int $concurrency = self::DEFAULT_CONCURRENCY,
        ?callable $progressCallback = null
    ): array {
        $total = count($tasks);
        if ($total === 0) {
            return ['success' => 0, 'failed' => 0, 'results' => []];
        }

        $concurrency = max(1, min($concurrency, 8));

        $results = [];
        $success = 0;
        $failed = 0;
        $completed = 0;

        $chromePath = self::getChromePath();
        if (!$chromePath) {
            foreach ($tasks as $task) {
                $results[] = [
                    'theme_id' => $task['theme']->getId(),
                    'area' => $task['area'],
                    'success' => false,
                    'error' => __('Chrome or Chromium browser was not found.'),
                ];
                $failed++;
            }
            return ['success' => 0, 'failed' => $failed, 'results' => $results];
        }

        $pendingTasks = array_values($tasks);
        $runningProcesses = [];

        $sendProgress = function(int $done, int $total, string $message) use ($progressCallback) {
            if ($progressCallback) {
                $progressCallback($done, $total, $message);
            }
        };

        $hasPcntl = function_exists('pcntl_async_signals') && function_exists('pcntl_signal');
        if ($hasPcntl) {
            pcntl_async_signals(true);
        }

        $interrupted = false;
        if ($hasPcntl) {
            pcntl_signal(SIGINT, function() use (&$interrupted) {
                $interrupted = true;
            });
        }

        // 主事件循环
        while ((!empty($pendingTasks) || !empty($runningProcesses)) && !$interrupted) {
            // 启动新任务直到达到并发上限
            while (!empty($pendingTasks) && count($runningProcesses) < $concurrency) {
                $task = array_shift($pendingTasks);
                $process = self::startScreenshotProcess($task, $chromePath);

                if ($process !== null) {
                    $runningProcesses[$process['pid']] = [
                        'process' => $process,
                        'task' => $task,
                        'started_at' => microtime(true),
                    ];
                    // 启动时报告当前状态
                    $running = count($runningProcesses);
                    $pending = count($pendingTasks);
                    $sendProgress(
                        $total - $pending - $running,
                        $total,
                        __('开始 [%{1}] %{2} 预览图...', [
                            $task['theme']->getName(),
                            $task['area'],
                        ])
                    );
                } else {
                    $results[] = [
                        'theme_id' => $task['theme']->getId(),
                        'area' => $task['area'],
                        'success' => false,
                        'error' => __('Failed to start screenshot process.'),
                    ];
                    $failed++;
                    $completed++;
                    $sendProgress($completed, $total, __('启动失败：%{1}', [$task['theme']->getName()]));
                }
            }

            // 检查运行中的进程
            if (!empty($runningProcesses)) {
                $finished = self::collectFinishedProcesses($runningProcesses, $results, $completed, $total, $sendProgress);
                foreach ($finished as $pid => $result) {
                    unset($runningProcesses[$pid]);
                    if ($result['success']) {
                        $success++;
                    } else {
                        $failed++;
                    }
                    $completed++;
                }
            }

            // 如果没有待启动的任务且仍有进程在运行，等待一下再检查
            // 使用 SchedulerSystem::yieldDelay 协作式让步，不阻塞 Worker 处理其他请求
            if (empty($pendingTasks) && !empty($runningProcesses)) {
                SchedulerSystem::yieldDelay((int)(self::PROCESS_POLL_INTERVAL_US / 1000));
            }
        }

        foreach ($runningProcesses as $pid => $info) {
            self::terminateProcessTree($info['process']['resource'], $info['process']['pid']);
            $results[] = [
                'theme_id' => $info['task']['theme']->getId(),
                'area' => $info['task']['area'],
                'success' => false,
                'error' => __('Process was terminated.'),
            ];
            $failed++;
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * 启动单个截图进程（非阻塞）
     */
    private static function startScreenshotProcess(array $task, string $chromePath): ?array
    {
        $theme = $task['theme'];
        $area = $task['area'];
        $force = $task['force'] ?? false;

        $themeId = $theme->getId();
        $previewPath = self::getPreviewImagePath($themeId, $area);

        if (!$force && is_file($previewPath)) {
            return null;
        }

        $dir = dirname($previewPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $previewUrl = self::getPreviewUrl($themeId, $area);
        $timestamp = time();
        $fullUrl = $previewUrl . (str_contains($previewUrl, '?') ? '&' : '?') . 't=' . $timestamp;

        $command = self::buildChromeCommand($chromePath, $previewPath, $fullUrl);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $options = PHP_OS_FAMILY === 'Windows'
            ? ['bypass_shell' => true, 'suppress_errors' => true]
            : [];

        $process = @proc_open($command, $descriptorSpec, $pipes, BP, null, $options);

        if (!is_resource($process)) {
            return null;
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $status = proc_get_status($process);
        $pid = (int)($status['pid'] ?? 0);

        if ($pid <= 0) {
            proc_close($process);
            return null;
        }

        return [
            'pid' => $pid,
            'resource' => $process,
            'pipes' => $pipes,
            'command' => $command,
            'save_path' => $previewPath,
        ];
    }

    /**
     * 收集已完成的进程
     */
    private static function collectFinishedProcesses(
        array &$runningProcesses,
        array &$results,
        int $baseCompleted,
        int $total,
        callable $progressCallback
    ): array {
        $finished = [];

        foreach ($runningProcesses as $pid => &$info) {
            $process = $info['process'];
            $task = $info['task'];
            $resource = $process['resource'];

            $status = proc_get_status($resource);

            if (!($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                $savePath = $process['save_path'];

                clearstatcache(true, $savePath);

                if ($exitCode === 0 && is_file($savePath)) {
                    self::optimizeImage($savePath);
                    $relativePath = self::normalizePreviewRelativePath($savePath);
                    $finished[$pid] = [
                        'theme_id' => $task['theme']->getId(),
                        'area' => $task['area'],
                        'success' => true,
                        'path' => $relativePath,
                    ];
                    $doneCount = $baseCompleted + count($finished);
                    $progressCallback($doneCount, $total, __('✓ %{1} [%{2}] 生成成功', [
                        $task['theme']->getName(),
                        $task['area'],
                    ]));
                } else {
                    $finished[$pid] = [
                        'theme_id' => $task['theme']->getId(),
                        'area' => $task['area'],
                        'success' => false,
                        'error' => __('进程退出码：%{1}', [$exitCode]),
                    ];
                    $doneCount = $baseCompleted + count($finished);
                    $progressCallback($doneCount, $total, __('✗ %{1} [%{2}] 生成失败', [
                        $task['theme']->getName(),
                        $task['area'],
                    ]));
                }

                foreach (($process['pipes'] ?? []) as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                proc_close($resource);
                continue;
            }

            $elapsed = microtime(true) - $info['started_at'];
            if ($elapsed > self::SCREENSHOT_TIMEOUT_SECONDS) {
                self::terminateProcessTree($resource, $pid);

                foreach (($process['pipes'] ?? []) as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                proc_close($resource);

                $finished[$pid] = [
                    'theme_id' => $task['theme']->getId(),
                    'area' => $task['area'],
                    'success' => false,
                    'error' => __('截图超时（%{1}秒）', [self::SCREENSHOT_TIMEOUT_SECONDS]),
                ];
                $doneCount = $baseCompleted + count($finished);
                $progressCallback($doneCount, $total, __('✗ %{1} [%{2}] 超时', [
                    $task['theme']->getName(),
                    $task['area'],
                ]));
            }
        }
        unset($info);

        return $finished;
    }

    public static function getPreviewImagePath(int $themeId, string $area = 'frontend'): string
    {
        $uploadDir = PUB . self::PREVIEW_DIR;
        $filename = "theme_{$themeId}_{$area}.png";
        return $uploadDir . DS . $filename;
    }

    public static function normalizePreviewRelativePath(string $path): string
    {
        $normalized = \str_replace('\\', '/', \trim($path));
        $pubPath = \str_replace('\\', '/', \rtrim(PUB, '\\/'));
        $bpPath = \str_replace('\\', '/', \rtrim(BP, '\\/'));

        if ($pubPath !== '' && \str_starts_with($normalized, $pubPath . '/')) {
            $normalized = \substr($normalized, \strlen($pubPath) + 1);
        } elseif ($bpPath !== '' && \str_starts_with($normalized, $bpPath . '/')) {
            $normalized = \substr($normalized, \strlen($bpPath) + 1);
        }

        $normalized = \ltrim($normalized, '/');
        if (\str_starts_with($normalized, 'pub/')) {
            $normalized = \substr($normalized, 4);
        }

        return \ltrim($normalized, '/');
    }

    public static function getPreviewUrl(int $themeId, string $area = 'frontend'): string
    {
        /** @var \Weline\Framework\Http\Url $url */
        $url = ObjectManager::getInstance(\Weline\Framework\Http\Url::class);

        if ($area === 'backend') {
            return $url->getBackendUrl('admin', [
                'preview_theme' => $themeId,
                'preview_gen' => '1',
            ]);
        }

        return $url->getFrontendUrl('index/index', [
            'preview_theme' => $themeId,
            'preview_gen' => '1',
        ]);
    }

    private static function captureScreenshot(string $url, string $savePath): string
    {
        $dir = \dirname($savePath);
        if (!\is_dir($dir) && !\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new \Exception(__('Unable to create preview image directory: %{1}', [$dir]));
        }

        $chromePath = self::getChromePath();
        if (!$chromePath) {
            throw new \Exception(__('Chrome or Chromium browser was not found.'));
        }

        $timestamp = \time();
        $fullUrl = $url . (\str_contains($url, '?') ? '&' : '?') . 't=' . $timestamp;

        $result = self::runScreenshotCommand(
            $chromePath,
            $savePath,
            $fullUrl,
            self::SCREENSHOT_TIMEOUT_SECONDS
        );

        if ($result['timedOut']) {
            throw new \Exception(__('Browser screenshot timed out after %{1} seconds. URL: %{2}', [
                self::SCREENSHOT_TIMEOUT_SECONDS,
                $fullUrl,
            ]));
        }

        \clearstatcache(true, $savePath);
        if ($result['exitCode'] !== 0 || !\is_file($savePath)) {
            $errorOutput = \implode("\n", $result['output']);
            throw new \Exception(__('Browser screenshot failed. Exit code: %{1}. Output: %{2}', [
                $result['exitCode'],
                $errorOutput,
            ]));
        }

        self::optimizeImage($savePath);
        return $savePath;
    }

    /**
     * @return array{exitCode:int, output:array<int,string>, timedOut:bool}
     */
    private static function runScreenshotCommand(
        string $chromePath,
        string $savePath,
        string $url,
        int $timeoutSeconds
    ): array {
        if (!\function_exists('proc_open')) {
            throw new \Exception(__('`proc_open` is required to run the screenshot command safely.'));
        }

        $command = self::buildChromeCommand($chromePath, $savePath, $url);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $options = PHP_OS_FAMILY === 'Windows'
            ? ['bypass_shell' => true, 'suppress_errors' => true]
            : [];

        $process = @\proc_open($command, $descriptorSpec, $pipes, BP, null, $options);
        if (!\is_resource($process)) {
            throw new \Exception(__('Unable to start the Chrome screenshot process.'));
        }

        $output = [];
        $timedOut = false;
        $exitCode = -1;

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                \stream_set_blocking($pipes[$index], false);
            }
        }
        if (isset($pipes[0]) && \is_resource($pipes[0])) {
            \fclose($pipes[0]);
        }

        $status = \proc_get_status($process);
        $pid = (int)($status['pid'] ?? 0);
        $deadline = \microtime(true) + $timeoutSeconds;

        while (true) {
            self::collectProcessOutput($pipes, $output);

            $status = \proc_get_status($process);
            if (!($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }

            if (\microtime(true) >= $deadline) {
                $timedOut = true;
                self::terminateProcessTree($process, $pid);
                break;
            }

            // 使用 SchedulerSystem::yieldDelay 协作式让步，不阻塞 Worker
            SchedulerSystem::yieldDelay((int)(self::PROCESS_POLL_INTERVAL_US / 1000));
        }

        self::collectProcessOutput($pipes, $output);

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && \is_resource($pipes[$index])) {
                \fclose($pipes[$index]);
            }
        }

        $closeExitCode = \proc_close($process);
        if ($timedOut) {
            $exitCode = 124;
        } elseif ($closeExitCode >= 0) {
            $exitCode = $closeExitCode;
        }

        return [
            'exitCode' => $exitCode,
            'output' => $output,
            'timedOut' => $timedOut,
        ];
    }

    private static function collectProcessOutput(array $pipes, array &$output): void
    {
        foreach ([1, 2] as $index) {
            if (!isset($pipes[$index]) || !\is_resource($pipes[$index])) {
                continue;
            }
            $chunk = \stream_get_contents($pipes[$index]);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            foreach (\preg_split('/\r\n|\r|\n/', $chunk) ?: [] as $line) {
                if ($line !== '') {
                    $output[] = $line;
                }
            }
        }
    }

    private static function buildChromeCommand(string $chromePath, string $savePath, string $url): string
    {
        return \sprintf(
            '"%s" --headless --disable-gpu --no-sandbox --ignore-certificate-errors --screenshot="%s" --window-size=1200,800 "%s"',
            \str_replace('"', '\"', $chromePath),
            \str_replace('"', '\"', $savePath),
            \str_replace('"', '\"', $url)
        );
    }

    private static function terminateProcessTree($process, int $pid): void
    {
        @\proc_terminate($process);

        if ($pid <= 0) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            @\exec("taskkill /F /T /PID {$pid} >NUL 2>NUL");
            return;
        }

        @\exec('kill -TERM ' . $pid . ' >/dev/null 2>&1');
        \Weline\Framework\Runtime\SchedulerSystem::yieldDelay(200);
        @\exec('kill -KILL ' . $pid . ' >/dev/null 2>&1');
    }

    private static function getChromePath(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $envPaths = [
                \getenv('PROGRAMFILES') ? \getenv('PROGRAMFILES') . '\\Google\\Chrome\\Application\\chrome.exe' : null,
                \getenv('PROGRAMFILES(X86)') ? \getenv('PROGRAMFILES(X86)') . '\\Google\\Chrome\\Application\\chrome.exe' : null,
                \getenv('LOCALAPPDATA') ? \getenv('LOCALAPPDATA') . '\\Google\\Chrome\\Application\\chrome.exe' : null,
            ];

            foreach ($envPaths as $path) {
                if ($path && \file_exists($path)) {
                    return $path;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $linuxPaths = [
                '/usr/bin/google-chrome',
                '/usr/bin/chromium',
                '/usr/bin/chromium-browser',
                '/usr/bin/google-chrome-stable',
                '/snap/bin/chromium',
            ];

            foreach ($linuxPaths as $path) {
                if (\file_exists($path) && \is_executable($path)) {
                    return $path;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $macPaths = [
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                '/Applications/Chromium.app/Contents/MacOS/Chromium',
            ];

            foreach ($macPaths as $path) {
                if (\file_exists($path) && \is_executable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private static function optimizeImage(string $imagePath): void
    {
        if (\function_exists('imagecreatefrompng')) {
            $image = \imagecreatefrompng($imagePath);
            if ($image) {
                \imagepng($image, $imagePath, 9);
                \imagedestroy($image);
            }
        }
    }

    public static function deletePreviewImage(int $themeId, ?string $area = null): bool
    {
        if ($area === null) {
            $areas = ['frontend', 'backend'];
            $deleted = true;
            foreach ($areas as $a) {
                $path = self::getPreviewImagePath($themeId, $a);
                if (\is_file($path)) {
                    $deleted = \unlink($path) && $deleted;
                }
            }
            return $deleted;
        }

        $path = self::getPreviewImagePath($themeId, $area);
        if (\is_file($path)) {
            return \unlink($path);
        }

        return false;
    }
}
