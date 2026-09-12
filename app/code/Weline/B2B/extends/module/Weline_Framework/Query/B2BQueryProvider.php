<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Framework\Query;

use Weline\B2B\Service\B2BCheckoutCreditQuote;
use Weline\B2B\Service\B2BConflictException;
use Weline\B2B\Service\B2BHangPaymentService;
use Weline\B2B\Service\B2BQueryHarnessCatalog;
use Weline\B2B\Service\MembershipApplicationService;
use Weline\B2B\Service\MembershipStatusProjection;
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
            'membership.status' => $this->membershipStatus($params),
            'hang.paymentContext' => $this->hangPaymentContext($params),
            'hang.startPayment' => $this->hangStartPayment($params),
            'credit.quote' => $this->creditQuote($params),
            'orderChat.open' => $this->orderChatOpen($params),
            'orderChat.messages' => $this->orderChatMessages($params),
            'orderChat.send' => $this->orderChatSend($params),
            'orderChat.markSeen' => $this->orderChatMarkSeen($params),
            'orderChat.list' => $this->orderChatList($params),
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
     * Read-only membership CTA projection. Session customer only; short per-customer throttle.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function membershipStatus(array $params): array
    {
        try {
            $customerId = $this->currentCustomerId();
            if ($customerId === null || $customerId <= 0) {
                /** @var MembershipStatusProjection $projection */
                $projection = ObjectManager::getInstance(MembershipStatusProjection::class);
                $snap = $projection->snapshot('', max(0, (int)($params['website_id'] ?? 0)));
                return [
                    'success' => true,
                    'ok' => true,
                    'status' => $snap,
                    'throttled' => false,
                ];
            }

            $websiteId = max(0, (int)($params['website_id'] ?? 0));
            $cacheKey = 'b2b.membership.status.' . $customerId . '.' . $websiteId;
            $now = time();
            $cached = \Weline\Framework\Runtime\RequestContext::get($cacheKey);
            if (is_array($cached)
                && isset($cached['at'], $cached['status'])
                && ($now - (int)$cached['at']) < 45
            ) {
                return [
                    'success' => true,
                    'ok' => true,
                    'status' => $cached['status'],
                    'throttled' => true,
                ];
            }

            /** @var MembershipStatusProjection $projection */
            $projection = ObjectManager::getInstance(MembershipStatusProjection::class);
            $snap = $projection->snapshot((string)$customerId, $websiteId);
            \Weline\Framework\Runtime\RequestContext::set($cacheKey, [
                'at' => $now,
                'status' => $snap,
            ]);

            return [
                'success' => true,
                'ok' => true,
                'status' => $snap,
                'throttled' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => 'b2b_membership_status_failed',
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

    /**
     * Preview wholesale credit apply for tob checkout (before freezeQuote).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function creditQuote(array $params): array
    {
        $deposit = max(0, (int)($params['deposit_amount_minor'] ?? 0));
        $currency = strtoupper(trim((string)($params['currency'] ?? 'CNY'))) ?: 'CNY';
        $websiteId = max(0, (int)($params['website_id'] ?? 0));
        $customerId = $this->currentCustomerId();
        if ($customerId === null || $customerId <= 0) {
            return [
                'success' => true,
                'ok' => true,
                'b2b_credit' => B2BCheckoutCreditQuote::unavailableStub('not_logged_in', $deposit),
            ];
        }
        try {
            /** @var B2BCheckoutCreditQuote $svc */
            $svc = ObjectManager::getInstance(B2BCheckoutCreditQuote::class);
            if (!$svc instanceof B2BCheckoutCreditQuote) {
                return [
                    'success' => true,
                    'ok' => true,
                    'b2b_credit' => B2BCheckoutCreditQuote::unavailableStub('quote_failed', $deposit),
                ];
            }

            return [
                'success' => true,
                'ok' => true,
                'b2b_credit' => $svc->quote((string)$customerId, $websiteId, $currency, $deposit),
            ];
        } catch (\Throwable) {
            return [
                'success' => true,
                'ok' => true,
                'b2b_credit' => B2BCheckoutCreditQuote::unavailableStub('quote_failed', $deposit),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function orderChatOpen(array $params): array
    {
        try {
            $customerId = $this->currentCustomerId();
            if ($customerId === null || $customerId <= 0) {
                return ['success' => false, 'ok' => false, 'error' => 'auth_required'];
            }
            $websiteId = (int)($params['website_id'] ?? $this->currentWebsiteId());
            $thread = $this->orderChat()->openOrCreate(
                (string)($params['order_uuid'] ?? $params['order_ref'] ?? ''),
                (string)$customerId,
                $websiteId,
                isset($params['hang_id']) ? (string)$params['hang_id'] : null,
            );

            return ['success' => true, 'ok' => true, 'thread' => $thread];
        } catch (B2BConflictException $e) {
            return ['success' => false, 'ok' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'ok' => false, 'error' => 'b2b_order_chat_open_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function orderChatMessages(array $params): array
    {
        try {
            $messages = $this->orderChat()->listMessages(
                (string)($params['thread_id'] ?? ''),
                (int)($params['since_id'] ?? 0),
            );

            return ['success' => true, 'ok' => true, 'messages' => $messages];
        } catch (B2BConflictException $e) {
            return ['success' => false, 'ok' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'ok' => false, 'error' => 'b2b_order_chat_messages_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function orderChatSend(array $params): array
    {
        try {
            $customerId = $this->currentCustomerId();
            if ($customerId === null || $customerId <= 0) {
                return ['success' => false, 'ok' => false, 'error' => 'auth_required'];
            }
            $msg = $this->orderChat()->send(
                (string)($params['thread_id'] ?? ''),
                (string)($params['role'] ?? 'customer'),
                (string)($params['body'] ?? $params['body_text'] ?? ''),
                (string)$customerId,
            );

            return ['success' => true, 'ok' => true, 'message' => $msg];
        } catch (B2BConflictException $e) {
            return ['success' => false, 'ok' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'ok' => false, 'error' => 'b2b_order_chat_send_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function orderChatMarkSeen(array $params): array
    {
        try {
            $customerId = $this->currentCustomerId();
            if ($customerId === null || $customerId <= 0) {
                return ['success' => false, 'ok' => false, 'error' => 'auth_required'];
            }
            $thread = $this->orderChat()->markSeen(
                (string)($params['thread_id'] ?? ''),
                (string)($params['role'] ?? 'customer'),
                (string)$customerId,
            );

            return ['success' => true, 'ok' => true, 'thread' => $thread];
        } catch (B2BConflictException $e) {
            return ['success' => false, 'ok' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'ok' => false, 'error' => 'b2b_order_chat_mark_seen_failed', 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function orderChatList(array $params): array
    {
        try {
            $customerId = $this->currentCustomerId();
            if ($customerId === null || $customerId <= 0) {
                return ['success' => false, 'ok' => false, 'error' => 'auth_required'];
            }
            $websiteId = (int)($params['website_id'] ?? $this->currentWebsiteId());
            $threads = $this->orderChat()->listThreadsForCustomer($customerId, $websiteId);

            return ['success' => true, 'ok' => true, 'threads' => $threads];
        } catch (\Throwable $e) {
            return ['success' => false, 'ok' => false, 'error' => 'b2b_order_chat_list_failed', 'message' => $e->getMessage()];
        }
    }

    private function orderChat(): \Weline\B2B\Service\B2BOrderThreadService
    {
        $service = ObjectManager::getInstance(\Weline\B2B\Service\B2BOrderThreadService::class);
        if (!$service instanceof \Weline\B2B\Service\B2BOrderThreadService) {
            throw new \RuntimeException('b2b_order_thread_service_missing');
        }

        return $service;
    }

    private function currentWebsiteId(): int
    {
        try {
            if (class_exists(\Weline\Websites\Model\Website::class)) {
                $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
                if (is_object($website) && method_exists($website, 'getId')) {
                    return max(0, (int)$website->getId());
                }
            }
        } catch (\Throwable) {
        }

        return 0;
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
            'summary' => 'B2B price candidate resolve, membership, hang payment, checkout credit quote preview',
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
                    'name' => 'membership.status',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Read membership CTA projection; session customer; 45s throttle',
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
                [
                    'name' => 'orderChat.open',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'order_uuid' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Open or create B2B order chat thread',
                ],
                [
                    'name' => 'orderChat.messages',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'thread_id' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'since_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List order chat messages since_id',
                ],
                [
                    'name' => 'orderChat.send',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'thread_id' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'body' => ['type' => 'string', 'required' => true, 'max_length' => 4000],
                        'role' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Send order chat message',
                ],
                [
                    'name' => 'orderChat.markSeen',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'thread_id' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                        'role' => ['type' => 'string', 'required' => false, 'max_length' => 16],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Mark order chat thread seen for role',
                ],
                [
                    'name' => 'orderChat.list',
                    'frontend' => true,
                    'auth' => 'customer',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 1,
                    'params' => [
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List order chat threads for customer',
                ],
                [
                    'name' => 'credit.quote',
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        'deposit_amount_minor' => ['type' => 'int', 'required' => true, 'min' => 0],
                        'currency' => ['type' => 'string', 'required' => false, 'max_length' => 8],
                        'website_id' => ['type' => 'int', 'required' => false, 'min' => 0],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Preview tob wholesale credit quote before freezeQuote',
                ],
            ],
        ];
    }
}
