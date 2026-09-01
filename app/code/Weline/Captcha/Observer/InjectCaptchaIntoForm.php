<?php

declare(strict_types=1);

namespace Weline\Captcha\Observer;

use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Captcha\Service\LazyCaptchaClientRuntime;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

final class InjectCaptchaIntoForm implements ObserverInterface
{
    public function __construct(private readonly CaptchaManagerInterface $captcha)
    {
    }

    public function execute(Event &$event): void
    {
        $attributes = $event->getData('attributes');
        if (!\is_array($attributes)) {
            return;
        }
        $mode = (string)($attributes['captcha'] ?? 'off');
        // auto/required/lazy only gate whether to inject; markup is always lazy (FPC-safe).
        if ($mode === 'off' || ($mode === 'auto' && (string)($attributes['method'] ?? '') !== 'post')) {
            return;
        }

        // Async admin forms submit via XHR/bin-query; a visible challenge would only
        // clutter the UI and cannot participate in that request path.
        $htmlAttributes = $attributes['html_attributes'] ?? [];
        if (
            isset($attributes['data-async-action'])
            || (\is_array($htmlAttributes) && isset($htmlAttributes['data-async-action']))
        ) {
            return;
        }

        $formId = (string)($attributes['id'] ?? '');
        $intent = (string)($attributes['intent'] ?? 'generic');
        $runtime = LazyCaptchaClientRuntime::onceScriptHtml();
        // Never SSR one-shot challenge HTML into shared page shells.
        $event->setData(
            'html',
            (string)$event->getData('html')
            . '<div class="weline-captcha-lazy-host"'
            . ' data-weline-captcha-lazy="1"'
            . ' data-challenge-route="weline_captcha/frontend/challenge"'
            . ' data-form-id="' . \htmlspecialchars($formId, \ENT_QUOTES, 'UTF-8') . '"'
            . ' data-intent="' . \htmlspecialchars($intent, \ENT_QUOTES, 'UTF-8') . '"'
            . ' data-captcha-mode="' . \htmlspecialchars($mode, \ENT_QUOTES, 'UTF-8') . '"'
            . '></div>'
            . $runtime
        );
    }
}
