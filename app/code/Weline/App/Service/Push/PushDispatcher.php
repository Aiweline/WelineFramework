<?php

declare(strict_types=1);

namespace Weline\App\Service\Push;

use Weline\App\Model\AppDeviceToken;
use Weline\Framework\Manager\ObjectManager;

final class PushDispatcher
{
    public function dispatch(int $websiteId, string $title, string $body, array $payload = []): array
    {
        $result = ['sent' => 0, 'disabled' => 0, 'skipped' => 0];
        /** @var AppDeviceToken $device */
        $device = ObjectManager::getInstance(AppDeviceToken::class);
        $rows = $device->clear()
            ->where(AppDeviceToken::schema_fields_website_id, $websiteId)
            ->where(AppDeviceToken::schema_fields_enabled, 1)
            ->fetch()
            ->getItems();
        foreach ($rows as $row) {
            $resultData = $this->sendToPlatform(
                (string)$row->getData(AppDeviceToken::schema_fields_platform),
                (string)$row->getData(AppDeviceToken::schema_fields_token),
                $title,
                $body,
                $payload
            );
            if ($resultData['ok'] ?? false) {
                $result['sent']++;
            } elseif (($resultData['reason'] ?? '') === 'token_invalid') {
                $row->setData([
                    AppDeviceToken::schema_fields_enabled => 0,
                    AppDeviceToken::schema_fields_updated_at => date('Y-m-d H:i:s'),
                ]);
                $row->save();
                $result['disabled']++;
            } else {
                $result['skipped']++;
            }
        }
        return $result;
    }

    public function countEnabled(int $websiteId): int
    {
        /** @var AppDeviceToken $device */
        $device = ObjectManager::getInstance(AppDeviceToken::class);
        $rows = $device->clear()
            ->where(AppDeviceToken::schema_fields_website_id, $websiteId)
            ->where(AppDeviceToken::schema_fields_enabled, 1)
            ->fetch()
            ->getItems();
        return count($rows);
    }

    protected function sendToPlatform(string $platform, string $token, string $title, string $body, array $payload = []): array
    {
        return ['ok' => false, 'retry' => false, 'reason' => 'provider_not_configured'];
    }
}
