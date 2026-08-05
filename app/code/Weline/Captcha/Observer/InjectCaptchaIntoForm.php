<?php

declare(strict_types=1);

namespace Weline\Captcha\Observer;

use Weline\Captcha\Api\CaptchaManagerInterface;
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
        if ($mode === 'off' || ($mode === 'auto' && (string)($attributes['method'] ?? '') !== 'post')) {
            return;
        }

        $html = $this->captcha->renderChallenge([
            'form_id' => (string)($attributes['id'] ?? ''),
            'intent' => (string)($attributes['intent'] ?? 'generic'),
            'required' => $mode === 'required',
        ]);
        $event->setData('html', (string)$event->getData('html') . $html);
    }
}
