<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway\Package;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Console\Server\Gateway\AbstractGatewayCommand;
use Weline\Server\Service\Edge\Gateway\GatewayProjectReleasePackageFetchConfig;
use Weline\Server\Service\Edge\Gateway\GatewayProjectReleasePackageFetcher;

/**
 * Explicitly fetch a signed project gateway release package from CDN.
 *
 * Does not install the host gateway. Use server:gateway:install afterward when
 * administrator confirmation and host privileges are required.
 */
final class Fetch extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        $force = isset($args['force']) || isset($args['f']);
        $target = \trim((string)($args['target'] ?? ''));
        $config = GatewayProjectReleasePackageFetchConfig::fromEnv();
        if (!$config->isFetchConfigured()) {
            return $this->failure(
                __('请先配置 wls.edge.gateway.package_base_url 与 package_fetch_hosts。'),
                $json,
                'fetch_disabled',
            );
        }

        $fetcher = new GatewayProjectReleasePackageFetcher($config);
        $trust = $fetcher->probeTrust();
        if (($trust['ok'] ?? false) !== true) {
            return $this->failure(
                (string)($trust['reason'] ?? __('未注入启用的 Gateway 信任公钥。')),
                $json,
                'trust_unavailable',
                ['enabled_keys' => (int)($trust['enabled_keys'] ?? 0)],
            );
        }

        $deadline = (\hrtime(true) / 1_000_000_000) + $config->timeoutSec;
        $result = $fetcher->fetch(
            $target !== '' ? $target : null,
            $force,
            $deadline,
        );
        $ok = ($result['ok'] ?? false) === true;
        $this->output(
            [
                'state' => (string)($result['state'] ?? ''),
                'reason' => (string)($result['reason'] ?? ''),
                'path' => (string)($result['path'] ?? ''),
                'target_profile' => (string)($result['target_profile'] ?? ''),
                'enabled_keys' => (int)($result['enabled_keys'] ?? 0),
                'base_url' => $config->baseUrl,
            ],
            $json,
            $ok,
            [
                'code' => \strtolower((string)($result['state'] ?? 'fetch_failed')),
                'message' => (string)($result['reason'] ?? __('Gateway 包拉取失败。')),
            ],
        );
        if (!$ok) {
            if (!$json) {
                $this->printer->error((string)($result['reason'] ?? __('Gateway 包拉取失败。')));
            }
            return 1;
        }
        if (!$json) {
            $this->printer->success(__('已拉取并验签 Gateway 项目发行包。宿主安装请使用 server:gateway:install --package=... --confirm。'));
        }
        return 0;
    }

    public function tip(): string
    {
        return __('从配置的 HTTPS CDN 拉取并验签 WLS 2.0 Gateway 项目发行包');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:package:fetch [--target=darwin-arm64] [--force] [--json]',
            $this->tip() . ' ' . __('不安装宿主网关；空信任库或未配置 CDN 时 fail closed。'),
            [
                '--target' => __('平台目标，默认当前主机 profile'),
                '--force|-f' => __('验签通过后替换已有本地包树'),
                '--json' => __('JSON 输出'),
            ],
        );
    }
}
