<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;

final class Doctor extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        try {
            $response = $this->gateway()->request('doctor');
            $ok = (bool)($response['ok'] ?? false);
            if (!$json) {
                $this->printer->setup(__('WLS 2.0 网关诊断'));
            }
            $this->output(
                (array)($response['payload'] ?? []),
                $json,
                $ok,
                (array)($response['error'] ?? ['message' => __('诊断失败。')]),
            );
            return $ok ? 0 : 1;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'doctor_failed');
        }
    }

    public function tip(): string
    {
        return __('诊断网关控制面、数据面、generation、LKG 与 A/B 槽');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp('server:gateway:doctor', $this->tip(), ['--json' => __('JSON 输出')], [], []);
    }
}
