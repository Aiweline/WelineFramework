<?php
declare(strict_types=1);

namespace Weline\App\Console\App;

use Weline\App\Service\Toolchain\EnvProbe;
use Weline\Framework\Console\CommandAbstract;

class Doctor extends CommandAbstract
{
    public const ALIASES = ['app:doctor'];

    public function execute(array $args = [], array $data = [])
    {
        $probe = (new EnvProbe())->probe();
        $format = (string)($args['format'] ?? '');
        if ($format === 'json') {
            echo json_encode($probe, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
            return 0;
        }
        $this->printer->note('OS：' . $probe['os'] . '（PHP ' . $probe['php'] . '）');
        $flutter = $probe['flutter'];
        $this->printer->{$flutter['installed'] ? 'success' : 'error'}(
            $flutter['installed']
                ? __('Flutter：已安装 %{1}', [$flutter['version'] ?? '版本未知'])
                : __('Flutter：未安装')
        );
        $this->printer->{$probe['java']['installed'] ? 'success' : 'error'}(
            $probe['java']['installed'] ? __('Java：已安装') : __('Java：未安装（Android 构建必需）')
        );
        $this->printer->{$probe['android_sdk']['installed'] ? 'success' : 'error'}(
            $probe['android_sdk']['installed']
                ? __('Android SDK：%{1}', [$probe['android_sdk']['path']])
                : __('Android SDK：未安装')
        );
        $xcode = $probe['xcode'];
        $this->printer->{$xcode['full'] ? 'success' : 'note'}(
            $xcode['full'] ? __('Xcode：完整') : __('Xcode：不完整')
        );
        $this->printer->note(__('签名：平台统一签名（本机无需 keystore）'));
        $this->printer->note(__('网络镜像：%{1}', [$probe['network']['mirror']]));
        $ready = 'none';
        if ($flutter['installed']) {
            $ready = 'web';
            if ($probe['java']['installed'] && $probe['android_sdk']['installed']) {
                $ready = 'android';
            }
        }
        $this->printer->{$ready !== 'none' ? 'success' : 'error'}(__('ready_for=%{1}', [$ready]));
        return 0;
    }

    public function tip(): string
    {
        return __('探测本机 App 编译环境（Flutter/JDK/Android SDK/Xcode/网络镜像）');
    }
}
