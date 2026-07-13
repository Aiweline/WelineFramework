<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Policy;

final class Check extends PolicyCommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        try {
            $result = $this->policyService()->check($this->topology($args), $this->instanceName($args));
            $output = $result;
            $output['bundle'] = $this->policyBundleSummary((array)($result['bundle'] ?? []));
            if ($this->json($args, $output)) {
                return;
            }
            if (!$result['valid']) {
                foreach ($result['errors'] as $error) {
                    $this->printer->error((string)$error);
                }
                return;
            }
            $this->printer->success(__('WLS 运行时策略校验通过。'));
            $this->printer->note(__('Digest：%{1}', [(string)$result['bundle']['digest']]));
            $this->printer->note(__('规则：%{1}', [(string)\count($result['bundle']['descriptors'] ?? [])]));
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
        }
    }

    public function tip(): string
    {
        return __('校验 WLS 编译型运行时策略');
    }
}
