<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Console\Command;

use Weline\Cdn\Service\CachePurger;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 清理缓存命令
 * 
 * 用法：php bin/w cdn:cache:clear --domain=example.com --mode=everything [--urls=url1,url2] [--hosts=host1,host2] [--tags=tag1,tag2] [--keys=key1,key2]
 * 
 * @package Weline_Cdn
 */
class CacheClear extends CommandAbstract implements CommandInterface
{
    private CachePurger $cachePurger;

    public function __construct()
    {
        $this->cachePurger = ObjectManager::getInstance(CachePurger::class);
    }

    /**
     * 命令描述
     * 
     * @return string
     */
    public function tip(): string
    {
        return '清理CDN缓存';
    }

    /**
     * 帮助信息
     * 
     * @return array|string
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'cdn:cache:clear',
            $this->tip(),
            [
                '--domain' => '域名ID或名称（必需）',
                '--mode' => '清理模式：everything|urls|hosts|tags|cache_keys（必需）',
                '--urls' => 'URL列表，逗号分隔（mode=urls时必需）',
                '--hosts' => 'Host列表，逗号分隔（mode=hosts时必需）',
                '--tags' => 'Tag列表，逗号分隔（mode=tags时必需）',
                '--keys' => 'Cache Key列表，逗号分隔（mode=cache_keys时必需）',
            ],
            [],
            []
        );
    }

    /**
     * 执行命令
     * 
     * @param array $args
     * @param array $data
     * @return mixed|void
     */
    public function execute(array $args = [], array $data = [])
    {
        $domain = $data['domain'] ?? '';
        $mode = trim($data['mode'] ?? 'everything');

        if (empty($domain)) {
            $this->printer->error(__('域名不能为空，请使用 --domain=域名ID或名称'));
            return;
        }

        if (empty($mode)) {
            $this->printer->error(__('清理模式不能为空，请使用 --mode=everything|urls|hosts|tags|cache_keys'));
            return;
        }

        // 验证清理模式
        $allowedModes = ['everything', 'urls', 'hosts', 'tags', 'cache_keys'];
        if (!in_array($mode, $allowedModes)) {
            $this->printer->error(__('无效的清理模式：%{1}，支持的模式：%{2}', [$mode, implode(', ', $allowedModes)]));
            return;
        }

        // 准备数据
        $purgeData = [];
        
        switch ($mode) {
            case 'urls':
                $urls = $data['urls'] ?? '';
                if (empty($urls)) {
                    $this->printer->error(__('URL列表不能为空，请使用 --urls=url1,url2'));
                    return;
                }
                $purgeData['urls'] = explode(',', $urls);
                break;
                
            case 'hosts':
                $hosts = $data['hosts'] ?? '';
                if (empty($hosts)) {
                    $this->printer->error(__('Host列表不能为空，请使用 --hosts=host1,host2'));
                    return;
                }
                $purgeData['hosts'] = explode(',', $hosts);
                break;
                
            case 'tags':
                $tags = $data['tags'] ?? '';
                if (empty($tags)) {
                    $this->printer->error(__('Tag列表不能为空，请使用 --tags=tag1,tag2'));
                    return;
                }
                $purgeData['tags'] = explode(',', $tags);
                break;
                
            case 'cache_keys':
                $keys = $data['keys'] ?? '';
                if (empty($keys)) {
                    $this->printer->error(__('Cache Key列表不能为空，请使用 --keys=key1,key2'));
                    return;
                }
                $purgeData['keys'] = explode(',', $keys);
                break;
        }

        try {
            $this->printer->note(__('正在清理缓存...'));

            $result = $this->cachePurger->purge($domain, $mode, $purgeData);

            if ($result['success']) {
                $this->printer->success(__('缓存清理成功：%{1}', [$result['message'] ?? '']));
            } else {
                $this->printer->error(__('缓存清理失败：%{1}', [$result['message'] ?? '未知错误']));
            }

        } catch (\Exception $e) {
            $this->printer->error(__('执行失败：%{1}', [$e->getMessage()]));
        }
    }
}

