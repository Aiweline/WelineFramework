<?php

declare(strict_types=1);

namespace Weline\Server\Console\Console\Server;

use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

/**
 * Retired compatibility command for the removed PHP built-in server launcher.
 */
final class Start implements CommandInterface
{
    public function __construct(private Printing $printer)
    {
    }

    public function execute(array $args = [], array $data = []): int
    {
        $this->printer->error(__('已退役：console:server:start 不再启动 PHP 内置 Web Server。'));
        $this->printer->note(__('Nginx 是唯一公网边缘；请使用 php bin/w server:start。'));

        return 1;
    }

    public function tip(): string
    {
        return (string)__('已退役：PHP 内置 Web Server 启动入口');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'console:server:start',
            $this->tip(),
            [],
            [
                __('替代命令') => 'php bin/w server:start',
            ],
            []
        );
    }
}
