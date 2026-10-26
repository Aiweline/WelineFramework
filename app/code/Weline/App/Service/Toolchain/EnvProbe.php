<?php
declare(strict_types=1);

namespace Weline\App\Service\Toolchain;

class EnvProbe
{
    public function probe(): array
    {
        $probe = [
            'os' => $this->probeOs(),
            'php' => PHP_VERSION,
            'flutter' => $this->probeFlutter(),
            'java' => $this->probeJava(),
            'android_sdk' => $this->probeAndroidSdk(),
            'cocoapods' => $this->probeCocoapods(),
            'xcode' => $this->probeXcode(),
            'keystore' => ['platform_managed' => true],
            'network' => $this->probeNetwork(),
        ];

        // 调试/验收钩子：模拟某几项缺失（不卸载真实环境），用来验证「检测→自动安装」链路
        $forced = array_filter(array_map('trim', explode(',', (string)(getenv('WELINE_APP_TOOLCHAIN_MISSING') ?: ''))));
        foreach ($forced as $key) {
            $map = ['java' => 'java', 'android_sdk' => 'android_sdk', 'android' => 'android_sdk', 'flutter' => 'flutter', 'cocoapods' => 'cocoapods', 'ios' => 'cocoapods'];
            $name = $map[$key] ?? '';
            if ($name !== '' && isset($probe[$name])) {
                $probe[$name] = ['installed' => false, 'simulated' => true] + $probe[$name];
            }
        }

        return $probe;
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
        // brew 装的 openjdk 是 keg-only，不 link 到 PATH；要认 JAVA_HOME / keg 目录，否则误报「未安装」
        $homes = array_filter([
            (string)(getenv('JAVA_HOME') ?: ''),
            $this->brewPrefix('openjdk@17'),
            $this->brewPrefix('openjdk'),
            $this->macJavaHome(),
            '/opt/homebrew/opt/openjdk@17',
            '/usr/local/opt/openjdk@17',
        ]);
        foreach ($homes as $home) {
            $bin = rtrim((string)$home, '/') . '/bin/java';
            if (is_file($bin) && is_executable($bin)) {
                $out = [];
                exec(escapeshellarg($bin) . ' -version 2>&1', $out, $code);
                $first = trim((string)($out[0] ?? ''));
                if ($code === 0 && $first !== '') {
                    return ['installed' => true, 'version' => $first, 'home' => rtrim((string)$home, '/'), 'path' => $bin];
                }
            }
        }
        $out = [];
        exec('java -version 2>&1', $out, $code);
        if ($code !== 0 || !$out) {
            return ['installed' => false, 'version' => null, 'home' => null, 'path' => null];
        }
        $first = trim((string) $out[0]);
        if (stripos($first, 'version') === false) {
            return ['installed' => false, 'version' => null, 'failed' => true, 'reason' => 'unexpected output: ' . $first];
        }
        return ['installed' => true, 'version' => $first, 'home' => getenv('JAVA_HOME') ?: null, 'path' => null];
    }

    private function brewPrefix(string $formula): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !function_exists('exec')) {
            return null;
        }
        $out = [];
        exec('brew --prefix ' . escapeshellarg($formula) . ' 2>/dev/null', $out, $code);
        $path = trim((string)($out[0] ?? ''));

        return $code === 0 && $path !== '' && is_dir($path) ? $path : null;
    }

    private function macJavaHome(): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !function_exists('exec')) {
            return null;
        }
        $out = [];
        exec('/usr/libexec/java_home 2>/dev/null', $out, $code);
        $path = trim((string)($out[0] ?? ''));

        return $code === 0 && $path !== '' && is_dir($path) ? $path : null;
    }

    private function probeAndroidSdk(): array
    {
        $home = (string)(getenv('HOME') ?: '');
        $candidates = array_filter([
            $_SERVER['ANDROID_HOME'] ?? getenv('ANDROID_HOME') ?: null,
            $_SERVER['ANDROID_SDK_ROOT'] ?? getenv('ANDROID_SDK_ROOT') ?: null,
            $home !== '' ? $home . '/Library/Android/sdk' : null,
            // Homebrew cask android-commandlinetools 的默认根
            $this->brewPrefix('android-commandlinetools'),
            '/opt/homebrew/share/android-commandlinetools',
            '/usr/local/share/android-commandlinetools',
        ]);
        foreach ($candidates as $path) {
            if (!is_string($path) || !is_dir($path)) {
                continue;
            }
            $hasPlatformTools = is_dir($path . '/platform-tools') || is_dir($path . '/platforms');
            $licenseOk = is_file($path . '/licenses/android-sdk-license');
            if ($hasPlatformTools && $licenseOk) {
                return ['installed' => true, 'path' => $path, 'licenses' => true];
            }
            if ($hasPlatformTools) {
                return ['installed' => false, 'path' => $path, 'licenses' => false, 'reason' => 'licenses 未接受'];
            }
        }
        return ['installed' => false, 'path' => null];
    }

    private function probeCocoapods(): array
    {
        if (!function_exists('exec')) {
            return ['installed' => false, 'version' => null, 'failed' => true, 'reason' => 'exec disabled'];
        }
        $out = [];
        exec('pod --version 2>/dev/null', $out, $code);
        $version = trim((string)($out[0] ?? ''));
        if ($code === 0 && $version !== '') {
            return ['installed' => true, 'version' => $version];
        }

        return ['installed' => false, 'version' => null];
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
