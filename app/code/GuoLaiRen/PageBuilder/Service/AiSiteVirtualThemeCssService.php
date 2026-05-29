<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

/**
 * 站点级 theme.css：一次生成，块级 prompt 复用类名。
 */
final class AiSiteVirtualThemeCssService
{
    public function __construct(
        private readonly ?AiSiteDesignTokenResolver $tokenResolver = null
    ) {
    }

    /**
     * @param array<string, mixed> $blueprintOrPlan
     * @return array{css:string,hash:string}
     */
    public function generateThemeCss(array $blueprintOrPlan): array
    {
        $tokens = $this->tokenResolver()->resolveFromBlueprint($blueprintOrPlan);
        $root = $this->tokenResolver()->buildRootCssVariables($tokens);

        $css = $root . "\n"
            . '.pb-c-section{padding:var(--pb-spacing) 24px;}'
            . '.pb-c-card{border-radius:var(--pb-radius);padding:calc(var(--pb-spacing) * 0.75);}'
            . '.pb-c-cta-primary{display:inline-flex;align-items:center;padding:12px 20px;border-radius:var(--pb-radius);font-family:var(--pb-font-display);transition:opacity .2s ease;}'
            . '.pb-c-cta-primary:hover{opacity:.92;}'
            . '@media (max-width:768px){.pb-c-section{padding:calc(var(--pb-spacing) * 0.6) 16px;}.pb-c-card{padding:16px;}}'
            . '@media (max-width:420px){.pb-c-section{padding:16px 12px;}}';

        return [
            'css' => $css,
            'hash' => 'sha256:' . \hash('sha256', $css),
        ];
    }

    /**
     * @param array{css:string,hash:string} $themeCssRef
     * @return array<string, mixed>
     */
    public function buildManifestRef(array $themeCssRef): array
    {
        return [
            'hash' => (string)($themeCssRef['hash'] ?? ''),
            'artifact_key' => 'theme_css',
            'storage' => 'artifact_v1',
        ];
    }

    private function tokenResolver(): AiSiteDesignTokenResolver
    {
        return $this->tokenResolver ?? new AiSiteDesignTokenResolver();
    }
}
