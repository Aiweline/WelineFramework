<?php

declare(strict_types=1);

namespace Weline\Captcha\Controller\Frontend;

use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * Lazy captcha challenge HTML for storefront forms that must not SSR
 * LocalImageCaptcha on every page (PASSWORD_DEFAULT alone is ~100–400ms).
 */
class Challenge extends FrontendController
{
    public function get()
    {
        $intent = trim((string)$this->request->getGet('intent', 'generic'));
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,80}\z/D', $intent) !== 1) {
            $intent = 'generic';
        }
        $formId = trim((string)$this->request->getGet('form_id', ''));
        if (preg_match('/\A[A-Za-z0-9_-]{0,80}\z/D', $formId) !== 1) {
            $formId = '';
        }

        /** @var CaptchaManagerInterface $captcha */
        $captcha = ObjectManager::getInstance(CaptchaManagerInterface::class);
        $html = $captcha->renderChallenge([
            'form_id' => $formId,
            'intent' => $intent,
            'required' => true,
        ]);

        return $this->json([
            'code' => 200,
            'html' => $html,
        ]);
    }
}
