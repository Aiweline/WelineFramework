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
     * 
     * @param string $url 要提交的 URL
     * @param string $scope 业务范围标识（如 page_builder、catalog）
     * @param array $extra 额外参数
     */
    public function requestSubmit(string $url, string $scope, array $extra = []): void
    {
        $url = trim($url);
        $scope = trim($scope);

        if ($url === '' || $scope === '') {
            return;
        }

        $data = array_merge($extra, [
            'url' => $url,
            'scope' => $scope,
        ]);

        $this->eventsManager->dispatch('Weline_Seo::integration::url_submit_request', $data);
    }

    /**
     * 批量提交 URL
     *
     * @param array $urls URL 列表
     * @param string $scope 业务范围标识
     * @param array $extra 额外参数
     */
    public function requestBatch(array $urls, string $scope, array $extra = []): void
    {
        foreach ($urls as $url) {
            $this->requestSubmit((string)$url, $scope, $extra);
        }
    }
}

