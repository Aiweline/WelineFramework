<?php

declare(strict_types=1);

namespace Weline\Newsletter\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Newsletter\Service\SubscribeService;

/**
 * Storefront BinQuery: newsletter.subscribe
 */
final class NewsletterQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'newsletter';
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'newsletter',
            'name' => '邮件订阅',
            'description' => '前台邮件订阅提交（与 Frontend Controller 同调 SubscribeService）',
            'module' => 'Weline_Newsletter',
            'operations' => [
                [
                    'name' => 'subscribe',
                    'frontend' => true,
                    'external' => true,
                    'mode' => 'write',
                    'description' => '提交邮箱订阅；返回 ok/message/subscriber_id|email/coupon_code?',
                    'params' => [
                        ['name' => 'email', 'type' => 'string', 'required' => true, 'description' => '邮箱'],
                        ['name' => 'topic_promo', 'type' => 'bool', 'required' => false, 'description' => '优惠活动主题，默认 true'],
                        ['name' => 'topic_new_arrivals', 'type' => 'bool', 'required' => false, 'description' => '上新主题，默认 true'],
                        ['name' => 'source_surface', 'type' => 'string', 'required' => false, 'description' => 'footer|popup|api'],
                        ['name' => 'locale', 'type' => 'string', 'required' => false, 'description' => 'locale'],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '网站 ID'],
                        ['name' => 'customer_id', 'type' => 'int', 'required' => false, 'description' => '登录客户 ID'],
                        ['name' => 'shop_url', 'type' => 'string', 'required' => false, 'description' => '商店 URL'],
                        ['name' => 'site_name', 'type' => 'string', 'required' => false, 'description' => '站点名'],
                    ],
                ],
            ],
        ];
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'subscribe' => $this->subscribe($params),
            default => throw new \InvalidArgumentException('newsletter_operation_unsupported'),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function subscribe(array $params): array
    {
        /** @var SubscribeService $service */
        $service = ObjectManager::getInstance(SubscribeService::class);

        return $service->subscribe($params);
    }
}
