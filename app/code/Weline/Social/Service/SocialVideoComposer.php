<?php

declare(strict_types=1);

namespace Weline\Social\Service;

class SocialVideoComposer
{
    public const ERROR_MISSING_MEDIA = 'missing_media';
    public const ERROR_VIDEO_UNAVAILABLE = 'video_unavailable';
    public const ERROR_RENDER_FAILED = 'render_failed';

    public const DEFAULT_WIDTH = 1080;
    public const DEFAULT_HEIGHT = 1920;
    public const DEFAULT_DURATION_MS = 60000;
    public const MAX_DURATION_MS = 60000;
    public const DEFAULT_FPS = 30;

    private const PRESETS = [
        'ultrafast',
        'superfast',
        'veryfast',
        'faster',
        'fast',
        'medium',
        'slow',
        'slower',
        'veryslow',
    ];

    public function isFfmpegAvailable(string $binary = 'ffmpeg'): bool
    {
        $binary = \trim($binary);
        if ($binary === '') {
            return false;
        }

        $output = [];
        $exitCode = 1;
        \exec('command -v ' . \escapeshellarg($binary), $output, $exitCode);

        return $exitCode === 0 && \trim(\implode("\n", $output)) !== '';
    }

    public function canCompose(): bool
    {
        return $this->isFfmpegAvailable($this->defaultFfmpegBinary());
    }

    /**
     * @param array<int, string> $images
     * @param array<string, mixed> $options
     * @return array{success:bool,path:string,duration_ms:int,error:string}
     */
    public function compose(array $images, string $audioPath, array $options = []): array
    {
        try {
            return $this->doCompose($images, $audioPath, $options);
        } catch (\Throwable) {
            return $this->failure(self::ERROR_RENDER_FAILED);
        }
    }

    /**
     * @param array<int, mixed> $images
     * @param array<string, mixed> $options
     * @return array{success:bool,path:string,duration_ms:int,error:string}
     */
    private function doCompose(array $images, string $audioPath, array $options): array
    {
        $images = $this->normalizeImages($images);
        $audioPath = \trim($audioPath);
        if ($images === [] || !$this->isReadableFile($audioPath)) {
            return $this->failure(self::ERROR_MISSING_MEDIA);
        }

        $ffmpegBinary = $this->optionFfmpegBinary($options);
        if ($ffmpegBinary === '' || !$this->isFfmpegAvailable($ffmpegBinary)) {
            return $this->failure(self::ERROR_VIDEO_UNAVAILABLE);
        }

        $width = $this->even($this->clampInt((int)($options['width'] ?? self::DEFAULT_WIDTH), 16, 3840));
        $height = $this->even($this->clampInt((int)($options['height'] ?? self::DEFAULT_HEIGHT), 16, 7680));
        $fps = $this->clampInt((int)($options['fps'] ?? self::DEFAULT_FPS), 1, 60);
        $durationMs = $this->clampInt(
            (int)($options['duration_ms'] ?? $options['max_duration_ms'] ?? self::DEFAULT_DURATION_MS),
            1000,
            self::MAX_DURATION_MS
        );
        $crf = $this->clampInt((int)($options['crf'] ?? 23), 0, 51);
        $preset = \strtolower(\trim((string)($options['preset'] ?? 'veryfast')));
        if (!\in_array($preset, self::PRESETS, true)) {
            $preset = 'veryfast';
        }

        $outputPath = $this->resolveOutputPath($options);
        if ($outputPath === '') {
            return $this->failure(self::ERROR_RENDER_FAILED);
        }

        $segmentSeconds = $durationMs / 1000 / \count($images);
        $renderPath = $outputPath . '.part-' . \bin2hex(\random_bytes(4)) . '.mp4';
        $listPath = \tempnam(\sys_get_temp_dir(), 'weline-social-video-');
        if ($listPath === false) {
            return $this->failure(self::ERROR_RENDER_FAILED);
        }

        try {
            $lines = [];
            foreach ($images as $image) {
                $lines[] = $this->concatFileLine($image);
                $lines[] = 'duration ' . \number_format($segmentSeconds, 3, '.', '');
            }
            $lines[] = $this->concatFileLine($images[\count($images) - 1]);
            if (\file_put_contents($listPath, \implode("\n", $lines) . "\n") === false) {
                return $this->failure(self::ERROR_RENDER_FAILED);
            }

            $command = [
                $ffmpegBinary,
                '-hide_banner',
                '-loglevel',
                'error',
                '-nostdin',
                '-y',
                '-f',
                'concat',
                '-safe',
                '0',
                '-i',
                $listPath,
                '-i',
                $audioPath,
                '-vf',
                \sprintf(
                    'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,fps=%d',
                    $width,
                    $height,
                    $width,
                    $height,
                    $fps
                ),
                '-c:v',
                'libx264',
                '-preset',
                $preset,
                '-crf',
                (string)$crf,
                '-pix_fmt',
                'yuv420p',
                '-c:a',
                'aac',
                '-b:a',
                '128k',
                '-t',
                \number_format($durationMs / 1000, 3, '.', ''),
                '-movflags',
                '+faststart',
                '-f',
                'mp4',
                $renderPath,
            ];

            $result = $this->runProcess($command);
            if ($result === null
                || $result['exit_code'] !== 0
                || !\is_file($renderPath)
                || (int)\filesize($renderPath) <= 0) {
                return $this->failure(self::ERROR_RENDER_FAILED);
            }
            if (!\rename($renderPath, $outputPath)) {
                return $this->failure(self::ERROR_RENDER_FAILED);
            }

            return [
                'success' => true,
                'path' => $outputPath,
                'duration_ms' => $durationMs,
                'error' => '',
            ];
        } finally {
            $this->removeFile($renderPath);
            $this->removeFile($listPath);
        }
    }

    /**
     * @param array<int, mixed> $images
     * @return array<int, string>
     */
    private function normalizeImages(array $images): array
    {
        if ($images === []) {
            return [];
        }

        $normalized = [];
        foreach ($images as $image) {
            if (!\is_string($image)) {
                return [];
            }
            $image = \trim($image);
            if ($image === ''
                || \strpbrk($image, "\r\n") !== false
                || !$this->isAbsolutePath($image)
                || !$this->isReadableFile($image)) {
                return [];
            }
            $normalized[] = $image;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveOutputPath(array $options): string
    {
        $outputPath = $this->stringOption($options, 'output_path');
        if ($outputPath !== '') {
            if (!$this->isAbsolutePath($outputPath)) {
                $base = $this->basePath();
                if ($base === '') {
                    return '';
                }
                $outputPath = $base . \DIRECTORY_SEPARATOR . $outputPath;
            }
            if (!$this->ensureDirectory(\dirname($outputPath))) {
                return '';
            }

            return $outputPath;
        }

        $outputDir = $this->stringOption($options, 'output_dir');
        if ($outputDir === '') {
            $outputDir = 'var/social_media';
        }
        if (!$this->isAbsolutePath($outputDir)) {
            $base = $this->basePath();
            if ($base === '') {
                return '';
            }
            $outputDir = $base . \DIRECTORY_SEPARATOR . $outputDir;
        }
        $outputDir = \rtrim($outputDir, '/\\');
        if ($outputDir === '' || !$this->ensureDirectory($outputDir)) {
            return '';
        }

        return $outputDir . \DIRECTORY_SEPARATOR
            . 'weline-social-video-' . \bin2hex(\random_bytes(8)) . '.mp4';
    }

    private function defaultFfmpegBinary(): string
    {
        $env = \getenv('SOCIAL_FFMPEG_BIN');
        $binary = \is_string($env) ? \trim($env) : '';

        return $binary === '' ? 'ffmpeg' : $binary;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function optionFfmpegBinary(array $options): string
    {
        $binary = $this->stringOption($options, 'ffmpeg_bin');

        return $binary === '' ? $this->defaultFfmpegBinary() : $binary;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $key): string
    {
        $value = $options[$key] ?? null;

        return \is_string($value) ? \trim($value) : '';
    }

    private function basePath(): string
    {
        if (\defined('BP')) {
            return \rtrim((string)\constant('BP'), '/\\');
        }

        $current = \getcwd();

        return $current === false ? '' : \rtrim($current, '/\\');
    }

    private function ensureDirectory(string $directory): bool
    {
        if ($directory === '') {
            return false;
        }
        if (\is_dir($directory)) {
            return \is_writable($directory);
        }

        return \mkdir($directory, 0775, true) || \is_dir($directory);
    }

    /**
     * @return array{exit_code:int,output:string}|null
     */
    private function runProcess(array $command): ?array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = \proc_open($command, $descriptors, $pipes);
        if (!\is_resource($process)) {
            return null;
        }

        \fclose($pipes[0]);
        $stdout = (string)\stream_get_contents($pipes[1]);
        $stderr = (string)\stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exitCode = (int)\proc_close($process);

        return ['exit_code' => $exitCode, 'output' => $stderr !== '' ? $stderr : $stdout];
    }

    private function concatFileLine(string $path): string
    {
        return "file '" . \str_replace("'", "'\\''", $path) . "'";
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return \str_starts_with($path, \DIRECTORY_SEPARATOR)
            || \preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function isReadableFile(string $path): bool
    {
        return $path !== '' && \is_file($path) && \is_readable($path);
    }

    private function removeFile(string $path): void
    {
        if ($path !== '' && \is_file($path)) {
            \unlink($path);
        }
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return \max($min, \min($max, $value));
    }

    private function even(int $value): int
    {
        return $value % 2 === 0 ? $value : $value - 1;
    }

    /**
     * @return array{success:bool,path:string,duration_ms:int,error:string}
     */
    private function failure(string $error): array
    {
        return [
            'success' => false,
            'path' => '',
            'duration_ms' => 0,
            'error' => $error,
        ];
    }
}
