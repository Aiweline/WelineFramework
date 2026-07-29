<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Nginx\ManagedNginxService;

final class Promote extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('显式提升必须携带 --confirm，且只能由当前 80/443 的受管 owner 执行。'),
                $json,
                'confirmation_required',
            );
        }
        $package = \trim((string)($args['package'] ?? ''));
        if ($package === '') {
            return $this->failure(
                __('显式提升必须通过 --package 提供签名、自包含的 WLS 2.0 宿主包。'),
                $json,
                'package_required',
            );
        }
        $profile = \strtolower(\trim((string)($args['profile'] ?? 'default')));
        $legacy = ManagedNginxService::fromEnv();
        $snapshot = $legacy->doctorSnapshot();
        $paths = new GatewayPaths();
        $owner = \trim((string)($snapshot['owner_instance'] ?? ''));
        if (!($snapshot['running'] ?? false)
            || !($snapshot['runtime_owner_active'] ?? false)
            || $owner === ''
            || (int)($snapshot['listen_http'] ?? 0) !== $paths->publicHttpPort()
            || (int)($snapshot['listen_https'] ?? 0) !== $paths->publicHttpsPort()
        ) {
            return $this->failure(
                __('只有身份已验证、正在占用目标公共端口的项目托管 Nginx 才能提升。'),
                $json,
                'legacy_owner_not_eligible',
            );
        }
        $upstreamHost = (string)($snapshot['owner_upstream_host'] ?? '127.0.0.1');
        $upstreamPort = (int)($snapshot['owner_upstream_port'] ?? 0);
        $serverNames = (array)($snapshot['owner_server_names'] ?? []);
        $gateway = new GatewayHostManager();
        $builder = new GatewayRegistrationBuilder();
        $projectRoot = \realpath((string)BP);
        if (!\is_string($projectRoot) || $builder->desiredDomains() === []) {
            return $this->failure(
                __('当前项目缺少可迁移身份或域名/证书期望状态，旧 Nginx 保持不变。'),
                $json,
                'promotion_state_incomplete',
            );
        }
        $staged = null;
        $activated = false;
        $legacyStopped = false;
        $downtimeStarted = 0.0;
        try {
            // Package, Broker, locked runtimes, system definition and project
            // desired state are validated while the legacy owner keeps serving.
            $staged = $gateway->stageLegacyPromotion($package, $profile);
            $stopped = $legacy->stopForInstance($owner);
            if (!($stopped['ok'] ?? false)) {
                throw new \RuntimeException(
                    'Legacy Nginx did not release the public ports: '
                    . (string)($stopped['message'] ?? '')
                );
            }
            $legacyStopped = true;
            $downtimeStarted = \microtime(true);
            $gateway->activateLegacyPromotion($staged);
            $activated = true;
            $gateway->enrollCurrentProjectForPromotion($builder, $projectRoot);
            $registration = $gateway->register($owner);
            $maintenanceWindow = \microtime(true) - $downtimeStarted;
            if (!$json) {
                $this->printer->success(__('WLS 1.x 公共端口 owner 已显式提升为宿主级 WLS 2.0 Gateway。'));
                $this->printer->note(__('实测维护窗：%{1} 秒。', [
                    \number_format($maintenanceWindow, 3, '.', ''),
                ]));
            }
            $this->output([
                'owner_instance' => $owner,
                'maintenance_window_seconds' => \round($maintenanceWindow, 3),
                'registration' => $registration,
            ], $json);
            return 0;
        } catch (\Throwable $throwable) {
            $details = [
                'legacy_stopped' => $legacyStopped,
                'gateway_activated' => $activated,
                'legacy_rollback' => 'not_required',
            ];
            if (\is_array($staged)) {
                try {
                    $gateway->abortLegacyPromotion($staged, $activated);
                } catch (\Throwable $abort) {
                    $details['gateway_cleanup_error'] = $abort->getMessage();
                    if (!$json) {
                        $this->printer->error(
                            __('宿主网关清理失败：%{1}', [$abort->getMessage()])
                        );
                    }
                }
            }
            if (!$legacyStopped) {
                if (!$json) {
                    $this->printer->warning(__('旧项目 Nginx 未进入交接，仍保持原状。'));
                }
                return $this->failure(
                    __('提升失败：%{1}', [$throwable->getMessage()]),
                    $json,
                    'promotion_failed',
                    $details,
                );
            }
            $rollback = $legacy->prepareAndStart(
                $upstreamPort,
                $upstreamHost,
                $serverNames,
                $owner,
                'nginx',
            );
            if ($rollback['ok'] ?? false) {
                $details['legacy_rollback'] = 'restored';
                if (!$json) {
                    $this->printer->warning(__('已回滚并恢复原项目托管 Nginx。'));
                }
                if ($downtimeStarted > 0.0) {
                    $recoveryWindow = \microtime(true) - $downtimeStarted;
                    $details['recovery_window_seconds'] = \round($recoveryWindow, 3);
                    if (!$json) {
                        $this->printer->note(__('失败恢复窗：%{1} 秒。', [
                            \number_format($recoveryWindow, 3, '.', ''),
                        ]));
                    }
                }
            } else {
                $details['legacy_rollback'] = 'failed';
                $details['legacy_rollback_error'] = (string)($rollback['message'] ?? '');
                if (!$json) {
                    $this->printer->error(__('原项目 Nginx 回滚也失败：%{1}', [
                        (string)($rollback['message'] ?? ''),
                    ]));
                }
            }
            return $this->failure(
                __('提升失败：%{1}', [$throwable->getMessage()]),
                $json,
                'promotion_failed',
                $details,
            );
        }
    }

    public function tip(): string
    {
        return __('将当前 80/443 的 WLS 1.x 受管 owner 显式提升为 WLS 2.0 Gateway');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:promote --package=/absolute/package --confirm [--profile=default]',
            $this->tip(),
            [
                '--package' => __('签名、自包含的 WLS 2.0 宿主包目录'),
                '--profile' => __('default（IPv4+IPv6）或 ipv4-only'),
                '--confirm' => __('确认进入有实测维护窗的公共端口所有权切换'),
                '--json' => __('输出稳定 JSON 文档'),
            ],
            [__('回滚') => __('先在旧入口在线时完成影子预检；交接失败时先停宿主服务，再用冻结快照恢复旧 Nginx。')],
            [],
        );
    }
}
