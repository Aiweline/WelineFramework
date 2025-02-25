<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\WarmCache\Console\Cache;

use GuzzleHttp\Client;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

class Warm implements CommandInterface
{
    private Client $client;
    private Printing $printing;

    public function __construct(Client $client, Printing $printing)
    {
        $this->client = $client;
        $this->printing = $printing;
    }

    public function execute(array $args = [], array $data = [])
    {
        $domain = $args[1] ?? $args['domain'] ?? '';
        if (!$domain) {
            $domain = Env::get('domain') ?: '';
            if (!$domain) {
                $env_path = str_replace(BP, '', Env::path_ENV_FILE);
                $this->printing->warning(__('请输入域名！或者在 %1 中添加domain键【指定域名后无需输入域名即可运行命令：php bin/w cache:warm】。命令行指定示例：php bin/w cache:warm www.aiweline.com ', $env_path));
                exit(1);
            }
        }
        $frontend_routers = (array)(require Env::path_FRONTEND_PC_ROUTER_FILE);
        $this->printing->warning(__('缓存预热开始...'));
        foreach ($frontend_routers as $frontend_router => $router_data) {
            $frontend_router = explode('::', $frontend_router);
            $frontend_url = array_shift($frontend_router);
            $method = 'get';
            if ($frontend_router) {
                $method = strtolower(array_shift($frontend_router));
            }
            $url = 'http://' . $domain . '/' . $frontend_url;
            $this->printing->note($url);
            try {
                $this->client->$method($url);
            } catch (\Exception $exception) {
                $this->printing->warning($exception->getMessage());
            }
        }
        $this->printing->success('缓存预热完成！');
    }

    public function tip(): string
    {
        return __('提供缓存预热,加速网页访问');
    }
}
