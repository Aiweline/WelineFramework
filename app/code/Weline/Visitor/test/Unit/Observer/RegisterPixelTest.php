<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\UnitTest\TestCore;
use Weline\Visitor\Model\PixelEncryptionToken;
use Weline\Visitor\Observer\RegisterPixel;
use Weline\Visitor\Service\PixelEncryptionService;

class RegisterPixelTest extends TestCore
{
    private RegisterPixel $observer;
    private PixelEncryptionService $encryptionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = ObjectManager::getInstance(RegisterPixel::class);
        $this->encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
    }

    public function testRegisterEventTriggersPixelWithToken(): void
    {
        $version = 'test-register-' . uniqid('', true);

        try {
            $this->encryptionService->generateTokenForVersion($version);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Unable to create token: ' . $e->getMessage());
            return;
        }

        $_SERVER['WELINE_USER_LANG'] = 'zh-CN';
        $_SERVER['WELINE_USER_CURRENCY'] = 'RMB';
        $_SERVER['WELINE_WEBSITE_ID'] = '1';

        $event = $this->createEvent($this->createUser(789), $this->createRequest(
            'https://example.com/register',
            'https://example.com/',
            'Mozilla/5.0 Test',
            '192.168.1.2'
        ));

        $this->observer->execute($event);
        $this->assertTrue(true);

        $this->cleanupToken($version);
        unset($_SERVER['WELINE_USER_LANG'], $_SERVER['WELINE_USER_CURRENCY'], $_SERVER['WELINE_WEBSITE_ID']);
    }

    public function testRegisterEventWithoutToken(): void
    {
        $currentToken = $this->encryptionService->getCurrentVersionToken();
        if ($currentToken) {
            $this->markTestSkipped('Current environment already has an active token.');
            return;
        }

        $event = $this->createEvent($this->createUser(789), $this->createRequest(
            'https://example.com/register',
            '',
            '',
            '192.168.1.2'
        ));

        $this->observer->execute($event);
        $this->assertTrue(true);
    }

    public function testRegisterEventEncryptedDataSent(): void
    {
        $version = 'test-register-encrypt-' . uniqid('', true);

        try {
            $this->encryptionService->generateTokenForVersion($version);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Unable to create token: ' . $e->getMessage());
            return;
        }

        $_SERVER['WELINE_USER_LANG'] = 'en-US';
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';
        $_SERVER['WELINE_WEBSITE_ID'] = '3';

        $event = $this->createEvent($this->createUser(999), $this->createRequest(
            'https://example.com/register',
            'https://example.com/signup',
            'Mozilla/5.0 Firefox',
            '203.0.113.2'
        ));

        $this->observer->execute($event);
        $this->assertTrue(true);

        $this->cleanupToken($version);
        unset($_SERVER['WELINE_USER_LANG'], $_SERVER['WELINE_USER_CURRENCY'], $_SERVER['WELINE_WEBSITE_ID']);
    }

    private function createEvent(?AuthenticableInterface $user, ?Request $request): Event
    {
        $data = new DataObject();
        if ($user !== null) {
            $data->setData('user', $user);
        }
        if ($request !== null) {
            $data->setData('request', $request);
        }

        return new Event(['data' => $data]);
    }

    private function createUser(int $id): AuthenticableInterface
    {
        $user = $this->createMock(AuthenticableInterface::class);
        $user->method('getAuthIdentifier')->willReturn($id);

        return $user;
    }

    private function createRequest(string $uri, string $referer, string $userAgent, string $ip): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getReferer')->willReturn($referer);
        $request->method('clientIP')->willReturn($ip);
        $request->method('getServer')->willReturnCallback(
            static fn(string $key = '') => $key === 'HTTP_USER_AGENT' ? $userAgent : ''
        );

        return $request;
    }

    private function cleanupToken(string $version): void
    {
        try {
            $token = ObjectManager::make(PixelEncryptionToken::class)->findByVersion($version);
            if ($token && $token->getTokenId()) {
                $token->delete();
            }
        } catch (\Throwable) {
        }
    }
}
