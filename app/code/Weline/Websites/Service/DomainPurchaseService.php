<?php
declare(strict_types=1);

/**
 * 域名购买服务
 *
 * 处理批量域名购买逻辑：
 * - 创建购买订单
 * - 逐个调用适配器 API 购买域名
 * - 购买成功后自动入域名池
 * - 可选绑定站点或自动新建站点
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\DomainPurchaseItem;
use Weline\Websites\Model\DomainPurchaseOrder;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Model\DomainRegistrarAccount;

class DomainPurchaseService
{
    private DomainPurchaseOrder $orderModel;
    private DomainPurchaseItem $itemModel;
    private DomainRegistrar $registrarModel;
    private DomainRegistrarAccount $accountModel;
    private DomainRegistrarResolverService $resolverService;
    private DomainPool $domainPool;
    private EventsManager $eventsManager;

    public function __construct(
        DomainPurchaseOrder $orderModel,
        DomainPurchaseItem $itemModel,
        DomainRegistrar $registrarModel,
        DomainRegistrarAccount $accountModel,
        DomainRegistrarResolverService $resolverService,
        DomainPool $domainPool,
        EventsManager $eventsManager
    ) {
        $this->orderModel = $orderModel;
        $this->itemModel = $itemModel;
        $this->registrarModel = $registrarModel;
        $this->accountModel = $accountModel;
        $this->resolverService = $resolverService;
        $this->domainPool = $domainPool;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 创建并处理购买订单
     *
     * @param int $accountId 域名商账号 ID
     * @param array $items 购买条目列表 [{domain, years, website_id, auto_create_site}, ...]
     * @return array{success: bool, message: string, order_id?: int, order_no?: string, results?: array}
     */
    public function createAndProcessOrder(int $accountId, array $items): array
    {
        // 如果 items 是 JSON 字符串（从前端 FormData 传来），解码
        if (\is_string($items)) {
            $decoded = \json_decode($items, true);
            if (\is_array($decoded)) {
                $items = $decoded;
            }
        }

        if (empty($items)) {
            return [
                'success' => false,
                'message' => __('购买条目不能为空'),
            ];
        }

        // 获取账号和适配器
        $account = clone $this->accountModel;
        $account->load($accountId);
        if (!$account->getAccountId()) {
            return [
                'success' => false,
                'message' => __('域名商账号不存在'),
            ];
        }

        $registrar = clone $this->registrarModel;
        $registrar->load($account->getRegistrarId());
        $adapter = $this->resolverService->getAdapter($registrar->getCode());
        if (!$adapter) {
            return [
                'success' => false,
                'message' => __('未找到对应的域名商适配器'),
            ];
        }

        $credentials = $account->getCredentials();

        // 创建订单
        $order = clone $this->orderModel;
        $order->setData(DomainPurchaseOrder::fields_ACCOUNT_ID, $accountId);
        $order->setData(DomainPurchaseOrder::fields_TOTAL_COUNT, \count($items));
        $order->setData(DomainPurchaseOrder::fields_STATUS, DomainPurchaseOrder::STATUS_PROCESSING);
        $order->save();

        $orderId = $order->getOrderId();
        $successCount = 0;
        $failCount = 0;
        $results = [];

        // 逐个处理购买条目
        foreach ($items as $itemData) {
            $domain = \strtolower(\trim($itemData['domain'] ?? ''));
            $years = (int) ($itemData['years'] ?? 1);
            $websiteId = (int) ($itemData['website_id'] ?? 0);
            $autoCreateSite = ($itemData['auto_create_site'] ?? 'no') === 'yes' ? 'yes' : 'no';

            if (empty($domain)) {
                continue;
            }

            // 创建购买条目
            $item = clone $this->itemModel;
            $item->setData(DomainPurchaseItem::fields_ORDER_ID, $orderId);
            $item->setData(DomainPurchaseItem::fields_DOMAIN, $domain);
            $item->setData(DomainPurchaseItem::fields_YEARS, $years);
            $item->setData(DomainPurchaseItem::fields_WEBSITE_ID, $websiteId);
            $item->setData(DomainPurchaseItem::fields_AUTO_CREATE_SITE, $autoCreateSite);
            $item->setData(DomainPurchaseItem::fields_STATUS, DomainPurchaseItem::STATUS_PENDING);

            try {
                // 调用适配器购买域名
                $purchaseResult = $adapter->purchaseDomain($domain, $years, $credentials);

                if ($purchaseResult['success'] ?? false) {
                    $item->setData(DomainPurchaseItem::fields_STATUS, DomainPurchaseItem::STATUS_SUCCESS);
                    $item->setData(DomainPurchaseItem::fields_PRICE, $purchaseResult['price'] ?? 0);
                    $item->setData(DomainPurchaseItem::fields_CURRENCY, $purchaseResult['currency'] ?? 'USD');
                    $successCount++;

                    // 购买成功后入域名池
                    $this->addToDomainPool($domain);

                    // 绑定站点
                    if ($websiteId > 0 || $autoCreateSite === 'yes') {
                        $this->bindToWebsite($domain, $websiteId, $autoCreateSite);
                    }

                    // 触发购买成功事件
                    $eventData = [
                        'data' => [
                            'domain' => $domain,
                            'order_id' => $orderId,
                            'website_id' => $websiteId,
                            'auto_create_site' => $autoCreateSite,
                        ],
                    ];
                    $this->eventsManager->dispatch('Weline_Websites::domain::purchase_success', $eventData);
                } else {
                    $item->setData(DomainPurchaseItem::fields_STATUS, DomainPurchaseItem::STATUS_FAILED);
                    $item->setData(DomainPurchaseItem::fields_ERROR_MESSAGE, $purchaseResult['message'] ?? __('购买失败'));
                    $failCount++;
                }

                $results[] = [
                    'domain' => $domain,
                    'success' => $purchaseResult['success'] ?? false,
                    'message' => $purchaseResult['message'] ?? '',
                ];
            } catch (\Exception $e) {
                $item->setData(DomainPurchaseItem::fields_STATUS, DomainPurchaseItem::STATUS_FAILED);
                $item->setData(DomainPurchaseItem::fields_ERROR_MESSAGE, $e->getMessage());
                $failCount++;

                $results[] = [
                    'domain' => $domain,
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }

            $item->save();
        }

        // 更新订单状态
        $order->setData(DomainPurchaseOrder::fields_SUCCESS_COUNT, $successCount);
        $order->setData(DomainPurchaseOrder::fields_FAIL_COUNT, $failCount);
        $order->setData(DomainPurchaseOrder::fields_STATUS,
            $failCount === 0 ? DomainPurchaseOrder::STATUS_COMPLETED : (
                $successCount === 0 ? DomainPurchaseOrder::STATUS_FAILED : DomainPurchaseOrder::STATUS_COMPLETED
            )
        );
        $order->save();

        $message = __('购买完成：%{success} 成功，%{fail} 失败', [
            'success' => $successCount,
            'fail' => $failCount,
        ]);

        return [
            'success' => $successCount > 0,
            'message' => $message,
            'order_id' => $orderId,
            'order_no' => $order->getOrderNo(),
            'results' => $results,
        ];
    }

    /**
     * 将域名添加到域名池
     */
    private function addToDomainPool(string $domain): void
    {
        try {
            $pool = clone $this->domainPool;
            $pool->loadByDomain($domain);
            if (!$pool->getPoolId()) {
                $pool->clearData();
                $pool->setDomain($domain);
                $pool->setStatus(DomainPool::STATUS_ACTIVE);
                $pool->save();
            }
        } catch (\Exception $e) {
            \error_log(__('域名入池失败: %{domain}, 错误: %{error}', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * 绑定域名到站点（或自动创建站点）
     */
    private function bindToWebsite(string $domain, int $websiteId, string $autoCreateSite): void
    {
        try {
            if ($autoCreateSite === 'yes' && $websiteId <= 0) {
                // 自动创建站点
                $websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
                $newSite = clone $websiteModel;
                $newSite->clearData();

                // 用域名前缀作为站点名称和代码
                $parts = \explode('.', $domain);
                $code = $parts[0] ?? $domain;
                $newSite->setData('name', $domain);
                $newSite->setData('code', $code);
                $newSite->setData('url', 'http://' . $domain);
                $newSite->save();

                $websiteId = (int) $newSite->getData('website_id');
            }

            if ($websiteId > 0) {
                // 添加域名到网站
                $websiteDomain = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteDomain::class);
                $domainModel = clone $websiteDomain;
                $domainModel->clearData();
                $domainModel->setData('website_id', $websiteId);
                $domainModel->setData('domain', $domain);
                $domainModel->setData('status', 'active');
                $domainModel->save();
            }
        } catch (\Exception $e) {
            \error_log(__('域名绑站失败: %{domain}, 错误: %{error}', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]));
        }
    }
}
