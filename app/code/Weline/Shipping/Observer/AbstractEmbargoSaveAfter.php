<?php

declare(strict_types=1);

namespace Weline\Shipping\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\DestinationAdminService;
use Weline\Shipping\Service\EmbargoAdminService;

abstract class AbstractEmbargoSaveAfter implements ObserverInterface
{
    /**
     * @param array<string, mixed>|Event $event
     */
    protected function persist(Event &$event, string $scopeType, string $idKey): void
    {
        $eventData = $event->getData();
        if (!is_array($eventData) || !array_key_exists($idKey, $eventData) || $eventData[$idKey] === null) {
            return;
        }
        $scopeId = (int)$eventData[$idKey];
        $postData = $event->getData('post_data');
        if (!is_array($postData)) {
            return;
        }
        $extensions = $postData['extensions'] ?? [];
        $shipping = is_array($extensions) ? ($extensions['shipping'] ?? []) : [];
        if (!is_array($shipping)) {
            return;
        }

        if (array_key_exists('embargo', $shipping)) {
            $embargo = is_array($shipping['embargo']) ? $shipping['embargo'] : [];
            /** @var EmbargoAdminService $admin */
            $admin = ObjectManager::getInstance(EmbargoAdminService::class);
            $rows = $admin->rowsFromExtensionPayload($embargo);
            $admin->replaceForScope($scopeType, $scopeId, $rows);
        }

        if (array_key_exists('destination', $shipping)) {
            $destination = is_array($shipping['destination']) ? $shipping['destination'] : [];
            /** @var DestinationAdminService $destAdmin */
            $destAdmin = ObjectManager::getInstance(DestinationAdminService::class);
            $destRows = $destAdmin->rowsFromExtensionPayload($destination);
            $destAdmin->replaceForScope($scopeType, $scopeId, $destRows);
        }
    }
}
