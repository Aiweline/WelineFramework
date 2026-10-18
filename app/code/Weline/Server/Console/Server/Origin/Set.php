<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Origin;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Console\Server\Stop as ServerStop;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

/**
 * Switch managed-Nginx origin between WLS Workers (proxy) and PHP-FPM (fastcgi).
 *
 * FPM mode does not require Workers / Session / Memory sidecars.
 */
final class Set extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $positional = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key)) {
                $positional[] = (string)$arg;
            }
        }
        // argv[0] is the command name (server:origin:set).
        \array_shift($positional);
        $mode = \strtolower(\trim((string)($args['mode'] ?? $positional[0] ?? '')));
        if ($mode === '') {
            $this->printer->error(__('请指定 origin 模式：fpm 或 wls'));
            $this->printer->note(__('用法：php bin/w server:origin:set fpm|wls [--fpm-listen=HOST:PORT|unix:/path]'));

            return 1;
        }

        $fpmListen = null;
        foreach (['fpm-listen', 'fpm_listen', 'listen'] as $key) {
            if (isset($args[$key]) && \trim((string)$args[$key]) !== '') {
                $fpmListen = \trim((string)$args[$key]);
                break;
            }
        }

        $this->printer->setup(__('切换托管 Nginx origin_mode=%{1}', [$mode]));
        $service = ManagedNginxService::fromEnv();
        $result = $service->applyOriginMode($mode, $fpmListen);
        if (!($result['ok'] ?? false)) {
            $this->printer->error((string)($result['message'] ?? __('切换失败')));
            $details = (array)($result['details'] ?? []);
            foreach ($details as $key => $value) {
                if (\is_scalar($value) || $value === null) {
                    $this->printer->note((string)$key . ': ' . \var_export($value, true));
                }
            }

            return 1;
        }

        $this->printer->success((string)($result['message'] ?? __('已切换')));
        $details = (array)($result['details'] ?? []);
        if (isset($details['origin_mode'])) {
            $this->printer->note(__('origin_mode：%{1}', [(string)$details['origin_mode']]));
        }
        if (!empty($details['fpm_listen'])) {
            $this->printer->note(__('fpm_listen：%{1}', [(string)$details['fpm_listen']]));
        }
        if (\array_key_exists('nginx_running', $details)) {
            $this->printer->note(__('托管 Nginx 运行中：%{1}', [
                !empty($details['nginx_running']) ? __('是') : __('否'),
            ]));
        }
        $originMode = (string)($details['origin_mode'] ?? '');
        if ($originMode === 'fpm') {
            $this->printer->note(__(
                'FPM 模式不需要 WLS Worker / Session / Memory 侧车；店面由托管 Nginx → php-fpm → pub/index.php 提供。'
            ));
            $this->stopBusinessPlaneKeepingNginx();
        } elseif ($originMode === 'wls') {
            $this->printer->note(__(
                '已切回 WLS 回源：请执行 php bin/w server:start 拉起 Worker（及既有侧车），本命令不会自动 start。'
            ));
        }

        return 0;
    }

    /**
     * Tear down WLS Master/Workers/sidecars while leaving managed Nginx up for FPM edge.
     * Failures are warnings only — origin_mode is already persisted.
     */
    private function stopBusinessPlaneKeepingNginx(): void
    {
        try {
            /** @var ServerStop $stop */
            $stop = ObjectManager::getInstance(ServerStop::class);
            $exit = $stop->execute([
                'keep-nginx' => true,
            ]);
            if (\is_int($exit) && $exit !== 0) {
                $this->printer->warning(__(
                    'origin_mode=fpm 已生效，但停止 WLS 业务平面返回非零（%{1}）；请检查 server:status，必要时手动 server:stop --keep-nginx。',
                    [(string)$exit]
                ));
            } else {
                $this->printer->note(__('已停止 WLS 业务平面并保留托管 Nginx（--keep-nginx）。'));
            }
        } catch (\Throwable $e) {
            $this->printer->warning(__(
                'origin_mode=fpm 已生效，但停止 WLS 业务平面失败：%{1}。请手动执行 php bin/w server:stop --keep-nginx。',
                [$e->getMessage()]
            ));
        }
    }

    public function tip(): string
    {
        return __('切换托管 Nginx 回源：WLS Workers（wls）或 PHP-FPM（fpm）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:origin:set',
            __('将 wls.edge.nginx.origin_mode 钉为 fpm 或 wls，并在托管 Nginx 运行时重写 conf + reload'),
            [
                'fpm|wls' => __('回源模式（必填位置参数）'),
                '--fpm-listen=…' => __('可选；tcp 如 127.0.0.1:9000，或 unix 套接字绝对路径'),
            ],
            [
                __('FPM') => __('托管 Nginx fastcgi → pub/index.php；成功后自动 server:stop --keep-nginx；不写系统 /etc nginx'),
                __('WLS') => __('切回 proxy_pass wls_backend；需再手动 server:start 拉起 Worker（及既有侧车）'),
                __('探活') => __('切到 fpm 时必须能连通 php-fpm listen，否则拒绝并保持可回滚语义（配置已写入则需再 set wls）'),
            ],
            [
                __('切 FPM') => 'php bin/w server:origin:set fpm',
                __('指定 listen') => 'php bin/w server:origin:set fpm --fpm-listen=127.0.0.1:9000',
                __('切回 WLS') => 'php bin/w server:origin:set wls',
            ]
        );
    }
}
