<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Framework\Query;

use Weline\B2B\Service\B2BConflictException;
use Weline\B2B\Service\B2BHangPaymentService;
use Weline\B2B\Service\B2BQueryHarnessCatalog;
use Weline\B2B\Service\MembershipApplicationService;
use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 前台 B2B 候选 Facade（TEST-P4C-01）+ hang 定金/尾款支付入口。
 */
class B2BQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'b2b';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'resolve' => $this->resolve($params),
            'membership.submit' => $this->submitMembership($params),
            'hang.paymentContext' => $this->hangPaymentContext($params),
            'hang.startPayment' => $this->hangStartPayment($params),
            default => throw new \InvalidArgumentException((string)__('B2B 接口不支持该操作：%{1}', $operation)),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolve(array $params): array
    {
        try {
            $svc = B2BQueryHarnessCatalog::buildService();
            $request = [
                'customer_id' => (string)($params['customer_id'] ?? ''),
                'website_id' => (int)($params['website_id'] ?? 0),
                'sku' => (string)($params['sku'] ?? ''),
                'retail_amount_minor' => (int)($params['retail_amount_minor'] ?? 0),
            ];
            if (array_key_exists('channel_id', $params) && $params['channel_id'] !== null && $params['channel_id'] !== '') {
                $request['channel_id'] = (string)$params['channel_id'];
            }
            if (array_key_exists('claimed_price_list_id', $params)) {
                $request['claimed_price_list_id'] = (string)$params['claimed_price_list_id'];
            }
            if (array_key_exists('claimed_version', $params)) {
                $request['claimed_version'] = (int)$params['claimed_version'];
            }

            $result = $svc->resolve($request);

            return [
                'success' => (bool)($result['ok'] ?? false),
                'ok' => (bool)($result['ok'] ?? false),
                'source' => (string)($result['source'] ?? ''),
                'amount_minor' => (int)($result['amount_minor'] ?? 0),
                'price_list_id' => $result['price_list_id'] ?? null,
                'version' => $result['version'] ?? null,
                'group_id' => $result['group_id'] ?? null,
                'rule_stack' => $result['rule_stack'] ?? [],
                'error' => $result['error'] ?? null,
                'candidate' => $result,
            ];
        } catch (B2BConflictException $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => 'b2b_candidate_request_invalid',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Frontend membership application. Rejects any client-supplied group_id.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function submitMembership(array $params): array
    {
        try {
            $customerId = $this->currentCustomerId();
            if ($customerId === null || $customerId <= 0) {
                return [
                    'success' => false,
                    'ok' => false,
                    'error' => 'b2b_membership_login_required',
                    'message' => (string)__('请先登录后再提交批发身份申请'),
                ];
            }
            $params['customer_id'] = (string)$customerId;

            /** @var MembershipApplicationService $service */
            $service = ObjectManager::getInstance(MembershipApplicationService::class);
            $result = $service->submit($params);
            return [
                'success' => true,
                'ok' => true,
                'application' => $result,
            ];
        } catch (B2BConflictException $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => 'b2b_membership_submit_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function hangPaymentContext(array $params): array
    {
        try {
            return $this->hangPayments()->paymentContext(
                (string)($params['order_uuid'] ?? ''),
                (string)($params['purpose'] ?? ''),
                $this->currentCustomerId(),
            );
        } catch (B2BConflictException $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => 'b2b_hang_payment_context_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function hangStartPayment(array $params): array
    {
        try {
            return $this->hangPayments()->startPayment(
                (string)($params['order_uuid'] ?? ''),
                (string)($params['purpose'] ?? ''),
                (string)($params['payment_method'] ?? ''),
                (string)($params['payment_idempotency_key'] ?? ''),
                $this->currentCustomerId(),
                [
                    'country_code' => (string)($params['country_code'] ?? ''),
                    'locale' => (string)($params['locale'] ?? ''),
                    'environment' => (string)($params['environment'] ?? 'sandbox'),
                    'quote_token' => (string)($params['quote_token'] ?? ''),
                    'checkout_token' => (string)($params['checkout_token'] ?? ''),
                ],
            );
        } catch (B2BConflictException $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
                'context' => $e->context,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => 'b2b_hang_start_payment_failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function hangPayments(): B2BHangPaymentService
    {
        $service = ObjectManager::getInstance(B2BHangPaymentService::class);
        if (!$service instanceof B2BHangPaymentService) {
            throw new \RuntimeException('b2b_hang_payment_service_missing');
        }

        return $service;
    }

    private function currentCustomerId(): ?int
    {
        try {
            $accounts = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(CustomerAccountFacadeInterface::class);
            if ($accounts instanceof CustomerAccountFacadeInterface) {
                $customerId = (int)($accounts->current()?->getId() ?? 0);
                if ($customerId > 0) {
                    return $customerId;
                }
            }
        } catch (\Throwable) {
            // Fall through to frontend session.
        }

        try {
            $session = ObjectManager::getInstance(SessionFactory::class)->createFrontendSession();
            if (!$session->isLoggedIn()) {
                return null;
            }
            $user = $session->getUser();
            if ($user instanceof Customer && $user->getId()) {
                return (int)$user->getId();
            }
            $userId = (int)($session->getUserId() ?? 0);

            return $userId > 0 ? $userId : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getDescriptor(): array
    {
        return [
            'name' => $this->getProviderName(),
            'module' => 'Weline_B2B',
            'summary' => 'B2B price candidate resolve, membership application, hang deposit/balance payment',
            'operations' => [
                [
                    'name' => 'resolve',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'customer_id' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'website_id' => ['type' => 'int', 'required' => true, 'min' => 0],
                        'sku' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                        'retail_amount_minor' => ['type' => 'int', 'required' => true, 'min' => 0],
                        'channel_id' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'claimed_price_list_id' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'claimed_version' => ['type' => 'int', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Resolve B2B price candidate for customer/sku',
                ],
                [
                    'name' => 'membership.submit',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'customer_id' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'website_id' => ['type' => 'int', 'required' => true, 'min' => 0],
                        'company_name' => ['type' => 'string', 'required' => true, 'max_length' => 191],
                        'contact_phone' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'notes' => ['type' => 'string', 'required' => false, 'max_length' => 2000],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Submit B2B membership application (no client group_id)',
                ],
                [
                    'name' => 'hang.paymentContext',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'order_uuid' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'purpose' => ['type' => 'string', 'required' => true, 'max_length' => 16],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Load hang deposit/balance payment amount for account CTA',
                ],
                [
                    'name' => 'hang.startPayment',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'order_uuid' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'purpose' => ['type' => 'string', 'required' => true, 'max_length' => 16],
                        'payment_method' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'payment_idempotency_key' => ['type' => 'string', 'required' => true, 'max_length' => 128],
                        'country_code' => ['type' => 'string', 'required' => false, 'max_length' => 8],
                        'locale' => ['type' => 'string', 'required' => false, 'max_length' => 32],
                        'environment' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Start hang deposit or balance payment for tob order',
                ],
            ],
        ];
    }
}
