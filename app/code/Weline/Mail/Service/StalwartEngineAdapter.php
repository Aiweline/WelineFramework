<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

class StalwartEngineAdapter implements MailEngineInterface
{
    private const LINUX_INSTALL_DIR = '/opt/stalwart';
    private const WINDOWS_INSTALL_DIR = 'C:\\Program Files\\Stalwart';

    public function getName(): string
    {
        return 'stalwart';
    }

    public function buildInstallPlan(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'platform' => 'windows',
                'install_dir' => self::WINDOWS_INSTALL_DIR,
                'steps' => [
                    __('下载 stalwart-x86_64-pc-windows-msvc.zip'),
                    __('解压 stalwart.exe 到 C:\\Program Files\\Stalwart\\bin'),
                    __('安装 NSSM 并注册 Stalwart Windows 服务'),
                    __('生成 etc\\config.json 并设置服务自启动'),
                    __('启动服务后读取 8080/admin 初始化状态'),
                ],
            ];
        }

        return [
            'platform' => strtolower(PHP_OS_FAMILY),
            'install_dir' => self::LINUX_INSTALL_DIR,
            'steps' => [
                __('下载 Stalwart Linux 二进制'),
                __('创建 /opt/stalwart/{bin,etc,data,logs}'),
                __('生成 Stalwart 配置文件'),
                __('注册 systemd 服务'),
                __('启动服务并检测 SMTP/IMAP/Admin 端口'),
            ],
        ];
    }

    public function checkEnvironment(): array
    {
        $checks = [
            $this->checkCommand($this->binaryName(), __('Stalwart 可执行文件')),
            $this->checkPort(25, __('SMTP 25 端口')),
            $this->checkPort(587, __('SMTP Submission 587 端口')),
            $this->checkPort(143, __('IMAP 143 端口')),
            $this->checkPort(993, __('IMAPS 993 端口')),
            $this->checkPort(8080, __('Stalwart Admin 8080 端口')),
        ];

        if (PHP_OS_FAMILY === 'Windows') {
            array_unshift($checks, $this->checkCommand('nssm', __('NSSM Windows 服务包装器')));
        } else {
            array_unshift($checks, $this->checkCommand('systemctl', __('systemd 服务管理')));
        }

        return [
            'engine' => $this->getName(),
            'platform' => PHP_OS_FAMILY,
            'ok' => count(array_filter($checks, static fn(array $check): bool => !$check['ok'])) === 0,
            'checks' => $checks,
            'plan' => $this->buildInstallPlan(),
        ];
    }

    public function install(bool $yes = false): array
    {
        if (!$yes) {
            return [
                'ok' => false,
                'dry_run' => true,
                'message' => __('未执行真实安装。确认无误后运行：php bin/w mail:env:install -y'),
                'plan' => $this->buildInstallPlan(),
            ];
        }

        $script = PHP_OS_FAMILY === 'Windows'
            ? BP . 'app/code/Weline/Mail/env/script/install_stalwart_windows.ps1'
            : BP . 'app/code/Weline/Mail/env/script/install_stalwart_linux.sh';

        if (!is_file($script)) {
            return [
                'ok' => false,
                'message' => __('安装脚本不存在：%{1}', [$script]),
            ];
        }

        return [
            'ok' => false,
            'message' => __('真实安装脚本已准备，但为避免误改系统服务，请先通过 env:install stalwart-mail-server -y 执行框架依赖安装入口。'),
            'script' => $script,
            'plan' => $this->buildInstallPlan(),
        ];
    }

    public function service(string $action): array
    {
        $allowed = ['start', 'stop', 'restart', 'status'];
        if (!in_array($action, $allowed, true)) {
            return ['ok' => false, 'message' => __('不支持的服务动作：%{1}', [$action])];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = match ($action) {
                'start' => 'powershell -NoProfile -Command "Start-Service Stalwart"',
                'stop' => 'powershell -NoProfile -Command "Stop-Service Stalwart"',
                'restart' => 'powershell -NoProfile -Command "Restart-Service Stalwart"',
                default => 'powershell -NoProfile -Command "Get-Service Stalwart"',
            };
        } else {
            $cmd = 'systemctl ' . escapeshellarg($action) . ' stalwart';
        }

        return $this->runCommand($cmd);
    }

    public function clientSettings(string $domain, string $hostname): array
    {
        return [
            'domain' => $domain,
            'hostname' => $hostname,
            'smtp' => [
                'host' => $hostname,
                'port' => 587,
                'security' => 'STARTTLS',
                'auth' => true,
            ],
            'imap' => [
                'host' => $hostname,
                'port' => 993,
                'security' => 'TLS',
                'auth' => true,
            ],
            'pop3' => [
                'host' => $hostname,
                'port' => 995,
                'security' => 'TLS',
                'auth' => true,
            ],
        ];
    }

    private function binaryName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'stalwart.exe' : 'stalwart';
    }

    private function checkCommand(string $command, string $label): array
    {
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($command)
            : 'command -v ' . escapeshellarg($command);
        $result = $this->runCommand($cmd);

        return [
            'name' => $label,
            'ok' => $result['ok'],
            'detail' => $result['output'] ?: $result['error'],
        ];
    }

    private function checkPort(int $port, string $label): array
    {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1.0);
        if (is_resource($connection)) {
            fclose($connection);
            return ['name' => $label, 'ok' => true, 'detail' => __('127.0.0.1:%{1} 可连接', [$port])];
        }

        return ['name' => $label, 'ok' => false, 'detail' => trim($errstr . ' #' . $errno)];
    }

    private function runCommand(string $command): array
    {
        $output = [];
        @exec($command . ' 2>&1', $output, $code);

        return [
            'ok' => $code === 0,
            'exit_code' => $code,
            'command' => $command,
            'output' => trim(implode("\n", $output)),
            'error' => $code === 0 ? '' : trim(implode("\n", $output)),
        ];
    }
}
