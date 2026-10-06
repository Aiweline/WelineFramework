<?php

declare(strict_types=1);

namespace Weline\Websites\Console\Website;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Service\Query\FrameworkQueryService;

class Create extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): mixed
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $params = [
            'name' => (string)($args['name'] ?? ''),
            'url' => (string)($args['url'] ?? $args['domain'] ?? ''),
            'pool_id' => (int)($args['pool-id'] ?? $args['pool_id'] ?? 0),
            'code' => (string)($args['code'] ?? ''),
            'default_timezone' => (string)($args['timezone'] ?? $args['default_timezone'] ?? 'Asia/Shanghai'),
        ];
        /** @var FrameworkQueryService $query */
        $query = ObjectManager::getInstance(FrameworkQueryService::class);
        try {
            $result = $query->execute('websites', 'createWebsite', $params, 'backend');
        } catch (\Throwable $e) {
            $printing->error($e->getMessage());
            return 1;
        }
        if (CliJson::requested($args)) {
            return CliJson::emit($result);
        }
        $website = \is_array($result) ? ($result['website'] ?? []) : [];
        $printing->success((string)($result['message'] ?? __('站点创建成功')));
        if (\is_array($website)) {
            $printing->note(\sprintf(
                '#%s  %s  %s  %s',
                (string)($website['website_id'] ?? ''),
                (string)($website['code'] ?? ''),
                (string)($website['name'] ?? ''),
                (string)($website['url'] ?? ''),
            ));
        }

        return 0;
    }

    public function tip(): string
    {
        return (string)__('新建网站（调用 websites.createWebsite）');
    }

    public function help(): array|string
    {
        return [
            'php bin/w website:create --name=店铺 --pool-id=12 --json' => (string)__('从域名池选择主地址创建（控制中心默认）'),
            'php bin/w website:create --name=店铺 --url=shop.example.com --json' => (string)__('按名称和地址创建站点；code 可省略'),
        ];
    }
}
