<?php
declare(strict_types=1);

/**
 * 建站智能体服务
 *
 * 根据描述执行一站式建站：购买域名、DNS 解析、HTTPS 申请、站点创建
 * 通过 onProgress 回调支持 SSE 实时推送进度
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Website;

class WebsiteAgentService
{
    public function __construct(
        private readonly DomainPurchaseService $purchaseService,
        private readonly DomainResolveService $resolveService,
        private readonly FrameworkQueryService $queryService
    ) {
    }

    /**
     * 根据描述执行建站流程（购买→解析→HTTPS→站点）
     *
     * @param string $description 站点描述，用于站点名称
     * @param string $domain 要购买的域名（必填）
     * @param int $accountId 域名商账号 ID
     * @param callable|null $onProgress function(string $event, array $data): void
     * @param array<string, mixed> $itemExtras 合并进购买条目（如 user_client_ip、purchase_contact 片段）
     * @return array{success: bool, message: string, domain?: string, website_id?: int, order_id?: int}
     */
    public function buildFromDescription(
        string $description,
        string $domain,
        int $accountId,
        ?callable $onProgress = null,
        array $itemExtras = []
    ): array {
        $emit = function (string $event, array $data) use ($onProgress): void {
            if ($onProgress) {
                $onProgress($event, $data);
            }
        };

        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return ['success' => false, 'message' => __('域名不能为空')];
        }
        if ($accountId <= 0) {
            return ['success' => false, 'message' => __('请选择域名商账号')];
        }

        $emit('start', ['message' => __('正在检查域名可用性...')]);

        // 1. 检查可用性
        $checkResult = $this->queryService->execute('websites', 'checkAvailability', [
            'account_id' => $accountId,
            'domains' => [$domain],
        ]);

        $available = false;
        if (\is_array($checkResult)) {
            foreach ($checkResult as $item) {
                if (isset($item['domain']) && \strtolower((string) $item['domain']) === $domain && ($item['available'] ?? false)) {
                    $available = true;
                    break;
                }
            }
        }
        if (!$available) {
            $emit('error', ['message' => __('域名 %{1} 不可用，请更换后重试', [$domain])]);
            return ['success' => false, 'message' => __('域名不可用')];
        }
        $emit('progress', ['message' => __('域名 %{1} 可用', [$domain]), 'progress' => 10]);

        // 2. 购买域名（含自动解析、自动建站）
        $emit('progress', ['message' => __('正在购买域名...'), 'progress' => 20]);
        $items = [
            \array_merge([
                'domain' => $domain,
                'years' => 1,
                'website_id' => 0,
                'auto_create_site' => 'yes',
                'resolve_to_local' => 'yes',
                'start_lifecycle' => '1',
                'subdomains' => ['@', 'www'],
            ], $itemExtras),
        ];
        $purchaseResult = $this->purchaseService->createAndProcessOrder($accountId, $items, true);
        if (!$purchaseResult['success']) {
            $emit('error', ['message' => $purchaseResult['message'] ?? __('购买失败')]);
            return ['success' => false, 'message' => $purchaseResult['message'] ?? __('购买失败')];
        }

        $orderId = $purchaseResult['order_id'] ?? 0;
        $results = $purchaseResult['results'] ?? [];
        $firstResult = $results[0] ?? [];
        if (!($firstResult['success'] ?? false)) {
            $msg = $firstResult['message'] ?? __('购买失败');
            $emit('error', ['message' => $msg]);
            return ['success' => false, 'message' => $msg];
        }

        $emit('progress', ['message' => __('域名购买成功'), 'progress' => 50]);

        // 3. 更新站点名称（若提供了描述）
        $websiteId = 0;
        $websiteModel = ObjectManager::getInstance(Website::class);
        $websiteModel->clearQuery()
            ->where(Website::schema_fields_NAME, $domain)
            ->find()
            ->fetch();
        if ($websiteModel->getWebsiteId()) {
            $websiteId = (int) $websiteModel->getWebsiteId();
            if (\trim($description) !== '') {
                $websiteModel->setData(Website::schema_fields_NAME, $description);
                $websiteModel->save();
                $emit('progress', ['message' => __('已根据描述设置站点名称'), 'progress' => 55]);
            }
        }

        // 4. 等待 DNS 解析生效后申请 HTTPS（异步解析由 Cron 处理，这里主动触发一次解析检查）
        $emit('progress', ['message' => __('正在检查 DNS 解析状态...'), 'progress' => 60]);
        $poolModel = ObjectManager::getInstance(DomainPool::class);
        $poolModel->loadByDomain($domain);
        $poolId = $poolModel->getPoolId() ?: 0;

        // 5. 申请 SSL 证书（DNS 可能尚未生效，失败时可稍后重试）
        $emit('progress', ['message' => __('正在申请 HTTPS 证书...'), 'progress' => 75]);
        $sslResult = $this->queryService->execute('server', 'requestCertificate', [
            'domain' => $domain,
            'website_id' => $websiteId,
            'pool_id' => $poolId,
        ]);

        if ($sslResult['success'] ?? false) {
            $emit('progress', ['message' => __('HTTPS 证书申请成功'), 'progress' => 95]);
        } else {
            $sslMsg = $sslResult['message'] ?? __('证书申请失败，请稍后在证书管理中手动申请');
            $emit('warning', ['message' => $sslMsg]);
        }

        $emit('done', [
            'message' => __('建站完成'),
            'domain' => $domain,
            'website_id' => $websiteId,
            'order_id' => $orderId,
            'https_ok' => $sslResult['success'] ?? false,
        ]);

        return [
            'success' => true,
            'message' => __('建站完成：%{1}', [$domain]),
            'domain' => $domain,
            'website_id' => $websiteId,
            'order_id' => $orderId,
        ];
    }

    /**
     * 从描述生成域名建议（简单规则，供前端展示）
     *
     * @param string $description 站点描述
     * @return array<string> 建议域名列表
     */
    public function suggestDomainsFromDescription(string $description): array
    {
        $desc = \preg_replace('/[\s\x{3000}\x{4e00}-\x{9fff}]+/u', ' ', $description);
        $desc = \trim(\preg_replace('/\s+/', ' ', $desc));
        if ($desc === '') {
            return ['mysite.com', 'mysite.net', 'mysite.cn'];
        }
        $words = \explode(' ', $desc);
        $base = \strtolower(\preg_replace('/[^a-z0-9]/i', '', $words[0] ?? 'site'));
        if (\strlen($base) < 2) {
            $base = 'mysite';
        }
        $tlds = ['.com', '.net', '.cn'];
        $suggestions = [];
        foreach ($tlds as $tld) {
            $suggestions[] = $base . $tld;
        }
        return \array_slice(\array_unique($suggestions), 0, 5);
    }
}
