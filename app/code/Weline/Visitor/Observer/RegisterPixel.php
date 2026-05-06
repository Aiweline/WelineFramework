<?php

declare(strict_types=1);

namespace Weline\Visitor\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\PixelEncryptionService;

class RegisterPixel implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            $payload = $event->getData();
            $user = $this->resolveEventValue($payload, 'user');
            $request = $this->resolveEventValue($payload, 'request');

            if (!$user || !$request) {
                return;
            }

            /** @var PixelEncryptionService $encryptionService */
            $encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
            $token = $encryptionService->getCurrentVersionToken();

            if (!$token) {
                return;
            }

            $websiteId = (int)(\Weline\Framework\Env\WelineEnv::getWebsiteId() ?? 0);

            $pixelData = [
                'url' => $this->resolveRequestUri($request),
                'module' => 'Weline_Frontend',
                'name' => 'register',
                'eventName' => 'register',
                'value' => 0,
                'userLang' => \Weline\Framework\Env\WelineEnv::server('WELINE_USER_LANG', 'zh-CN'),
                'currency' => \Weline\Framework\Env\WelineEnv::server('WELINE_USER_CURRENCY', 'RMB'),
                'websiteId' => $websiteId,
                'siteId' => $websiteId,
                'referer' => method_exists($request, 'getReferer') ? (string)$request->getReferer() : '',
                'userId' => $this->resolveUserId($user),
                'userAgent' => method_exists($request, 'getServer') ? (string)$request->getServer('HTTP_USER_AGENT') : '',
                'ip' => method_exists($request, 'clientIP') ? (string)$request->clientIP() : '',
                'additionalInfo' => [
                    'innerWidth' => 0,
                    'innerHeight' => 0,
                    'outerWidth' => 0,
                    'outerHeight' => 0,
                ],
                'screen' => [
                    'width' => 0,
                    'height' => 0,
                ],
                'timestamp' => date('c'),
                'local_datetime' => date('Y-m-d H:i:s'),
            ];

            $version = $token->getVersion();
            $encryptedData = $encryptionService->encrypt($pixelData, $version);
            $this->sendPixelDataAsync($encryptedData, $version);
        } catch (\Throwable $e) {
            w_log_error('RegisterPixel Observer Error: ' . $e->getMessage());
        }
    }

    private function resolveEventValue(mixed $payload, string $key): mixed
    {
        if (is_object($payload) && method_exists($payload, 'getData')) {
            return $payload->getData($key);
        }
        if (is_array($payload)) {
            return $payload[$key] ?? null;
        }

        return null;
    }

    private function resolveRequestUri(object $request): string
    {
        if (method_exists($request, 'getUri')) {
            return (string)$request->getUri();
        }
        if (method_exists($request, 'getUriString')) {
            return (string)$request->getUriString();
        }

        return '';
    }

    private function resolveUserId(object $user): int
    {
        if (method_exists($user, 'getAuthIdentifier')) {
            return (int)$user->getAuthIdentifier();
        }
        if (method_exists($user, 'getId')) {
            return (int)$user->getId();
        }

        return 0;
    }

    private function sendPixelDataAsync(string $encryptedData, string $version): void
    {
        $baseUrl = \Weline\Framework\App\Env::getInstance()->getBaseUrl();
        if (empty($baseUrl)) {
            $scheme = \Weline\Framework\Env\WelineEnv::server('REQUEST_SCHEME', 'http');
            $host = \Weline\Framework\Env\WelineEnv::server('HTTP_HOST', 'localhost');
            $baseUrl = $scheme . '://' . $host;
        }
        $pixelUrl = rtrim($baseUrl, '/') . '/visitor/rest/v1/pixel';

        $postData = json_encode([
            'encrypted' => $encryptedData,
            'version' => $version,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($pixelUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Pixel-Version: ' . $version,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        @curl_exec($ch);
        curl_close($ch);
    }
}
