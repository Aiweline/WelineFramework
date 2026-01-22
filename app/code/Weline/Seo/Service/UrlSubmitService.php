<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Event\EventsManager;

/**
 * URL 提交服务
 *
 * 为其他模块提供统一的 SEO URL 提交入口，
 * 通过事件 Weline_Seo::integration::url_submit_request 与队列解耦。
 */
class UrlSubmitService
{
    private EventsManager $eventsManager;

    public function __construct(EventsManager $eventsManager)
    {
        $this->eventsManager = $eventsManager;
    }

    /**
     * 提交单个 URL
     */
    public function requestSubmit(string $url, string $scope, string $module, array $extra = []): void
    {
        $url = trim($url);
        $scope = trim($scope);
        $module = trim($module);

        if ($url === '' || $scope === '' || $module === '') {
            return;
        }

        $data = array_merge($extra, [
            'url' => $url,
            'scope' => $scope,
            'module' => $module,
        ]);

        $this->eventsManager->dispatch('Weline_Seo::integration::url_submit_request', $data);
    }

    /**
     * 批量提交 URL
     *
     * @param array $urls URL 列表
     */
    public function requestBatch(array $urls, string $scope, string $module, array $extra = []): void
    {
        foreach ($urls as $url) {
            $this->requestSubmit((string)$url, $scope, $module, $extra);
        }
    }
}

