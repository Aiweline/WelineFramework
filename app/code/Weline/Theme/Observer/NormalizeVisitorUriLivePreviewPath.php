<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeLivePreviewPathMount;

/**
 * Early URI normalize: /~preview/{token}/… → routing remainder + lock origin under preview mount.
 */
class NormalizeVisitorUriLivePreviewPath implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var DataObject|null $data */
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            $payload = $event->getData();
            if ($payload instanceof DataObject) {
                $data = $payload;
            } else {
                return;
            }
        }

        $uri = (string)($data->getData('uri') ?? $data->getData('routing_uri') ?? '');
        if ($uri === '') {
            return;
        }

        $parsed = ThemeLivePreviewPathMount::parseFromUri($uri);
        if ($parsed === null) {
            return;
        }

        $token = $parsed['token'];
        $data->setData('origin_uri', $uri);
        $data->setData('routing_uri', $parsed['remainder'] !== '' ? $parsed['remainder'] : '/');
        $data->setData('live_preview_token', $token);

        try {
            RequestContext::set(ThemeLivePreviewPathMount::REQUEST_CONTEXT_TOKEN_KEY, $token);
        } catch (\Throwable) {
        }

        try {
            WelineEnv::set(ThemeLivePreviewPathMount::ENV_TOKEN_KEY, $token);
        } catch (\Throwable) {
        }

        // Make token visible to PreviewTokenService without polluting formal query identity.
        try {
            $_GET[PreviewTokenService::TOKEN_KEY] = $token;
            WelineEnv::setGet(PreviewTokenService::TOKEN_KEY, $token);
        } catch (\Throwable) {
        }
    }
}
