<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名池解析偏离本站观察者
 *
 * 监听 Weline_Websites::domain_pool::resolve_off_local 事件，
 * 以 scoped urgent 写链通知对象 Scope 授权用户；无授权仅留审计。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Observer;

use Weline\Backend\Service\ScopedUrgentNotifier;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

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

        /** @var ScopedUrgentNotifier $notifier */
        $notifier = ObjectManager::getInstance(ScopedUrgentNotifier::class);
        // 域名池是站群基础设施：落默认站 website Scope（0/default），由对象 ACL 裁剪收件人。
        $scope = ScopeIdentity::website(0, 'default');
        $notifier->emit(
            'domain_pool_resolve_off_local',
            $title,
            $content,
            $scope,
            'domain_pool_off_local:' . \strtolower($domain),
            [
                'domain' => $domain,
                'pool_id' => $poolId,
                'resolved_ip' => $resolvedIp,
                'expected_ip' => $expectedIp,
                'source_module' => 'Weline_Websites',
            ],
            null,
            'ri-error-warning-line',
        );
    }
}
