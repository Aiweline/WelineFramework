<?php

declare(strict_types=1);

namespace Weline\Geo\Service;

use Weline\Framework\Event\EventsManager;

class FeedSubmitService
{
    public function __construct(
        private readonly EventsManager $eventsManager
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function requestSubmit(string $url, string $scope, array $extra = []): void
    {
        $url = trim($url);
        $scope = trim($scope);
        if ($url === '' || $scope === '') {
            return;
        }
        // 标题守卫：把 URL 当标题写进 feed 等于「无标题内容」，生成式引擎无法据此引用。
        // 上游漏传真实标题时宁可留空，让 feed 消费方走自己的兜底，也不要把 loc 复制成 title。
        if (isset($extra['title'])) {
            $title = trim((string)$extra['title']);
            if ($title === '' || $title === $url) {
                unset($extra['title']);
            } else {
                $extra['title'] = $title;
            }
        }

        // dispatch() 的第二个形参是引用（EventsManager::dispatch(string, mixed &$data)），
        // 直接传表达式会每个事件触发一条 E_NOTICE（Only variables should be passed by reference）。
        $payload = array_merge($extra, [
            'url' => $url,
            'scope' => $scope,
        ]);
        $this->eventsManager->dispatch('Weline_Geo::integration::feed_submit_request', $payload);
    }

    /**
     * @param string[] $urls
     * @param array<string, mixed> $extra
     */
    public function requestBatch(array $urls, string $scope, array $extra = []): void
    {
        foreach ($urls as $url) {
            $this->requestSubmit((string)$url, $scope, $extra);
        }
    }
}
