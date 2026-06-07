<?php

declare(strict_types=1);

namespace WeShop\Base\Observer\Theme;

use WeShop\Base\Service\ThemeCompatibilityService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;

class ThemeEditorCompatibilityResultAfter implements ObserverInterface
{
    private const JSON_ACTIONS = [
        'save_layout' => true,
        'publish_layout' => true,
        'save_compiled_layout' => true,
    ];

    public function __construct(
        private readonly ThemeCompatibilityService $themeCompatibilityService,
        private readonly Request $request
    ) {
    }

    public function execute(Event &$event): void
    {
        $action = (string)$event->getData('action');
        $result = $event->getData('result');
        if (!is_string($result)) {
            return;
        }

        $request = $event->getData('request');
        if (!$request instanceof Request) {
            $request = $this->request;
        }

        if ($action === 'layout_preview') {
            $event->setData('result', $this->decoratePreviewResponse($result, $request, $action));
            return;
        }

        if (!isset(self::JSON_ACTIONS[$action])) {
            return;
        }

        $event->setData('result', $this->decorateJsonResponse($result, $request, $action));
    }

    private function decoratePreviewResponse(string $result, Request $request, string $action): string
    {
        $compatibility = $this->themeCompatibilityService->inspectFromRequest($request);
        if (empty($compatibility['has_missing_hosts'])) {
            return $result;
        }

        $this->themeCompatibilityService->emitWarning($compatibility, $action);

        return $this->themeCompatibilityService->injectPreviewBanner($result, $compatibility);
    }

    private function decorateJsonResponse(string $result, Request $request, string $action): string
    {
        $compatibility = $this->themeCompatibilityService->inspectFromRequest($request);
        if (empty($compatibility['has_missing_hosts'])) {
            return $result;
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return $result;
        }

        $this->themeCompatibilityService->emitWarning($compatibility, $action);

        $warningMessage = trim((string)($compatibility['warning_message'] ?? ''));
        if ($warningMessage !== '') {
            $decoded['message'] = trim((string)($decoded['message'] ?? ''));
            $decoded['message'] = trim($decoded['message'] . ' ' . $warningMessage);
        }

        $decoded['compatibility'] = $this->themeCompatibilityService->buildPayload($compatibility, $action);
        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : $result;
    }
}
