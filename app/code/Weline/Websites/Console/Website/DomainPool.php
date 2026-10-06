<?php

declare(strict_types=1);

namespace Weline\Websites\Console\Website;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Service\Query\FrameworkQueryService;

/**
 * 控制中心新建网站：列出域名池可选项（默认 site_ready 且未占用）。
 */
class DomainPool extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $params = [
            'status' => 'active',
            'site_ready' => true,
            'exclude_site_created' => true,
            'limit' => (int)($args['limit'] ?? 500),
        ];
        if (isset($args['all']) || isset($args['--all'])) {
            $params['site_ready'] = false;
            $params['exclude_site_created'] = false;
        }
        /** @var FrameworkQueryService $query */
        $query = ObjectManager::getInstance(FrameworkQueryService::class);
        try {
            $list = $query->execute('websites', 'getDomainPoolList', $params, 'backend');
        } catch (\Throwable $e) {
            $printing->error($e->getMessage());

            return 1;
        }
        if (!\is_array($list)) {
            $list = [];
        }
        if (CliJson::requested($args)) {
            return CliJson::emit(\array_values($list));
        }
        $printing->note((string)__('域名池（%{1}）', [\count($list)]));
        foreach ($list as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $printing->printing(\sprintf(
                '  #%s  %-40s  ready=%s  created=%s',
                (string)($row['pool_id'] ?? ''),
                (string)($row['domain'] ?? ''),
                (string)($row['site_ready'] ?? '0'),
                (string)($row['site_created'] ?? '0'),
            ));
        }

        return 0;
    }

    public function tip(): string
    {
        return (string)__('列出域名池（调用 websites.getDomainPoolList，默认可建站且未占用）');
    }

    public function help(): array|string
    {
        return [
            'php bin/w website:domain-pool --json' => (string)__('JSON 输出，供控制中心读取可建站域名'),
            'php bin/w website:domain-pool --all --json' => (string)__('列出全部活跃池记录（含未就绪/已占用）'),
        ];
    }
}
