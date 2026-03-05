<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池解析偏离本站观察者
 *
 * 监听 Weline_Websites::domain_pool::resolve_off_local 事件，
 * 通过 w_msg 向订阅用户发送通知。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class DomainPoolResolveOffLocalObserver implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!\is_array($data)) {
            return;
        }

        $domain = (string) ($data['domain'] ?? '');
        if ($domain === '') {
            return;
        }

        $poolId = (int) ($data['pool_id'] ?? 0);
        $resolvedIp = (string) ($data['resolved_ip'] ?? '');
        $expectedIp = (string) ($data['expected_ip'] ?? '');

        $title = __('域名池解析偏离本站');
        $content = __('域名 %{domain} 解析已不再指向本服务器。当前解析 IP：%{resolved}，本服务器 IP：%{expected}。请检查 DNS 解析配置。', [
            'domain' => $domain,
            'resolved' => $resolvedIp ?: __('未解析'),
            'expected' => $expectedIp ?: __('未知'),
        ]);

        if (\function_exists('w_msg')) {
            w_msg('domain_pool_resolve_off_local', 'warning', $title, $content, [
                'metadata' => [
                    'domain' => $domain,
                    'pool_id' => $poolId,
                    'resolved_ip' => $resolvedIp,
                    'expected_ip' => $expectedIp,
                ],
            ]);
        }
    }
}
