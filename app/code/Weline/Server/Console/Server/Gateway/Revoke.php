<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Gateway\GatewayCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;

final class Revoke extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('撤销会使当前项目凭据和路由不可恢复；请提供 --confirm。'),
                $json,
                'confirmation_required',
            );
        }
        try {
            $response = $this->gateway()->request('revoke', [
                'project_uuid' => (new GatewayRegistrationBuilder())->projectUuid(),
            ]);
            if (!($response['ok'] ?? false)) {
                $error = (array)($response['error'] ?? []);
                return $this->failure(
                    (string)($error['message'] ?? __('撤销失败')),
                    $json,
                    (string)($error['code'] ?? 'revoke_failed'),
                    (array)($error['details'] ?? []),
                );
            }
            $payload = (array)($response['payload'] ?? []);
            $cleanupErrors = [];
            try {
                (new GatewayCredentialStore())->remove();
                $payload['credential_removed'] = true;
            } catch (\Throwable $throwable) {
                $cleanupErrors['credential'] = $throwable->getMessage();
                $payload['credential_removed'] = false;
            }
            if ($cleanupErrors !== []) {
                return $this->failure(
                    __('网关安全撤销已提交，但项目凭据清理不完整；修复权限后请重新执行 revoke。'),
                    $json,
                    'revoke_cleanup_incomplete',
                    [
                        'revocation_committed' => true,
                        'cleanup_errors' => $cleanupErrors,
                        ...$payload,
                    ],
                );
            }
            if (!$json) {
                $this->printer->success(__('当前项目路由和凭据已撤销；共享网关保持运行。'));
            }
            $this->output($payload, $json);
            return 0;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'revoke_failed');
        }
    }

    public function tip(): string
    {
        return __('撤销当前项目的网关路由与宿主凭据');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:revoke --confirm',
            $this->tip(),
            [
                '--confirm' => __('确认写入不可逆撤销状态并删除本项目宿主凭据'),
                '--json' => __('输出稳定 JSON 文档'),
            ],
            [],
            [],
        );
    }
}
