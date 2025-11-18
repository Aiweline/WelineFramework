<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Controller\Api;

use Weline\Cdn\Service\CachePurger;
use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN缓存清理API控制器
 * 
 * @package Weline_Cdn
 */
class Clear extends BackendRestController
{
    /**
     * 获取缓存清理服务
     */
    private function getCachePurger(): CachePurger
    {
        return ObjectManager::getInstance(CachePurger::class);
    }

    /**
     * 清理缓存API
     * 
     * POST /api/cdn/clear
     * 
     * 参数：
     * - domain: 域名ID或名称（必需）
     * - mode: 清理模式，everything|urls|hosts|tags|cache_keys（必需）
     * - data: 模式相关的数据（可选）
     *   - urls: URL数组（mode=urls时必需）
     *   - hosts: Host数组（mode=hosts时必需）
     *   - tags: Tag数组（mode=tags时必需）
     *   - keys: Cache Key数组（mode=cache_keys时必需）
     * 
     * @return string
     */
    public function index(): string
    {
        if (!$this->request->isPost()) {
            return $this->error(__('仅支持POST请求'), '', 405);
        }

        $domain = $this->request->getPost('domain');
        $mode = trim($this->request->getPost('mode', 'everything'));
        $data = $this->request->getPost('data', []);

        if (empty($domain)) {
            return $this->error(__('域名参数不能为空'), '', 400);
        }

        if (empty($mode)) {
            return $this->error(__('清理模式不能为空'), '', 400);
        }

        // 验证清理模式
        $allowedModes = ['everything', 'urls', 'hosts', 'tags', 'cache_keys'];
        if (!in_array($mode, $allowedModes)) {
            return $this->error(__('无效的清理模式'), '', 400);
        }

        // 根据模式验证必需的数据
        switch ($mode) {
            case 'urls':
                if (empty($data['urls'])) {
                    return $this->error(__('URL列表不能为空'), '', 400);
                }
                break;
            case 'hosts':
                if (empty($data['hosts'])) {
                    return $this->error(__('Host列表不能为空'), '', 400);
                }
                break;
            case 'tags':
                if (empty($data['tags'])) {
                    return $this->error(__('Tag列表不能为空'), '', 400);
                }
                break;
            case 'cache_keys':
                if (empty($data['keys'])) {
                    return $this->error(__('Cache Key列表不能为空'), '', 400);
                }
                break;
        }

        try {
            $result = $this->getCachePurger()->purge($domain, $mode, $data);

            if ($result['success']) {
                return $this->success(__('缓存清理成功'), $result);
            } else {
                return $this->error($result['message'] ?? __('缓存清理失败'), $result, 500);
            }
        } catch (\Exception $e) {
            return $this->error(__('清理失败：%{1}', $e->getMessage()), '', 500);
        }
    }
}

