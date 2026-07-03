<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelAdditional;

class PixelEventPersistenceService
{
    private const PASSIVE_EVENTS_WITH_BROWSER_INFO = [
        'page_view' => true,
        'page_load' => true,
        'homepage' => true,
        'blog' => true,
        'category' => true,
        'search_result_view' => true,
    ];

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function persistPrepared(array $post, array $data): array
    {
        /** @var Pixel $pixel */
        $pixel = ObjectManager::make(Pixel::class);
        try {
            $pixel->save($data);
        } catch (Exception $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $pixelId = $pixel->getId();
        $pixelAdditionalId = null;
        $additionalData = $post;

        if ($pixelId && $this->shouldPersistAdditional($post)) {
            try {
                $this->normalizeAbTestFields($post, $additionalData);

                /** @var PixelAdditional $pixelAdditional */
                $pixelAdditional = ObjectManager::make(PixelAdditional::class);
                $pixelAdditional->setPixelId((int)$pixelId)
                    ->setTotalEventData(json_encode($additionalData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}')
                    ->save();

                $pixelAdditionalId = $pixelAdditional->getId() ?: null;
            } catch (Exception $e) {
                w_log_error('Pixel Additional Save Error: ' . $e->getMessage());
            }
        }

        $responseData = [
            'pixel_id' => $pixelId,
            'pixel_additional_id' => $pixelAdditionalId,
        ];

        if (isset($additionalData['testId']) || isset($additionalData['variant'])) {
            $responseData['ab_test'] = [
                'testId' => $additionalData['testId'] ?? null,
                'variant' => $additionalData['variant'] ?? null,
            ];
        }

        return $responseData;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function shouldPersistAdditional(array $post): bool
    {
        foreach (['testId', 'variant', 'test_id', 'testVariant', 'items', 'product_id', 'order_id', 'transaction_id'] as $key) {
            if (isset($post[$key]) && $post[$key] !== '' && $post[$key] !== []) {
                return true;
            }
        }

        if ($this->isFilteredTraffic($post['traffic'] ?? null) || $this->isFilteredTraffic($post['forwarding'] ?? null)) {
            return true;
        }

        if (isset($post['additionalInfo']) && \is_array($post['additionalInfo'])) {
            if ($this->isFilteredTraffic($post['additionalInfo']['traffic'] ?? null)
                || $this->isFilteredTraffic($post['additionalInfo']['forwarding'] ?? null)
            ) {
                return true;
            }
        }

        $event = (string)($post['eventName'] ?? $post['event'] ?? '');
        if (\str_starts_with($event, 'account_')) {
            return false;
        }

        return !isset(self::PASSIVE_EVENTS_WITH_BROWSER_INFO[$event]);
    }

    private function isFilteredTraffic(mixed $value): bool
    {
        return \is_array($value)
            && (
                ($value['filtered'] ?? false) === true
                || ($value['allowed'] ?? true) === false
                || !empty($value['reasons'])
            );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $additionalData
     */
    private function normalizeAbTestFields(array $post, array &$additionalData): void
    {
        if (!isset($post['testId']) && !isset($post['variant']) && !isset($post['test_id']) && !isset($post['testVariant'])) {
            return;
        }
        if (isset($post['test_id']) && !isset($additionalData['testId'])) {
            $additionalData['testId'] = substr((string)$post['test_id'], 0, 255);
        } elseif (isset($post['testId'])) {
            $additionalData['testId'] = substr((string)$post['testId'], 0, 255);
        }

        if (isset($post['testVariant']) && !isset($additionalData['variant'])) {
            $additionalData['variant'] = substr((string)$post['testVariant'], 0, 10);
        } elseif (isset($post['variant'])) {
            $additionalData['variant'] = substr((string)$post['variant'], 0, 10);
        }
    }
}
