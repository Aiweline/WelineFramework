<?php

declare(strict_types=1);

namespace Weline\Captcha\Controller\Frontend;

use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\Framework\Manager\ObjectManager;

/**
 * Lazy captcha challenge HTML for storefront forms that must not SSR
 * LocalImageCaptcha on every page (PASSWORD_DEFAULT alone is ~100–400ms).
 *
 * Route: weline_captcha/frontend/challenge (GET).
 */
class Challenge extends FrontendController
{
    public function get()
    {
        // One-shot challenge HTML must never enter shared/page fragment caches.
        SharedResponseCachePolicy::forbid('captcha_challenge');

        $intent = trim((string)$this->request->getGet('intent', 'generic'));
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/D', $intent) !== 1) {
            $intent = 'generic';
        }
        $formId = trim((string)$this->request->getGet('form_id', ''));
        if (preg_match('/\A[A-Za-z0-9_-]{0,80}\z/D', $formId) !== 1) {
            $formId = '';
        }
        $prefer = strtolower(trim((string)$this->request->getGet('prefer', '')));
        if ($prefer !== 'local_image') {
            $prefer = '';
        }

        /** @var CaptchaManagerInterface $captcha */
        $captcha = ObjectManager::getInstance(CaptchaManagerInterface::class);
        $html = $captcha->renderChallenge([
            'form_id' => $formId,
            'intent' => $intent,
            'required' => true,
            'prefer' => $prefer,
            'server' => $_SERVER ?? [],
        ]);

        // PcController exposes fetchJson (not json).
        return $this->fetchJson([
            'code' => 200,
            'html' => $html,
        ]);
    }
}
