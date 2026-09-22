<?php

declare(strict_types=1);

namespace Weline\Newsletter\Service;

use Weline\Framework\DateTime\Timezone;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Newsletter\Model\Subscriber;

/**
 * Single write path for storefront newsletter subscribe (Controller + BinQuery).
 */
final class SubscribeService
{
    private const EMAIL_MAX_LEN = 254;

    public function __construct(
        private readonly ?SubscribeGiftIssuer $giftIssuer = null,
        private readonly ?NewsletterMailSender $mailSender = null,
        private readonly ?CheckoutAutoApplyService $autoApply = null,
        private readonly ?SubscribeGiftConfig $giftConfig = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function subscribe(array $input): array
    {
        $email = $this->normalizeEmail((string)($input['email'] ?? ''));
        if ($email === '' || !$this->isValidEmail($email)) {
            return $this->fail((string)\__('请输入有效的邮箱地址。'));
        }

        $topicPromo = $this->boolOrDefault($input['topic_promo'] ?? null, true);
        $topicNew = $this->boolOrDefault($input['topic_new_arrivals'] ?? null, true);
        $sourceSurface = $this->normalizeSurface((string)($input['source_surface'] ?? 'api'));
        $websiteId = isset($input['website_id'])
            ? \max(0, (int)$input['website_id'])
            : $this->resolveWebsiteId();
        $locale = \trim((string)($input['locale'] ?? $this->resolveLocale()));
        $customerId = isset($input['customer_id']) ? \max(0, (int)$input['customer_id']) : 0;

        /** @var Subscriber $model */
        $model = ObjectManager::getInstance(Subscriber::class);
        $existing = $model->clear()
            ->where(Subscriber::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Subscriber::schema_fields_EMAIL, $email)
            ->find()
            ->fetch();

        $now = Timezone::utcNowSql();
        $preferenceUpdated = false;
        $isNew = !($existing instanceof Subscriber) || !(int)$existing->getId();

        if ($isNew) {
            $model->clearData()
                ->setData(Subscriber::schema_fields_WEBSITE_ID, $websiteId)
                ->setData(Subscriber::schema_fields_EMAIL, $email)
                ->setData(Subscriber::schema_fields_STATUS, Subscriber::STATUS_ACTIVE)
                ->setData(Subscriber::schema_fields_TOPIC_PROMO, $topicPromo ? 1 : 0)
                ->setData(Subscriber::schema_fields_TOPIC_NEW_ARRIVALS, $topicNew ? 1 : 0)
                ->setData(Subscriber::schema_fields_CUSTOMER_ID, $customerId > 0 ? $customerId : null)
                ->setData(Subscriber::schema_fields_SOURCE_SURFACE, $sourceSurface)
                ->setData(Subscriber::schema_fields_LOCALE, $locale)
                ->setData(Subscriber::schema_fields_GIFT_STATUS, Subscriber::GIFT_NONE)
                ->setData(Subscriber::schema_fields_CREATED_AT, $now)
                ->setData(Subscriber::schema_fields_UPDATED_AT, $now)
                ->save();
            $subscriber = $model;
        } else {
            /** @var Subscriber $subscriber */
            $subscriber = $existing;
            $preferenceUpdated = true;
            $subscriber
                ->setData(Subscriber::schema_fields_STATUS, Subscriber::STATUS_ACTIVE)
                ->setData(Subscriber::schema_fields_TOPIC_PROMO, $topicPromo ? 1 : 0)
                ->setData(Subscriber::schema_fields_TOPIC_NEW_ARRIVALS, $topicNew ? 1 : 0)
                ->setData(Subscriber::schema_fields_SOURCE_SURFACE, $sourceSurface)
                ->setData(Subscriber::schema_fields_LOCALE, $locale !== '' ? $locale : (string)$subscriber->getData(Subscriber::schema_fields_LOCALE))
                ->setData(Subscriber::schema_fields_UPDATED_AT, $now);
            if ($customerId > 0) {
                $subscriber->setData(Subscriber::schema_fields_CUSTOMER_ID, $customerId);
            }
            $subscriber->save();
        }

        $subscriberId = (int)$subscriber->getId();
        $giftStatus = (string)$subscriber->getData(Subscriber::schema_fields_GIFT_STATUS);
        $couponCode = \strtoupper(\trim((string)$subscriber->getData(Subscriber::schema_fields_COUPON_CODE)));
        $issuedNow = false;

        if (!$preferenceUpdated || !\in_array($giftStatus, [Subscriber::GIFT_ISSUED, Subscriber::GIFT_REDEEMED], true)) {
            if (!\in_array($giftStatus, [Subscriber::GIFT_ISSUED, Subscriber::GIFT_REDEEMED], true)) {
                $giftResult = $this->giftIssuer()->issueIfEligible($subscriber);
                if (!empty($giftResult['issued'])) {
                    $issuedNow = true;
                    $couponCode = (string)($giftResult['coupon_code'] ?? '');
                    $giftStatus = Subscriber::GIFT_ISSUED;
                    $this->autoApply()->applyIssuedCoupon($couponCode, $input);
                }
            }
        }

        if ($preferenceUpdated) {
            // Preference update: do not force another mail.
            $message = (string)\__('您已订阅，主题偏好已更新。');
        } elseif ($issuedNow && $couponCode !== '') {
            $this->mailSender()->sendGift($email, [
                'email' => $email,
                'coupon_code' => $couponCode,
                'discount_label' => $this->discountLabel(),
                'valid_until' => $this->validUntilLabel(),
                'shop_url' => (string)($input['shop_url'] ?? '/'),
                'topics_label' => $this->topicsLabel($topicPromo, $topicNew),
                'site_name' => (string)($input['site_name'] ?? ''),
            ]);
            $message = (string)\__('订阅成功！欢迎礼优惠券已发送到您的邮箱。');
        } else {
            $this->mailSender()->sendWelcome($email, [
                'email' => $email,
                'topics_label' => $this->topicsLabel($topicPromo, $topicNew),
                'site_name' => (string)($input['site_name'] ?? ''),
            ]);
            $message = (string)\__('订阅成功！感谢您的关注。');
        }

        $payload = [
            'ok' => true,
            'message' => $message,
            'subscriber_id' => $subscriberId,
            'email' => $email,
            'gift_status' => $giftStatus,
        ];
        if ($preferenceUpdated) {
            $payload['preference_updated'] = true;
        }
        if ($couponCode !== '') {
            $payload['coupon_code'] = $couponCode;
        }

        return $payload;
    }

    public function normalizeEmail(string $email): string
    {
        $email = \strtolower(\trim($email));
        if (\strlen($email) > self::EMAIL_MAX_LEN) {
            return '';
        }

        return $email;
    }

    public function isValidEmail(string $email): bool
    {
        if ($email === '' || \strlen($email) > self::EMAIL_MAX_LEN) {
            return false;
        }

        return (bool)\filter_var($email, \FILTER_VALIDATE_EMAIL);
    }

    private function normalizeSurface(string $surface): string
    {
        $surface = \strtolower(\trim($surface));

        return match ($surface) {
            'footer', 'popup', 'api' => $surface,
            default => 'api',
        };
    }

    private function boolOrDefault(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        $s = \strtolower(\trim((string)$value));
        if (\in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($s, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    private function resolveWebsiteId(): int
    {
        try {
            return \max(0, RequestContext::getWelineWebsiteId());
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveLocale(): string
    {
        try {
            $lang = \trim((string)RequestContext::getWelineUserLang());

            return ($lang !== '' && $lang !== 'default') ? $lang : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function topicsLabel(bool $promo, bool $newArrivals): string
    {
        $parts = [];
        if ($promo) {
            $parts[] = (string)\__('优惠活动');
        }
        if ($newArrivals) {
            $parts[] = (string)\__('上新资讯');
        }

        return $parts === [] ? (string)\__('邮件资讯') : \implode(' / ', $parts);
    }

    private function discountLabel(): string
    {
        $cfg = $this->giftConfig()->read();
        $type = (string)($cfg['discount_type'] ?? 'percentage');
        $value = (float)($cfg['discount_value'] ?? 10);
        if ($type === 'percentage') {
            return \rtrim(\rtrim(\number_format($value, 2, '.', ''), '0'), '.') . '%';
        }

        return (string)$value;
    }

    private function validUntilLabel(): string
    {
        $days = \max(1, (int)($this->giftConfig()->read()['valid_days'] ?? 14));

        return \gmdate('Y-m-d', \time() + 86400 * $days);
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'errors' => ['email' => $message],
        ];
    }

    private function giftIssuer(): SubscribeGiftIssuer
    {
        return $this->giftIssuer ?? ObjectManager::getInstance(SubscribeGiftIssuer::class);
    }

    private function mailSender(): NewsletterMailSender
    {
        return $this->mailSender ?? ObjectManager::getInstance(NewsletterMailSender::class);
    }

    private function autoApply(): CheckoutAutoApplyService
    {
        return $this->autoApply ?? ObjectManager::getInstance(CheckoutAutoApplyService::class);
    }

    private function giftConfig(): SubscribeGiftConfig
    {
        return $this->giftConfig ?? ObjectManager::getInstance(SubscribeGiftConfig::class);
    }
}
