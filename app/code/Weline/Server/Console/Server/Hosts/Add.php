<?php

declare(strict_types=1);

/**
 * 将开发用 .local 域名写入本机 hosts（与 server:start 内自动配置同源：HostsFileManager）。
 * AI 建站 / E2E 计划中的 w_query：首版以此命令为正式入口（另可与 Playwright globalSetup 双轨）。
 */

namespace Weline\Server\Console\Server\Hosts;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Server\Service\HostsFileManager;

/**
 * server:hosts:add — 添加 .local 域名到本机 hosts
 */
class Add extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        $envType = (string) Env::getInstance()->getConfig('system.env', 'local');
        if (!\in_array($envType, ['local', 'dev', 'test'], true)) {
            $this->printer->error(__('当前 system.env=%{1} 下禁止执行 hosts 写入', [$envType]));
            return;
        }

        $ip = \trim((string) ($args['ip'] ?? '127.0.0.1'));
        if ($ip === '' || !self::isValidIp($ip)) {
            $this->printer->error(__('无效的 IP：%{1}', [$ip]));
            return;
        }

        $positional = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string) $arg, '-')) {
                $positional[] = (string) $arg;
            }
        }
        \array_shift($positional);

        $domain = \trim((string) ($args['domain'] ?? $positional[0] ?? ''));
        if ($domain === '') {
            $this->printer->error(__('请指定域名，例如：php bin/w server:hosts:add shop123.weline.local'));
            $this->printer->note(__('可选：--ip=127.0.0.1（默认）'));
            return;
        }

        if (!self::isEligibleLocalHostname($domain)) {
            $this->printer->error(__('仅允许以 .local 结尾且非 localhost 的域名（与 WLS server:start 规则一致）'));
            return;
        }

        $result = HostsFileManager::addDomain($domain, $ip);
        if ($result['success']) {
            if (($result['already_exists'] ?? false) === true) {
                $this->printer->note($result['message']);
            } else {
                $this->printer->success($result['message']);
            }
            return;
        }
        if ($result['needs_admin'] ?? false) {
            $this->printer->warning($result['message'] ?? __('需要提升权限'));
            if (!empty($result['command'])) {
                $this->printer->note((string) $result['command']);
            }
            return;
        }
        $this->printer->error($result['message'] ?? __('写入 hosts 失败'));
    }

    public static function isEligibleLocalHostname(string $domain): bool
    {
        $domain = \trim(\strtolower($domain));
        if ($domain === '' || $domain === 'localhost') {
            return false;
        }
        if (!\str_ends_with($domain, '.local')) {
            return false;
        }
        if (\strlen($domain) > 253) {
            return false;
        }
        return (bool) \preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain);
    }

    private static function isValidIp(string $ip): bool
    {
        return \filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public function tip(): string
    {
        return __('向本机 hosts 添加 .local 域名（开发/测试；计划 w_query 入口）');
    }

    public function help(): array|string
    {
        return [
            __('用法') => 'php bin/w server:hosts:add <域名> [--ip=127.0.0.1]',
            __('示例') => 'php bin/w server:hosts:add myshop.weline.local',
            __('说明') => __('与 server:start 使用的 HostsFileManager 相同；仅 system.env 为 local/dev/test 时可用'),
        ];
    }
}
