<?php

declare(strict_types=1);

/**
 * Write managed local WLS domains into the local hosts file when required.
 * `.weline.test` needs a hosts entry during local/dev/test usage, while
 * `.weline.localhost` resolves to loopback automatically and is skipped.
 */

namespace Weline\Server\Console\Server\Hosts;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Server\Service\HostsFileManager;
use Weline\Server\Service\LocalDomainPolicy;

final class Add extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        $envType = (string) Env::getInstance()->getConfig('system.env', 'local');
        if (!\in_array($envType, ['local', 'dev', 'test'], true)) {
            $this->printer->error(__('当前 system.env=%{1} 禁止执行 hosts 写入', [$envType]));
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
            $this->printer->error(__('请指定域名，例如：php bin/w server:hosts:add shop123.weline.test'));
            $this->printer->note(__('可选：--ip=127.0.0.1（默认）'));
            return;
        }

        if (LocalDomainPolicy::resolvesViaLoopbackSuffix($domain)) {
            $this->printer->note(__('域名 %{1} 使用 .localhost 回环后缀，无需写入 hosts', [$domain]));
            return;
        }

        if (!self::isEligibleLocalHostname($domain)) {
            $this->printer->error(__('仅允许写入单标签的 *.weline.test 本地域名；*.weline.localhost 无需 hosts'));
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
        return LocalDomainPolicy::requiresHostsEntry($domain)
            && LocalDomainPolicy::isManagedSingleLabelSubdomain($domain);
    }

    private static function isValidIp(string $ip): bool
    {
        return \filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public function tip(): string
    {
        return __('向本机 hosts 添加需要显式解析的本地域名（当前仅 *.weline.test）');
    }

    public function help(): array|string
    {
        return [
            __('用法') => 'php bin/w server:hosts:add <域名> [--ip=127.0.0.1]',
            __('示例') => 'php bin/w server:hosts:add myshop.weline.test',
            __('说明') => __('与 server:start 使用同一套 HostsFileManager；仅 system.env 为 local/dev/test 时可用'),
        ];
    }
}
