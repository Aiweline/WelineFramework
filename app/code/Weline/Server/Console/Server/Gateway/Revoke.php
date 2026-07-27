<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;

final class Revoke extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        try {
            $response = $this->gateway()->request('revoke', [
                'project_uuid' => (new GatewayRegistrationBuilder())->projectUuid(),
            ]);
            if (!($response['ok'] ?? false)) {
                $this->printer->error((string)($response['error']['message'] ?? __('撤销失败')));
                return 1;
            }
            $this->printer->success(__('当前项目路由和宿主授权已撤销；共享网关保持运行。'));
            return 0;
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
            return 1;
        }
    }

    public function tip(): string
    {
        return __('撤销当前项目的网关路由与证书目录授权');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('server:gateway:revoke', $this->tip(), [], [], []);
    }
}
