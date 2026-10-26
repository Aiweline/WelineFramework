<?php
declare(strict_types=1);

namespace Weline\App\Service\Toolchain;

/**
 * App 构建依赖探测与安装计划（JDK / Android SDK / Flutter）。
 *
 * 规则：`plan()` 只给可执行命令（dry-run 展示），`install()` 才真正执行；
 * 安装后必须重新 probe 并回报，调用方据此决定能否继续打包。
 */
class DepInstaller
{
    public function plan(string $os = 'auto'): array
    {
        if ($os === 'auto') {
            $os = match (PHP_OS_FAMILY) {
                'Darwin' => 'macos',
                'Linux' => 'linux',
                'Windows' => 'windows',
                default => 'unknown',
            };
        }
        $probe = (new EnvProbe())->probe();
        $steps = [];
        $add = static function (string $target, string $command) use (&$steps) {
            $steps[] = ['target' => $target, 'command' => $command, 'done' => false];
        };
        if (!$probe['flutter']['installed']) {
            $add('flutter', $os === 'macos'
                ? 'brew install --cask flutter'
                : 'snap install flutter || echo "install flutter from https://docs.flutter.dev/get-started/install/linux"');
        }
        if (!$probe['java']['installed']) {
            $add('java', $os === 'macos'
                ? 'brew install openjdk@17'
                : 'apt-get install -y openjdk-17-jdk');
        }
        if (empty($probe['cocoapods']['installed'])) {
            $add('cocoapods', $os === 'macos'
                ? 'brew install cocoapods'
                : 'gem install cocoapods');
        }
        if (!$probe['android_sdk']['installed']) {
            $sdkmanager = self::sdkmanagerPath();
            if ($os === 'macos' && !is_dir(self::sdkRoot())) {
                $add('android_sdk', 'brew install --cask android-commandlinetools');
            }
            $add('android_licenses', 'yes | ' . escapeshellarg($sdkmanager) . ' --licenses >/dev/null 2>&1');
            $add('android_packages', escapeshellarg($sdkmanager)
                . ' "platform-tools" "platforms;android-35" "build-tools;35.0.0"');
            $sdkRoot = self::sdkRoot();
            if ($sdkRoot !== '') {
                $add('flutter_android_sdk', 'flutter config --android-sdk ' . escapeshellarg($sdkRoot));
            }
        }

        return ['os' => $os, 'steps' => $steps, 'ready' => $this->readyFor('android')];
    }

    /**
     * 按平台判断工具链缺失项（只读，不改环境）。
     *
     * @return list<string> 缺失项（空数组 = 就绪）
     */
    public function missingFor(string $platform, ?array $probe = null): array
    {
        $probe ??= (new EnvProbe())->probe();
        $needs = match ($platform) {
            'android' => ['flutter', 'java', 'android_sdk'],
            'ios' => ['flutter', 'cocoapods'],
            'web' => ['flutter'],
            default => [],
        };
        $missing = [];
        foreach ($needs as $need) {
            if (empty($probe[$need]['installed'])) {
                $missing[] = $need;
            }
        }

        return $missing;
    }

    public function readyFor(string $platform): bool
    {
        return $this->missingFor($platform) === [];
    }

    /**
     * 真正执行缺失依赖的安装，返回每个步骤的结果 + 安装后的探测快照。
     *
     * @return array{ok:bool, steps:list<array{target:string,command:string,exit:int,output:string}>, probe:array, missing:list<string>}
     */
    public function install(string $os = 'auto', ?callable $logger = null): array
    {
        // 每装完一项重新算 plan：装完 java 就不再重复判断 SDK，避免空转
        $executed = [];
        $guard = 0;
        while ($guard++ < 8) {
            $plan = $this->plan($os);
            if ($plan['steps'] === []) {
                break;
            }
            $step = $plan['steps'][0];
            // 同一目标连续出现 = 安装没有推进（模拟钩子/装失败），直接停，避免空转刷命令
            $lastTarget = $executed === [] ? '' : $executed[array_key_last($executed)]['target'];
            if ($step['target'] === $lastTarget) {
                break;
            }
            if ($logger !== null) {
                $logger(__('安装缺失依赖：%{1} → %{2}', [$step['target'], $step['command']]));
            }
            $output = [];
            $exit = 0;
            exec($step['command'] . ' 2>&1', $output, $exit);
            $executed[] = [
                'target' => $step['target'],
                'command' => $step['command'],
                'exit' => $exit,
                'output' => substr(implode("\n", $output), -2000),
            ];
            if ($exit !== 0) {
                break;
            }
        }
        $probe = (new EnvProbe())->probe();
        $ok = $executed !== [];
        foreach ($executed as $step) {
            if ($step['exit'] !== 0) {
                $ok = false;
                break;
            }
        }

        return [
            'ok' => $ok,
            'steps' => $executed,
            'probe' => $probe,
            'missing' => $this->missingFor('android', $probe),
        ];
    }

    /**
     * SDK 根目录（安装后定位；找不到返回空串）。
     */
    public static function sdkRoot(): string
    {
        $home = (string)(getenv('HOME') ?: '');
        $candidates = array_filter([
            (string)(getenv('ANDROID_HOME') ?: ''),
            (string)(getenv('ANDROID_SDK_ROOT') ?: ''),
            $home !== '' ? $home . '/Library/Android/sdk' : '',
            '/opt/homebrew/share/android-commandlinetools',
            '/usr/local/share/android-commandlinetools',
        ]);
        foreach ($candidates as $path) {
            if (is_dir($path . '/cmdline-tools/latest/bin')) {
                return rtrim((string)$path, '/');
            }
        }

        return '';
    }

    public static function sdkmanagerPath(): string
    {
        $root = self::sdkRoot();
        if ($root !== '' && is_file($root . '/cmdline-tools/latest/bin/sdkmanager')) {
            return $root . '/cmdline-tools/latest/bin/sdkmanager';
        }

        return '/opt/homebrew/share/android-commandlinetools/cmdline-tools/latest/bin/sdkmanager';
    }
}
