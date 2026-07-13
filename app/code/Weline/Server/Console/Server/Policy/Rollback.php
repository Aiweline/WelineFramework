<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Policy;

final class Rollback extends PolicyCommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        try {
            $instance = $this->instanceName($args);
            $bundle = $this->policyService()->prepareRollback($instance, $this->digest($args));
            $runtime = $this->publishToRuntime($instance, $bundle->digest, true);
            $result = [
                'instance' => $instance,
                'digest' => $bundle->digest,
                'runtime' => $runtime,
            ];
            if ($this->json($args, $result)) {
                return;
            }
            $this->printer->success(__('WLS 运行时策略回滚已受理。'));
            $this->printer->note(__('Digest：%{1}', [$bundle->digest]));
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
        }
    }

    public function tip(): string
    {
        return __('回滚 WLS 运行时策略');
    }
}
