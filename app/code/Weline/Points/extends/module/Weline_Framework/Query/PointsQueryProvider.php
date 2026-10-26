<?php

declare(strict_types=1);

namespace Weline\Points\Extends\Module\Weline_Framework\Query;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Points\Service\PointsCheckoutApplySession;
use Weline\Points\Service\PointsCheckoutDiscountQuote;
use Weline\Points\Service\VipLevelService;

/**
 * Storefront points query surface: discount quote/apply and VIP read.
 */
class PointsQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'points';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'discount.quote' => $this->discountQuote($params),
            'discount.apply' => $this->discountApply($params),
            'discount.clear' => $this->discountClear(),
            'vip.state' => $this->vipState($params),
            'vip.benefits' => $this->vipBenefits($params),
            'vip.levels' => $this->vipLevels(),
            default => throw new \InvalidArgumentException(
                (string) __('Points 查询不支持该操作：%{1}', [$operation]),
            ),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function discountQuote(array $params): array
    {
        $customerId = $this->currentCustomerId();
        $websiteId = max(0, (int) ($params['website_id'] ?? 0));
        $currency = strtoupper(trim((string) ($params['currency'] ?? 'CNY'))) ?: 'CNY';
        $amountMinor = max(0, (int) ($params['amount_minor'] ?? 0));
        $namespace = strtolower(trim((string) ($params['namespace'] ?? 'live'))) ?: 'live';
        try {
            $quote = ObjectManager::getInstance(PointsCheckoutDiscountQuote::class);
            if (!$quote instanceof PointsCheckoutDiscountQuote) {
                return ['success' => false, 'ok' => false, 'error' => 'points_quote_unavailable'];
            }

            return [
                'success' => true,
                'ok' => true,
                'points_discount' => $quote->quote(
                    $customerId !== null ? (string) $customerId : '',
                    $websiteId,
                    $currency,
                    $amountMinor,
                    $namespace,
                ),
            ];
        } catch (\Throwable $exception) {
            return ['success' => false, 'ok' => false, 'error' => 'points_quote_failed', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function discountApply(array $params): array
    {
        $customerId = $this->currentCustomerId();
        if ($customerId === null || $customerId <= 0) {
            return ['success' => false, 'ok' => false, 'error' => 'not_logged_in'];
        }
        $websiteId = max(0, (int) ($params['website_id'] ?? 0));
        $currency = strtoupper(trim((string) ($params['currency'] ?? 'CNY'))) ?: 'CNY';
        $amountMinor = max(0, (int) ($params['amount_minor'] ?? 0));
        $requested = max(0, (int) ($params['apply_minor'] ?? 0));
        $namespace = strtolower(trim((string) ($params['namespace'] ?? 'live'))) ?: 'live';

        try {
            $quoteService = ObjectManager::getInstance(PointsCheckoutDiscountQuote::class);
            if (!$quoteService instanceof PointsCheckoutDiscountQuote) {
                return ['success' => false, 'ok' => false, 'error' => 'points_quote_unavailable'];
            }
            $quote = $quoteService->quote((string) $customerId, $websiteId, $currency, $amountMinor, $namespace);
            if (empty($quote['enabled'])) {
                return ['success' => true, 'ok' => true, 'points_discount' => $quote, 'applied' => null];
            }
            $clamped = $quoteService->clampApply($quote, $requested);
            $apply = [
                'points' => (int) $clamped['points'],
                'apply_checkout_minor' => (int) $clamped['apply_checkout_minor'],
                'apply_base_minor' => (int) $clamped['apply_base_minor'],
                'checkout_currency' => $currency,
                'base_currency' => (string) $quote['base_currency'],
                'exchange_ratio' => (float) $quote['exchange_ratio'],
            ];
            $session = ObjectManager::getInstance(PointsCheckoutApplySession::class);
            if ($session instanceof PointsCheckoutApplySession) {
                $session->save($apply);
            }

            return [
                'success' => true,
                'ok' => true,
                'points_discount' => $quote,
                'applied' => $apply,
            ];
        } catch (\Throwable $exception) {
            return ['success' => false, 'ok' => false, 'error' => 'points_apply_failed', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function discountClear(): array
    {
        try {
            $session = ObjectManager::getInstance(PointsCheckoutApplySession::class);
            if ($session instanceof PointsCheckoutApplySession) {
                $session->clear();
            }

            return ['success' => true, 'ok' => true, 'applied' => null];
        } catch (\Throwable) {
            return ['success' => true, 'ok' => true, 'applied' => null];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function vipState(array $params): array
    {
        $customerId = (int) ($params['customer_id'] ?? 0) ?: (int) ($this->currentCustomerId() ?? 0);
        $websiteId = max(0, (int) ($params['website_id'] ?? 0));
        $namespace = strtolower(trim((string) ($params['namespace'] ?? 'live'))) ?: 'live';
        try {
            $vip = ObjectManager::getInstance(VipLevelService::class);
            if (!$vip instanceof VipLevelService) {
                return ['success' => false, 'ok' => false, 'error' => 'points_vip_unavailable'];
            }

            return ['success' => true, 'ok' => true, 'state' => $vip->stateFor($customerId, $websiteId, $namespace)];
        } catch (\Throwable $exception) {
            return ['success' => false, 'ok' => false, 'error' => 'points_vip_state_failed', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function vipBenefits(array $params): array
    {
        $customerId = (int) ($params['customer_id'] ?? 0) ?: (int) ($this->currentCustomerId() ?? 0);
        $websiteId = max(0, (int) ($params['website_id'] ?? 0));
        $namespace = strtolower(trim((string) ($params['namespace'] ?? 'live'))) ?: 'live';
        try {
            $vip = ObjectManager::getInstance(VipLevelService::class);
            if (!$vip instanceof VipLevelService) {
                return ['success' => false, 'ok' => false, 'error' => 'points_vip_unavailable'];
            }

            return ['success' => true, 'ok' => true, 'benefits' => $vip->benefitsFor($customerId, $websiteId, $namespace)];
        } catch (\Throwable $exception) {
            return ['success' => false, 'ok' => false, 'error' => 'points_vip_benefits_failed', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function vipLevels(): array
    {
        try {
            $vip = ObjectManager::getInstance(VipLevelService::class);
            if (!$vip instanceof VipLevelService) {
                return ['success' => false, 'ok' => false, 'error' => 'points_vip_unavailable'];
            }

            return ['success' => true, 'ok' => true, 'levels' => $vip->listLevels()];
        } catch (\Throwable $exception) {
            return ['success' => false, 'ok' => false, 'error' => 'points_vip_levels_failed', 'message' => $exception->getMessage()];
        }
    }

    private function currentCustomerId(): ?int
    {
        try {
            $accounts = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(CustomerAccountFacadeInterface::class);
            if ($accounts instanceof CustomerAccountFacadeInterface) {
                $customerId = (int) ($accounts->current()?->getId() ?? 0);
                if ($customerId > 0) {
                    return $customerId;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $session = ObjectManager::getInstance(SessionFactory::class)->createFrontendSession();
            if (!$session->isLoggedIn()) {
                return null;
            }
            $userId = (int) ($session->getUserId() ?? 0);

            return $userId > 0 ? $userId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => 'Points 查询',
            'description' => '积分抵扣报价/应用与 VIP 等级读取',
            'module' => 'Weline_Points',
            'operations' => [
                [
                    'name' => 'discount.quote',
                    'description' => '按购物车金额与顾客积分余额计算可抵扣上限',
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '站点 ID'],
                        ['name' => 'currency', 'type' => 'string', 'required' => false, 'description' => '结账币'],
                        ['name' => 'amount_minor', 'type' => 'int', 'required' => true, 'description' => '购物车/应付金额（最小单位）'],
                    ],
                ],
                [
                    'name' => 'discount.apply',
                    'description' => '钳制并保存积分抵扣选择',
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '站点 ID'],
                        ['name' => 'currency', 'type' => 'string', 'required' => false, 'description' => '结账币'],
                        ['name' => 'amount_minor', 'type' => 'int', 'required' => true, 'description' => '购物车/应付金额（最小单位）'],
                        ['name' => 'apply_minor', 'type' => 'int', 'required' => true, 'description' => '希望抵扣的结账币金额（最小单位）'],
                    ],
                ],
                [
                    'name' => 'discount.clear',
                    'description' => '清除积分抵扣选择',
                    'params' => [],
                ],
                [
                    'name' => 'vip.state',
                    'description' => '读取顾客成长值、累计积分与当前等级',
                    'params' => [
                        ['name' => 'customer_id', 'type' => 'int', 'required' => false, 'description' => '顾客 ID，默认当前登录顾客'],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '站点 ID'],
                        ['name' => 'namespace', 'type' => 'string', 'required' => false, 'description' => 'live|sandbox'],
                    ],
                ],
                [
                    'name' => 'vip.benefits',
                    'description' => '读取当前等级权益',
                    'params' => [
                        ['name' => 'customer_id', 'type' => 'int', 'required' => false, 'description' => '顾客 ID'],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'description' => '站点 ID'],
                    ],
                ],
                [
                    'name' => 'vip.levels',
                    'description' => '列出等级门槛与权益',
                    'params' => [],
                ],
            ],
        ];
    }
}
