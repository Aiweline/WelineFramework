<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Policy;

final class Status extends PolicyCommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        try {
            $result = $this->policyService()->status($this->instanceName($args));
            $output = $result;
            $output['active_bundle'] = \is_array($result['active_bundle'] ?? null)
                ? $this->policyBundleSummary($result['active_bundle'])
                : null;
            if ($this->json($args, $output)) {
                return;
            }
            $this->printer->setup(__('WLS 运行时策略状态'));
            $this->printer->note(__('实例：%{1}', [(string)$result['instance']]));
            $this->printer->note(__('状态：%{1}', [(string)$result['policy_state']]));
            $this->printer->note(__('Active：%{1}', [(string)($result['active_digest'] ?: '-')]));
            $this->printer->note(__('Staged：%{1}', [(string)($result['staged_digest'] ?: '-')]));
            $this->printer->note(__('Previous：%{1}', [(string)($result['previous_digest'] ?: '-')]));
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
        }
    }

    public function tip(): string
    {
        return __('查看 WLS 运行时策略状态');
    }
}
