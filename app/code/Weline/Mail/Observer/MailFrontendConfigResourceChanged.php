<?php

declare(strict_types=1);

namespace Weline\Mail\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailFrontendFeatureConfig;
use Weline\Mail\Service\MailFrontendStorefrontCacheInvalidator;

final class MailFrontendConfigResourceChanged implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $changes = $event->getData('changes');
        if (!is_array($changes)) {
            $changes = [$event->getData()];
        }
        $hit = false;
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $field = (string)($change['path'] ?? $change['field'] ?? $change['key'] ?? '');
            if ($field !== '' && str_starts_with($field, 'mail/frontend_register/')) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            // Also accept single field payloads from SystemConfig publishers.
            $field = (string)($event->getData('path') ?? $event->getData('field') ?? $event->getData('key') ?? '');
            $hit = $field !== '' && str_starts_with($field, 'mail/frontend_register/');
        }
        if (!$hit) {
            return;
        }
        ObjectManager::getInstance(MailFrontendStorefrontCacheInvalidator::class)
            ->clearForMailFrontendConfig('mail_frontend_config');
        unset($event);
        // Keep feature config class referenced for contract tests.
        class_exists(MailFrontendFeatureConfig::class);
    }
}
