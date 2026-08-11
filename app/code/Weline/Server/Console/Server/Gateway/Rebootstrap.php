<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

/**
 * Explicit, administrator-driven whole-generation launcher maintenance.
 */
final class Rebootstrap extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('整代重引导会持久停止共享入口；必须显式携带 --confirm。'),
                $json,
                'confirmation_required',
            );
        }
        $package = \trim((string)($args['package'] ?? ''));
        if ($package === '' || !$this->isAbsolutePath($package)) {
            return $this->failure(
                __('必须通过 --package 指定签名自包含候选包的绝对目录。'),
                $json,
                'absolute_package_required',
            );
        }
        $nonce = \strtolower(\trim((string)($args['nonce'] ?? '')));
        if (\preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            return $this->failure(
                __('必须通过 --nonce 提供 32 位小写十六进制维护事务标识；崩溃恢复时重用同一值。'),
                $json,
                'nonce_required',
            );
        }
        $profile = \strtolower(\trim((string)($args['profile'] ?? 'default')));
        try {
            $result = $this->gateway()->rebootstrap(
                $package,
                $profile,
                $nonce,
            );
            if (!\hash_equals('COMMITTED', (string)($result['phase'] ?? ''))) {
                return $this->failure(
                    __('WLS 2.0 Gateway 整代重引导失败，已恢复并重新启用身份验证通过的旧代；请诊断候选包。'),
                    $json,
                    'gateway_rebootstrap_rolled_back',
                    ['transaction' => $result],
                );
            }
            if (!$json) {
                $this->printer->success(
                    ($result['gateway_epoch_preserved'] ?? false) === true
                        ? __('WLS 2.0 Gateway 整代重引导已完成，原 gateway epoch 保持不变。')
                        : __('WLS 2.0 Gateway 整代重引导已完成，CA 信任代与 gateway epoch 已轮换。')
                );
            }
            $this->output($result, $json);
            return 0;
        } catch (\Throwable $throwable) {
            return $this->failure(
                $throwable->getMessage(),
                $json,
                'gateway_rebootstrap_failed',
            );
        }
    }

    public function tip(): string
    {
        return __('显式替换 WLS 2.0 Gateway 稳定 Launcher、CA 信任与完整 A/B 代');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:rebootstrap --package=/absolute/package --nonce=<32hex> --confirm [--profile=default]',
            $this->tip(),
            [
                '--package' => __('签名自包含候选包绝对目录'),
                '--nonce' => __('32 位小写十六进制事务标识；恢复时必须复用'),
                '--profile' => __('default（IPv4+IPv6）或 ipv4-only'),
                '--confirm' => __('确认进入持久停止的整代维护事务'),
                '--json' => __('JSON 输出'),
            ],
            [
                __('恢复') => __('进程或宿主异常后，由管理员使用同一 package、nonce 与 profile 重跑本命令；事务会从已认证 journal 幂等恢复。'),
                __('失败') => __('停止承诺后任一步失败都会先撤销候选启动权限，再整代恢复旧运行时、平台定义与旧 gateway epoch；只有旧代通过身份校验并连续健康 15 秒才重新开放。回滚恢复本身失败时保持 ADMIN_STOPPED。'),
            ],
            [],
        );
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '' || \str_contains($path, "\0")) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            return \preg_match('/\A(?:[A-Za-z]:[\\\\\/]|\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+)/D', $path) === 1;
        }
        return \str_starts_with($path, DIRECTORY_SEPARATOR);
    }
}
