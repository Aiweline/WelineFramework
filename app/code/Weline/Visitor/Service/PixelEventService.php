<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelAdditional;

class PixelEventService
{
    public function __construct(
        private readonly Request $request
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function track(array $payload): array
    {
        $post = $this->normalizePayload($payload);
        $post['source'] = $post['source'] ?? 'worker';

        $ip = $post['ip'] ?? $this->request->clientIP();
        if (!empty($ip) && !filter_var((string)$ip, FILTER_VALIDATE_IP)) {
            $ip = $this->request->clientIP();
        }

        $websiteId = $this->resolveWebsiteId($post);
        if (empty($post['eventName']) && empty($post['event'])) {
            $post['eventName'] = 'click';
        }

        $url = (string)($post['url'] ?? '');
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            if (!str_starts_with($url, 'http') && !str_starts_with($url, '//')) {
                $url = '';
            }
        }

        $data = [
            'url' => $url,
            'module' => substr((string)($post['module'] ?? ''), 0, 255),
            'name' => substr((string)($post['name'] ?? ''), 0, 255),
            'event' => substr((string)($post['eventName'] ?? $post['event'] ?? 'click'), 0, 255),
            'value' => max(0, (int)($post['value'] ?? 0)),
            'lang' => substr((string)($post['userLang'] ?? $post['lang'] ?? ''), 0, 255),
            'currency' => substr((string)($post['currency'] ?? ''), 0, 255),
            'website_id' => max(0, $websiteId),
            'referer' => substr((string)($post['referer'] ?? ''), 0, 255),
            'user_id' => max(0, (int)($post['userId'] ?? 0)),
            'user_agent' => substr((string)($post['userAgent'] ?? ''), 0, 255),
            'ip' => (string)$ip,
            'browser_info' => json_encode([
                'additionalInfo' => is_array($post['additionalInfo'] ?? null) ? $post['additionalInfo'] : [],
                'screen' => is_array($post['screen'] ?? null) ? $post['screen'] : [],
            ], JSON_UNESCAPED_UNICODE),
        ];

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

        if ($pixelId) {
            try {
                $this->normalizeAbTestFields($post, $additionalData);

                /** @var PixelAdditional $pixelAdditional */
                $pixelAdditional = ObjectManager::make(PixelAdditional::class);
                $pixelAdditional->setPixelId((int)$pixelId)
                    ->setTotalEventData(json_encode($additionalData, JSON_UNESCAPED_UNICODE) ?: '{}')
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

        return [
            'success' => true,
            'error' => false,
            'code' => 200,
            'msg' => (string)__('请求成功！'),
            'message' => (string)__('请求成功！'),
            'data' => $responseData,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        if (isset($payload['encrypted'], $payload['version'])) {
            /** @var PixelEncryptionService $encryptionService */
            $encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
            $decoded = $encryptionService->decrypt($payload['encrypted'], $payload['version']);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException((string)__('解密后的数据格式错误'));
            }
            return $decoded;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function resolveWebsiteId(array $post): int
    {
        if (isset($post['websiteId']) && $post['websiteId'] !== '') {
            return (int)$post['websiteId'];
        }
        if (isset($post['siteId']) && $post['siteId'] !== '') {
            return (int)$post['siteId'];
        }

        return (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
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
