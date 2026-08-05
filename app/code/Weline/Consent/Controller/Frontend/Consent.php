<?php

declare(strict_types=1);

namespace Weline\Consent\Controller\Frontend;

use Weline\Consent\Api\ConsentVisitorIdentityInterface;
use Weline\Consent\Service\ConsentService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

/**
 * Consent accept / withdraw API（前台；浏览器应走 Weline.Api / bin-query，本控制器供 Query 或 http:request 验证）。
 */
class Consent extends FrontendController
{
    public function status(): string
    {
        $svc = ObjectManager::getInstance(ConsentService::class);
        $identity = $this->visitorIdentity();
        $websiteId = $this->websiteId();
        $visitor = $identity->resolveOrIssue();
        $granted = [];
        foreach ($svc->categories() as $cat) {
            $granted[$cat['code']] = $svc->isGranted($websiteId, $visitor, $cat['code']);
        }

        return $this->json([
            'website_id' => $websiteId,
            'show_banner' => $svc->shouldShowBanner($websiteId, $visitor),
            'categories' => $svc->categories(),
            'granted' => $granted,
            'recording_enabled' => $svc->isRecordingEnabled(),
        ]);
    }

    public function accept(): string
    {
        $svc = ObjectManager::getInstance(ConsentService::class);
        $identity = $this->visitorIdentity();
        $websiteId = $this->websiteId();
        $visitor = $identity->resolveOrIssue();
        $codes = $this->request->getParam('categories');
        if (!\is_array($codes) || $codes === []) {
            $codes = ['analytics', 'marketing'];
        }
        foreach ($codes as $code) {
            $svc->grant($websiteId, $visitor, (string)$code);
        }
        // necessary always granted
        $svc->grant($websiteId, $visitor, 'necessary');

        return $this->json([
            'success' => true,
            'show_banner' => $svc->shouldShowBanner($websiteId, $visitor),
        ]);
    }

    public function withdraw(): string
    {
        $svc = ObjectManager::getInstance(ConsentService::class);
        $identity = $this->visitorIdentity();
        $websiteId = $this->websiteId();
        $visitor = $identity->resolveOrIssue();
        $code = (string)$this->request->getParam('category', 'analytics');
        $svc->withdraw($websiteId, $visitor, $code);

        return $this->json([
            'success' => true,
            'show_banner' => $svc->shouldShowBanner($websiteId, $visitor),
        ]);
    }

    private function websiteId(): int
    {
        $id = RequestContext::getWelineWebsiteId();
        return $id >= 0 ? $id : 0;
    }

    private function visitorIdentity(): ConsentVisitorIdentityInterface
    {
        $identity = ObjectManager::getInstance(ConsentVisitorIdentityInterface::class);
        $override = $this->request->getParam('visitor_key', null);
        $identity->assertNoClientOverride(
            $override === null ? [] : ['visitor_key' => $override],
        );
        return $identity;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return \json_encode($data, \JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
