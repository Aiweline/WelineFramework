<?php
declare(strict_types=1);

namespace Weline\App\Service\Toolchain;

class EnvProbe
{
    public function probe(): array
    {
        return [
            'os' => $this->probeOs(),
            'php' => PHP_VERSION,
            'flutter' => $this->probeFlutter(),
            'java' => $this->probeJava(),
            'android_sdk' => $this->probeAndroidSdk(),
            'xcode' => $this->probeXcode(),
            'keystore' => ['platform_managed' => true],
            'network' => $this->probeNetwork(),
        ];
    }

    private function probeOs(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            'Windows' => 'win',
            default => 'unknown',
        };
    }

    private function probeFlutter(): array
    {
        if (!function_exists('exec')) {
            return ['installed' => false, 'version' => null, 'path' => null, 'failed' => true, 'reason' => 'exec disabled'];
        }
        $path = null;
        $out = [];
        exec('which flutter', $out, $code);
        if ($code === 0 && $out) {
            $path = trim((string) $out[0]);
        }
        if (!$path) {
            return ['installed' => false, 'version' => null, 'path' => null];
        }
        $out = [];
        exec('flutter --version 2>/dev/null', $out, $code);
        if ($code !== 0 || !$out) {
            return ['installed' => true, 'version' => null, 'path' => $path, 'failed' => true, 'reason' => 'version probe failed'];
        }
        $version = null;
        foreach ($out as $line) {
            if (preg_match('/^Flutter\s+\d+\.\d+\.\d+/', trim($line))) {
                $version = trim($line);
                break;
            }
        }
        return ['installed' => true, 'version' => $version, 'path' => $path];
    }

    private function probeJava(): array
    {
        if (!function_exists('exec')) {
            return ['installed' => false, 'version' => null, 'failed' => true, 'reason' => 'exec disabled'];
        }
        $out = [];
        exec('java -version 2>&1', $out, $code);
        if ($code !== 0 || !$out) {
            return ['installed' => false, 'version' => null];
        }
        $first = trim((string) $out[0]);
        if (stripos($first, 'version') === false) {
            return ['installed' => false, 'version' => null, 'failed' => true, 'reason' => 'unexpected output: ' . $first];
        }
        return ['installed' => true, 'version' => $first];
    }

    private function probeAndroidSdk(): array
    {
        $candidates = array_filter([
            $_SERVER['ANDROID_HOME'] ?? getenv('ANDROID_HOME') ?: null,
            $_SERVER['ANDROID_SDK_ROOT'] ?? getenv('ANDROID_SDK_ROOT') ?: null,
            ($_SERVER['HOME'] ?? getenv('HOME') ?: null) ? ($_SERVER['HOME'] ?? getenv('HOME')) . '/Library/Android/sdk' : null,
        ]);
        foreach ($candidates as $path) {
            if (is_string($path) && is_dir($path)) {
                return ['installed' => true, 'path' => $path];
            }
        }
        return ['installed' => false, 'path' => null];
    }

    private function probeXcode(): array
    {
        if (!function_exists('exec')) {
            return ['installed' => is_dir('/Applications/Xcode.app'), 'full' => false, 'failed' => true, 'reason' => 'exec disabled'];
        }
        $out = [];
        exec('xcodebuild -version 2>&1', $out, $code);
        return ['installed' => $code === 0 || is_dir('/Applications/Xcode.app'), 'full' => $code === 0];
    }

    private function probeNetwork(): array
    {
        if (!function_exists('exec')) {
            return ['mirror' => 'unknown', 'failed' => true, 'reason' => 'exec disabled'];
        }
        $out = [];
        exec('curl -m 4 -sI https://pub.dev', $out, $code);
        if ($code === 0 && $out) {
            return ['mirror' => 'direct'];
        }
        $out = [];
        exec('curl -m 4 -sI https://storage.flutter-io.cn', $out, $code);
        if ($code === 0 && $out) {
            return ['mirror' => 'cn'];
        }
        return ['mirror' => 'unknown'];
    }
}
