<?php

declare(strict_types=1);

namespace WeShop\Base\Plugin\Theme;

use WeShop\Base\Service\ThemeCompatibilityService;
use Weline\Framework\Http\Request;
use Weline\Theme\Controller\Backend\ThemeEditor;

/**
 * @deprecated Kept only so stale generated plugin metadata cannot block setup commands.
 */
class ThemeEditorCompatibilityPlugin
{
    public function __construct(
        private readonly ThemeCompatibilityService $themeCompatibilityService,
        private readonly Request $request
    ) {
    }

    public function afterPostSaveLayout(ThemeEditor $subject, string $result): string
    {
        return $this->decorateJsonResponse($result, 'save_layout');
    }

    public function afterPostPublish(ThemeEditor $subject, string $result): string
    {
        return $this->decorateJsonResponse($result, 'publish_layout');
    }

    public function afterPostSaveCompiledLayout(ThemeEditor $subject, string $result): string
    {
        return $this->decorateJsonResponse($result, 'save_compiled_layout');
    }

    public function afterGetLayoutPreview(ThemeEditor $subject, string $result): string
    {
        $compatibility = $this->themeCompatibilityService->inspectFromRequest($this->request);
        if (empty($compatibility['has_missing_hosts'])) {
            return $result;
        }

        $this->themeCompatibilityService->emitWarning($compatibility, 'layout_preview');

        return $this->themeCompatibilityService->injectPreviewBanner($result, $compatibility);
    }

    private function decorateJsonResponse(string $result, string $action): string
    {
        $compatibility = $this->themeCompatibilityService->inspectFromRequest($this->request);
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
