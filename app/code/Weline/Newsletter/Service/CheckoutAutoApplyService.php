<?php

declare(strict_types=1);

namespace Weline\Newsletter\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Marketing\Api\Quote\DiscountQuoteServiceInterface;
use Weline\Marketing\Service\MarketingCheckoutCouponSession;
use Weline\Newsletter\Api\NewsletterCheckoutCouponAutoApplyInterface;
use Weline\Newsletter\Model\Subscriber;

/**
 * Best-effort checkout coupon apply:
 * - T1: after welcome gift issue (same session)
 * - T2: when checkout identity/email is recognizable
 */
final class CheckoutAutoApplyService implements NewsletterCheckoutCouponAutoApplyInterface
{
    /**
     * T1: apply freshly issued welcome coupon into toc checkout session.
     *
     * @param array<string, mixed> $context
     */
    public function applyIssuedCoupon(string $couponCode, array $context = []): bool
    {
        $result = $this->applyCode($couponCode, $context);

        return !empty($result['applied']);
    }

    /**
     * T2: match newsletter ledger by email / customer_id → apply issued gift coupon.
     *
     * @param array<string, mixed> $context
     * @return array{applied:bool,coupon_code:string,reason:string}
     */
    public function applyForRecognizedEmail(array $context): array
    {
        if (!$this->isTocAudience($context)) {
            return $this->skip('audience_tob');
        }

        $email = $this->normalizeEmail((string)($context['email'] ?? ''));
        $customerId = \max(0, (int)($context['customer_id'] ?? 0));
        $websiteId = isset($context['website_id'])
            ? \max(0, (int)$context['website_id'])
            : $this->resolveWebsiteId();

        $subscriber = $this->findEligibleSubscriber($websiteId, $email, $customerId);
        if ($subscriber === null) {
            return $this->skip('no_issued_gift');
        }

        $code = \strtoupper(\trim((string)$subscriber->getData(Subscriber::schema_fields_COUPON_CODE)));
        if ($code === '') {
            return $this->skip('empty_coupon_code');
        }

        return $this->applyCode($code, $context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{applied:bool,coupon_code:string,reason:string}
     */
    private function applyCode(string $couponCode, array $context = []): array
    {
        $code = \strtoupper(\trim($couponCode));
        if ($code === '') {
            return $this->skip('empty_code');
        }
        if (!$this->isTocAudience($context)) {
            return $this->skip('audience_tob');
        }

        try {
            /** @var MarketingCheckoutCouponSession $session */
            $session = ObjectManager::getInstance(MarketingCheckoutCouponSession::class);
            $cartType = $this->resolveCartType($context);
            if (!$session->couponsAllowedForCartType($cartType)) {
                return $this->skip('coupons_disallowed');
            }

            $quotes = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(DiscountQuoteServiceInterface::class);
            if (!$quotes instanceof DiscountQuoteServiceInterface) {
                return $this->skip('quotes_unavailable');
            }

            $params = [
                'cart_type' => $cartType,
                'selling_mode' => $cartType,
            ];
            if (isset($context['lines']) && \is_array($context['lines'])) {
                $params['lines'] = $context['lines'];
            }
            if (isset($context['currency'])) {
                $params['currency'] = (string)$context['currency'];
            }

            $result = $session->applyCoupon($code, $quotes, $params);
            if (!empty($result['success'])) {
                return [
                    'applied' => true,
                    'coupon_code' => $code,
                    'reason' => '',
                ];
            }

            return $this->skip('apply_failed:' . (string)($result['message'] ?? 'unknown'), $code);
        } catch (\Throwable $e) {
            return $this->skip('exception:' . $e->getMessage(), $code);
        }
    }

    /**
     * @return Subscriber|null
     */
    private function findEligibleSubscriber(int $websiteId, string $email, int $customerId): ?Subscriber
    {
        /** @var Subscriber $model */
        $model = ObjectManager::getInstance(Subscriber::class);

        if ($email !== '' && \filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $row = $model->clear()
                ->where(Subscriber::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Subscriber::schema_fields_EMAIL, $email)
                ->where(Subscriber::schema_fields_GIFT_STATUS, Subscriber::GIFT_ISSUED)
                ->find()
                ->fetch();
            if ($row instanceof Subscriber && (int)$row->getId() > 0) {
                $code = \trim((string)$row->getData(Subscriber::schema_fields_COUPON_CODE));
                if ($code !== '') {
                    return $row;
                }
            }
        }

        if ($customerId > 0) {
            $row = $model->clear()
                ->where(Subscriber::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Subscriber::schema_fields_CUSTOMER_ID, $customerId)
                ->where(Subscriber::schema_fields_GIFT_STATUS, Subscriber::GIFT_ISSUED)
                ->find()
                ->fetch();
            if ($row instanceof Subscriber && (int)$row->getId() > 0) {
                $code = \trim((string)$row->getData(Subscriber::schema_fields_COUPON_CODE));
                if ($code !== '') {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isTocAudience(array $context): bool
    {
        $mode = \strtolower(\trim((string)($context['selling_mode'] ?? $context['cart_type'] ?? 'toc')));

        return !\in_array($mode, ['tob', 'wholesale'], true);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveCartType(array $context): string
    {
        $mode = \strtolower(\trim((string)($context['selling_mode'] ?? $context['cart_type'] ?? 'toc')));

        return $mode !== '' ? $mode : 'toc';
    }

    private function normalizeEmail(string $email): string
    {
        return \strtolower(\trim($email));
    }

    private function resolveWebsiteId(): int
    {
        try {
            return \max(0, RequestContext::getWelineWebsiteId());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{applied:bool,coupon_code:string,reason:string}
     */
    private function skip(string $reason, string $code = ''): array
    {
        return [
            'applied' => false,
            'coupon_code' => $code,
            'reason' => $reason,
        ];
    }
}
