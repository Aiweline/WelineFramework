<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

class ThemePreviewGenerator
{
    public const PREVIEW_DIR = 'theme_previews';
    private const SCREENSHOT_TIMEOUT_SECONDS = 30;
    private const PROCESS_POLL_INTERVAL_US = 100000;

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
            Env::log_error('theme_preview', __('鐢熸垚涓婚棰勮鍥惧け璐ワ細%{1}', [$e->getMessage()]));
            throw $e;
        }
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

            \usleep(self::PROCESS_POLL_INTERVAL_US);
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
        \usleep(200000);
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
