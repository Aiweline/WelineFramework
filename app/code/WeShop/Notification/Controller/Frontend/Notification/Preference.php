<?php

declare(strict_types=1);

namespace WeShop\Notification\Controller\Frontend\Notification;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Notification\Service\CustomerNotificationPreferenceService;
use Weline\Framework\App\Controller\FrontendController;

class Preference extends FrontendController
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly CustomerNotificationPreferenceService $preferenceService
    ) {
    }

    public function save(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('请先登录后再配置通知渠道。'));
            $this->redirect('customer/account/login');
            return '';
        }

        try {
            $channelCode = (string) ($this->request->getPost('channel_code', '') ?? '');
            $contactValue = (string) ($this->request->getPost('contact_value', '') ?? '');

            if (trim($channelCode) !== '' && trim($contactValue) !== '') {
                $this->preferenceService->saveContact($customerId, $channelCode, $contactValue, [
                    'contact_name' => (string) ($this->request->getPost('contact_name', '') ?? ''),
                    'is_default' => (bool) ($this->request->getPost('is_default', true) ?? true),
                    'is_verified' => $channelCode === 'email',
                ]);
            }

            $channels = $this->readChannels();
            if (trim($channelCode) !== '' && trim($contactValue) !== '') {
                $channels[] = $channelCode;
                $channels = array_values(array_unique($channels));
            }
            if ($channels !== []) {
                $this->preferenceService->saveTopicPreferences(
                    $customerId,
                    CustomerNotificationPreferenceService::TOPIC_ORDER,
                    $channels
                );
            }

            $this->getMessageManager()->addSuccess(__('通知渠道配置已保存。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        $this->redirect('customer/account/index#notification-preferences');
        return '';
    }

    public function post(): string
    {
        return $this->save();
    }

    /**
     * @return array<int, string>
     */
    private function readChannels(): array
    {
        $channels = $this->request->getPost('notification_channels', []);
        if (!is_array($channels)) {
            $channels = [$channels];
        }

        return array_values(array_filter(array_map(static fn(mixed $value): string => trim((string) $value), $channels)));
    }
}
