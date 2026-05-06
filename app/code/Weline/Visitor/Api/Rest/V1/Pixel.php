<?php

namespace Weline\Visitor\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelAdditional;
use Weline\Visitor\Service\PixelEncryptionService;

class Pixel extends FrontendRestController
{
    public function __construct(
        private \Weline\Visitor\Model\Pixel $pixel,
        private PixelAdditional $pixelAdditional
    ) {
    }

    public function postIndex()
    {
        $post = $this->request->getBodyParams();

        if (isset($post['encrypted'], $post['version'])) {
            try {
                /** @var PixelEncryptionService $encryptionService */
                $encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
                $post = $encryptionService->decrypt($post['encrypted'], $post['version']);

                if (!is_array($post)) {
                    return $this->error('解密后的数据格式错误');
                }
            } catch (\Exception $e) {
                return $this->error('解密失败：' . $e->getMessage());
            }
        }

        $post['source'] = $post['source'] ?? 'direct';
        $ip = $post['ip'] ?? $this->request->clientIP();
        if (!empty($ip) && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = $this->request->clientIP();
        }

        $websiteId = 0;
        if (isset($post['websiteId']) && $post['websiteId'] !== '') {
            $websiteId = (int)$post['websiteId'];
        } elseif (isset($post['siteId']) && $post['siteId'] !== '') {
            $websiteId = (int)$post['siteId'];
        } else {
                $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);
            }

        if (empty($post['eventName']) && empty($post['event'])) {
            $post['eventName'] = 'click';
        }

        $url = $post['url'] ?? '';
        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
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

        try {
            $this->pixel->save($data);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }

        $pixelId = $this->pixel->getId();
        $pixelAdditionalId = null;
        $additionalData = $post;

        if ($pixelId) {
            try {
                if (isset($post['testId']) || isset($post['variant']) || isset($post['test_id']) || isset($post['testVariant'])) {
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

        return $this->success('请求成功！', $responseData);
    }
}
