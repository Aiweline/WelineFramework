<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Framework;

use Weline\Framework\Compilation\FrameworkCompiler;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Cli\Printing;

final class Compile extends CommandAbstract
{
    public function __construct(
        private readonly Printing $printing,
        private readonly FrameworkCompiler $compiler,
    ) {
    }

    public function execute(array $args = [], array $data = []): void
    {
        $output = BP . 'generated' . DS . 'framework';
        $result = $this->compiler->compile(BP . 'app' . DS . 'code' . DS . 'Weline', $output);

        $this->printing->success(__(
            '框架编译完成：%{1} 个模块，%{2} 个 QueryProvider，%{3} 个延迟 Provider。',
            [
                count($result['modules']['modules'] ?? []),
                count($result['query_providers']['providers'] ?? []),
                count($result['query_providers']['deferred'] ?? []),
            ],
        ));
    }

    public function tip(): string
    {
        return __('编译模块依赖图和 QueryProvider 运行时索引');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'framework:compile',
            $this->tip(),
            ['-h, --help' => __('显示帮助信息')],
            [],
            [__('编译运行时索引') => 'php bin/w framework:compile'],
        );
    }
}
