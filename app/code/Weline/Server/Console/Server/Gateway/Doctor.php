<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

final class Doctor extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        try {
            $atomicWrite = GatewayProjectStateFilesystem::atomicWriteRuntimeCapability();
            $response = $this->gateway()->request('doctor');
            $ok = (bool)($response['ok'] ?? false)
                && ($atomicWrite['ready'] ?? false) === true;
            $payload = (array)($response['payload'] ?? []);
            $payload['project_state_atomic_write'] = $atomicWrite;
            $error = (array)($response['error'] ?? ['message' => __('诊断失败。')]);
            if (($atomicWrite['ready'] ?? false) !== true) {
                $error = [
                    'code' => 'windows_atomic_runtime_unavailable',
                    'message' => (string)($atomicWrite['reason']
                        ?? __('WLS 项目状态原子写运行时不可用。')),
                ];
            }
            if (!$json) {
                $this->printer->setup(__('WLS 2.0 网关诊断'));
            }
            $this->output(
                $payload,
                $json,
                $ok,
                $error,
            );
            if (!$json && !$ok) {
                $this->printer->error((string)($error['message'] ?? __('诊断失败。')));
            }
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
