<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Policy;

final class Compile extends PolicyCommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        try {
            $instance = $this->instanceName($args);
            $bundle = $this->policyService()->compile($instance, $this->topology($args));
            $result = [
                'instance' => $instance,
                'digest' => $bundle->digest,
                'topology' => $bundle->topology,
                'descriptors' => \count($bundle->descriptors),
            ];
            if ($this->json($args, $result)) {
                return;
            }
            $this->printer->success(__('WLS 运行时策略编译完成。'));
            $this->printer->note(__('Digest：%{1}', [$bundle->digest]));
        } catch (\Throwable $throwable) {
            $this->printer->error($throwable->getMessage());
        }
    }

    public function tip(): string
    {
        return __('编译 WLS 运行时策略包');
    }
}
