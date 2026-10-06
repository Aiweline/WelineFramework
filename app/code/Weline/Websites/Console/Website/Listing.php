<?php

declare(strict_types=1);

namespace Weline\Websites\Console\Website;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Service\Query\FrameworkQueryService;

class Listing extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var FrameworkQueryService $query */
        $query = ObjectManager::getInstance(FrameworkQueryService::class);
        $list = $query->execute('websites', 'getWebsiteList', [], 'backend');
        if (!\is_array($list)) {
            $list = [];
        }
        if (CliJson::requested($args)) {
            return CliJson::emit(\array_values($list));
        }
        $printing->note((string)__('网站列表（%{1}）', [\count($list)]));
        foreach ($list as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $printing->printing(\sprintf(
                '  #%s  %-16s  %-20s  %s',
                (string)($row['website_id'] ?? ''),
                (string)($row['code'] ?? ''),
                (string)($row['name'] ?? ''),
                (string)($row['url'] ?? ''),
            ));
        }

        return 0;
    }

    public function tip(): string
    {
        return (string)__('列出全部网站（调用 websites.getWebsiteList）');
    }

    public function help(): array|string
    {
        return [
            'php bin/w website:listing --json' => (string)__('JSON 输出，供控制中心读取'),
        ];
    }
}
