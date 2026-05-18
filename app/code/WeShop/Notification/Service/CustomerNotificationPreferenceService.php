<?php

declare(strict_types=1);

namespace WeShop\Notification\Service;

use WeShop\Notification\Model\CustomerNotificationContact;
use WeShop\Notification\Model\CustomerNotificationPreference;
use Weline\Backend\Service\ChannelAdapterCollector;

class CustomerNotificationPreferenceService
{
    public const CHANNEL_INBOX = 'inbox';
    public const TOPIC_ORDER = 'order';

    private const ALLOWED_EXTERNAL_CHANNELS = ['email', 'sms', 'telegram', 'webhook'];

    public function __construct(
        private readonly CustomerNotificationContact $contactModel,
        private readonly CustomerNotificationPreference $preferenceModel,
        private readonly ChannelAdapterCollector $adapterCollector
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAvailableChannels(): array
    {
        $channels = [
            self::CHANNEL_INBOX => [
                'code' => self::CHANNEL_INBOX,
                'name' => (string) __('站内通知'),
                'requires_contact' => false,
                'available' => true,
            ],
        ];

        foreach ($this->adapterCollector->getAdapters() as $adapter) {
            $code = strtolower($adapter->getChannelCode());
            if (!in_array($code, self::ALLOWED_EXTERNAL_CHANNELS, true)) {
                continue;
            }

            $channels[$code] = [
                'code' => $code,
                'name' => (string) $adapter->getChannelName(),
                'requires_contact' => true,
                'available' => true,
                'config_fields' => $adapter->getConfigFields(),
            ];
        }

        return $channels;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getContacts(int $customerId, ?string $channelCode = null): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $query = $this->contactModel->clear()
            ->where(CustomerNotificationContact::schema_fields_CUSTOMER_ID, $customerId)
            ->where(CustomerNotificationContact::schema_fields_IS_ENABLED, 1);

        if ($channelCode !== null && trim($channelCode) !== '') {
            $query->where(CustomerNotificationContact::schema_fields_CHANNEL_CODE, strtolower(trim($channelCode)));
        }

        return $query->order(CustomerNotificationContact::schema_fields_IS_DEFAULT, 'DESC')
            ->order(CustomerNotificationContact::schema_fields_ID)
            ->select()
            ->fetchArray();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getContactsGrouped(int $customerId): array
    {
        $grouped = [];
        foreach ($this->getContacts($customerId) as $contact) {
            $channel = strtolower((string) ($contact[CustomerNotificationContact::schema_fields_CHANNEL_CODE] ?? ''));
            if ($channel === '') {
                continue;
            }

            $grouped[$channel][] = $contact;
        }

        return $grouped;
    }

    public function saveContact(int $customerId, string $channelCode, string $contactValue, array $options = []): int
    {
        $customerId = max(0, $customerId);
        $channelCode = strtolower(trim($channelCode));
        $contactValue = trim($contactValue);

        if ($customerId <= 0 || $channelCode === '' || $contactValue === '') {
            throw new \InvalidArgumentException((string) __('通知渠道和联系方式不能为空。'));
        }

        $available = $this->getAvailableChannels();
        if ($channelCode === self::CHANNEL_INBOX || !isset($available[$channelCode])) {
            throw new \InvalidArgumentException((string) __('该通知渠道暂不支持客户配置。'));
        }

        if ($channelCode === 'email' && !filter_var($contactValue, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException((string) __('请输入有效的通知邮箱。'));
        }

        $existing = $this->contactModel->clear()
            ->where(CustomerNotificationContact::schema_fields_CUSTOMER_ID, $customerId)
            ->where(CustomerNotificationContact::schema_fields_CHANNEL_CODE, $channelCode)
            ->where(CustomerNotificationContact::schema_fields_CONTACT_VALUE, $contactValue)
            ->find()
            ->fetch();

        $now = date('Y-m-d H:i:s');
        $isDefault = (bool) ($options['is_default'] ?? false);
        if ($isDefault || $this->getContacts($customerId, $channelCode) === []) {
            $this->clearDefault($customerId, $channelCode);
            $isDefault = true;
        }

        $contact = $existing && $existing->getId() ? $existing : clone $this->contactModel;
        $config = $this->buildChannelConfig($channelCode, $contactValue, (array) ($options['channel_config'] ?? []));

        $contact->setData(CustomerNotificationContact::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(CustomerNotificationContact::schema_fields_CHANNEL_CODE, $channelCode)
            ->setData(CustomerNotificationContact::schema_fields_CONTACT_VALUE, $contactValue)
            ->setData(CustomerNotificationContact::schema_fields_CONTACT_NAME, (string) ($options['contact_name'] ?? $contactValue))
            ->setChannelConfig($config)
            ->setData(CustomerNotificationContact::schema_fields_IS_DEFAULT, $isDefault ? 1 : 0)
            ->setData(CustomerNotificationContact::schema_fields_IS_VERIFIED, (bool) ($options['is_verified'] ?? false) ? 1 : 0)
            ->setData(CustomerNotificationContact::schema_fields_IS_ENABLED, 1)
            ->setData(CustomerNotificationContact::schema_fields_UPDATED_AT, $now);

        if (!$contact->getId()) {
            $contact->setData(CustomerNotificationContact::schema_fields_CREATED_AT, $now);
        }

        $contact->save();
        return (int) $contact->getId();
    }

    /**
     * @param array<int, string> $channels
     */
    public function saveTopicPreferences(int $customerId, string $topicCode, array $channels, string $minType = 'info'): void
    {
        $customerId = max(0, $customerId);
        $topicCode = trim($topicCode) !== '' ? trim($topicCode) : self::TOPIC_ORDER;
        $channels = $this->normalizeChannels($channels, $customerId, false);
        $now = date('Y-m-d H:i:s');

        foreach ($channels as $channelCode) {
            $existing = $this->preferenceModel->clear()
                ->where(CustomerNotificationPreference::schema_fields_CUSTOMER_ID, $customerId)
                ->where(CustomerNotificationPreference::schema_fields_TOPIC_CODE, $topicCode)
                ->where(CustomerNotificationPreference::schema_fields_CHANNEL_CODE, $channelCode)
                ->find()
                ->fetch();

            $preference = $existing && $existing->getId() ? $existing : clone $this->preferenceModel;
            $preference->setData(CustomerNotificationPreference::schema_fields_CUSTOMER_ID, $customerId)
                ->setData(CustomerNotificationPreference::schema_fields_TOPIC_CODE, $topicCode)
                ->setData(CustomerNotificationPreference::schema_fields_CHANNEL_CODE, $channelCode)
                ->setData(CustomerNotificationPreference::schema_fields_MIN_TYPE, $minType)
                ->setData(CustomerNotificationPreference::schema_fields_IS_ENABLED, 1)
                ->setData(CustomerNotificationPreference::schema_fields_UPDATED_AT, $now);

            if (!$preference->getId()) {
                $preference->setData(CustomerNotificationPreference::schema_fields_CREATED_AT, $now);
            }

            $preference->save();
        }

        $this->disableMissingTopicChannels($customerId, $topicCode, $channels, $now);
    }

    /**
     * @return array<int, string>
     */
    public function getPreferredChannels(int $customerId, string $topicCode = self::TOPIC_ORDER): array
    {
        if ($customerId <= 0) {
            return [self::CHANNEL_INBOX];
        }

        $rows = $this->preferenceModel->clear()
            ->where(CustomerNotificationPreference::schema_fields_CUSTOMER_ID, $customerId)
            ->where(CustomerNotificationPreference::schema_fields_TOPIC_CODE, $topicCode)
            ->where(CustomerNotificationPreference::schema_fields_IS_ENABLED, 1)
            ->select()
            ->fetchArray();

        $channels = [];
        foreach ($rows as $row) {
            $channel = strtolower(trim((string) ($row[CustomerNotificationPreference::schema_fields_CHANNEL_CODE] ?? '')));
            if ($channel !== '') {
                $channels[] = $channel;
            }
        }

        return $channels !== [] ? array_values(array_unique($channels)) : [self::CHANNEL_INBOX];
    }

    /**
     * @param array<int|string, mixed> $requested
     * @return array<int, string>
     */
    public function normalizeChannels(array $requested, int $customerId = 0, bool $guestCheckout = false): array
    {
        $available = $this->getAvailableChannels();
        $normalized = [];

        foreach ($requested as $value) {
            $channel = strtolower(trim((string) $value));
            if ($channel === '' || !isset($available[$channel])) {
                continue;
            }

            if ($guestCheckout && $channel !== 'email') {
                continue;
            }

            if ($channel !== self::CHANNEL_INBOX && $customerId > 0 && $this->getContacts($customerId, $channel) === []) {
                continue;
            }

            $normalized[] = $channel;
        }

        if ($normalized === []) {
            $normalized[] = $guestCheckout ? 'email' : self::CHANNEL_INBOX;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCheckoutOptions(int $customerId, bool $guestCheckout = false, string $guestEmail = ''): array
    {
        if ($guestCheckout) {
            return [
                'available_channels' => ['email' => ['code' => 'email', 'name' => (string) __('邮箱'), 'available' => $guestEmail !== '']],
                'preferred_channels' => ['email'],
                'contacts_grouped' => [],
                'can_manage' => false,
            ];
        }

        return [
            'available_channels' => $this->getAvailableChannels(),
            'preferred_channels' => $this->getPreferredChannels($customerId),
            'contacts_grouped' => $this->getContactsGrouped($customerId),
            'can_manage' => $customerId > 0,
        ];
    }

    private function clearDefault(int $customerId, string $channelCode): void
    {
        $rows = $this->getContacts($customerId, $channelCode);
        foreach ($rows as $row) {
            $contact = clone $this->contactModel;
            $contact->load((int) ($row[CustomerNotificationContact::schema_fields_ID] ?? 0));
            if ($contact->getId()) {
                $contact->setData(CustomerNotificationContact::schema_fields_IS_DEFAULT, 0)
                    ->setData(CustomerNotificationContact::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                    ->save();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChannelConfig(string $channelCode, string $contactValue, array $extra): array
    {
        $base = match ($channelCode) {
            'email' => ['to_email' => $contactValue],
            'sms' => ['phone' => $contactValue],
            'telegram' => ['chat_id' => $contactValue],
            'webhook' => ['webhook_url' => $contactValue],
            default => ['value' => $contactValue],
        };

        return array_replace($base, $extra);
    }

    /**
     * @param array<int, string> $activeChannels
     */
    private function disableMissingTopicChannels(int $customerId, string $topicCode, array $activeChannels, string $now): void
    {
        $rows = $this->preferenceModel->clear()
            ->where(CustomerNotificationPreference::schema_fields_CUSTOMER_ID, $customerId)
            ->where(CustomerNotificationPreference::schema_fields_TOPIC_CODE, $topicCode)
            ->select()
            ->fetchArray();

        foreach ($rows as $row) {
            $channel = strtolower((string) ($row[CustomerNotificationPreference::schema_fields_CHANNEL_CODE] ?? ''));
            if (in_array($channel, $activeChannels, true)) {
                continue;
            }

            $preference = clone $this->preferenceModel;
            $preference->load((int) ($row[CustomerNotificationPreference::schema_fields_ID] ?? 0));
            if ($preference->getId()) {
                $preference->setData(CustomerNotificationPreference::schema_fields_IS_ENABLED, 0)
                    ->setData(CustomerNotificationPreference::schema_fields_UPDATED_AT, $now)
                    ->save();
            }
        }
    }
}
