<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Policy;

final class Publish extends PolicyCommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        try {
            $instance = $this->instanceName($args);
            $bundle = $this->policyService()->stage($instance, $this->digest($args), $this->topology($args));
            $runtime = $this->publishToRuntime($instance, $bundle->digest);
            $result = [
                'instance' => $instance,
                'digest' => $bundle->digest,
                'runtime' => $runtime,
            ];
            if ($this->json($args, $result)) {
                return;
            }
            $this->printer->success(__('WLS 运行时策略发布已受理。'));
            $this->printer->note(__('Digest：%{1}', [$bundle->digest]));
            $this->printer->note(__('模式：%{1}', [(string)$runtime['mode']]));
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
        }
    }

    public function tip(): string
    {
        return __('两阶段发布 WLS 运行时策略');
    }
}
