<?php
declare(strict_types=1);

namespace Weline\App\Service\Toolchain;

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
                : 'flutter linux: snap install flutter || tarball https://docs.flutter.dev/get-started/install/linux');
        }
        if (!$probe['java']['installed']) {
            $add('java', $os === 'macos'
                ? 'brew install --recursive temurin@17'
                : 'apt-get install -y openjdk-17-jdk');
        }
        if (!$probe['android_sdk']['installed']) {
            $add('android_sdk', $os === 'macos'
                ? 'brew install --cask android-commandlinetools && sdkmanager "platform-tools" "platforms;android-35" "emulator"'
                : 'sdkmanager "platform-tools" "platforms;android-35" "emulator"');
        }
        return ['os' => $os, 'steps' => $steps];
    }
}
