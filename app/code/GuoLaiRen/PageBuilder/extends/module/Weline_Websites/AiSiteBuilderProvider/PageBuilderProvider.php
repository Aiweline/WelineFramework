<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Websites\AiSiteBuilderProvider;

use Weline\Framework\Http\Url;
use Weline\Websites\Api\AiSiteBuilderWorkbenchProviderInterface;

class PageBuilderProvider implements AiSiteBuilderWorkbenchProviderInterface
{
    private const HANDOFF_MODE_NATIVE_WORKSPACE = 'pagebuilder_native_workspace';

    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getCode(): string
    {
        return 'pagebuilder';
    }

    public function getName(): string
    {
        return (string)__('AI Site PageBuilder');
    }

    public function getDescription(): string
    {
        return (string)__('Build AI site pages with PageBuilder.');
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 30;
    }

    public function getWorkbenchConfig(
        ?array $sessionState,
        int $adminUserId,
        array $scope = [],
        array $providerState = [],
        array $context = []
    ): array {
        unset($adminUserId, $providerState);

        $entryUrl = $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/index');
        $nativeEntryUrl = $this->resolveNativeEntryUrl($sessionState, $scope, $entryUrl);
        $domainManagementUrl = $this->url->getBackendUrl('pagebuilder/backend/domainManagement/index');
        $websiteManagementUrl = $this->url->getBackendUrl('pagebuilder/backend/websiteManagement/index');
        $pageIndexUrl = $this->url->getBackendUrl('pagebuilder/backend/page/index');

        $resolvedScope = [
            'provider' => 'pagebuilder',
            'preferred_editor' => 'pagebuilder',
            'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
            'workspace_track' => \trim((string)($scope['workspace_track'] ?? 'virtual_theme')) !== 'html_blocks'
                ? 'virtual_theme'
                : 'html_blocks',
        ];
        if (($context['source'] ?? '') !== '') {
            $resolvedScope['created_from'] = (string)$context['source'];
        }

        $initialStage = ((int)($scope['draft_website_id'] ?? 0) > 0
            || (int)($scope['preview_page_id'] ?? 0) > 0
            || \trim((string)($scope['pagebuilder_workspace_public_id'] ?? '')) !== '')
            ? 'generate'
            : 'prepare';

        return [
            'badge' => (string)__('PageBuilder'),
            'target_url' => $nativeEntryUrl,
            'target_label' => (string)__('Open PageBuilder workspace'),
            'workspace_label' => (string)__('Websites AI site workspace'),
            'handoff_label' => (string)__('Continue in PageBuilder'),
            'native_entry_url' => $nativeEntryUrl,
            'welcome_message' => (string)__('Continue building this AI site in the PageBuilder workspace.'),
            'initial_stage' => $initialStage,
            'scope' => $resolvedScope,
            'provider_state' => [
                'provider' => [
                    'code' => 'pagebuilder',
                    'native_entry_url' => $nativeEntryUrl,
                ],
            ],
            'stage_guides' => [
                'prepare' => [
                    'description' => (string)__('Prepare the site brief, routes, and workspace settings before generation.'),
                    'ai_recommendation' => (string)__('Use PageBuilder when the site needs editable pages and visual iteration.'),
                    'confirm_label' => (string)__('Continue in PageBuilder'),
                    'scope_patch' => [
                        'journey_stage' => 'prepare',
                        'preferred_editor' => 'pagebuilder',
                        'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
                    ],
                ],
                'generate' => [
                    'title' => (string)__('PageBuilder AI generation'),
                    'description' => (string)__('Generate and refine editable PageBuilder pages.'),
                    'ai_recommendation_title' => (string)__('Recommended PageBuilder workflow'),
                    'ai_recommendation' => (string)__('Open the PageBuilder workspace to generate, preview, refine, and publish pages.'),
                    'confirm_label' => (string)__('Open PageBuilder generation'),
                    'tool_codes' => [
                        'open_pagebuilder_workspace',
                        'open_page_index',
                    ],
                    'scope_patch' => [
                        'journey_stage' => 'generate',
                        'preferred_editor' => 'pagebuilder',
                        'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
                    ],
                    'key_points' => [
                        (string)__('Use website_id to keep generated pages attached to the selected site.'),
                        (string)__('Use PageBuilder preview and page edit tools for visual review.'),
                        (string)__('Publish only after generated pages pass the workspace checks.'),
                    ],
                ],
                'complete' => [
                    'description' => (string)__('Review generated pages, domains, and publish state before finishing.'),
                    'ai_recommendation' => (string)__('Use PageBuilder preview and website management tools for final verification.'),
                    'tool_codes' => [
                        'open_pagebuilder_workspace',
                        'open_website_management',
                    ],
                    'scope_patch' => [
                        'journey_stage' => 'complete',
                        'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
                    ],
                ],
            ],
            'tools' => [
                [
                    'code' => 'open_pagebuilder_workspace',
                    'label' => (string)__('Open PageBuilder workspace'),
                    'description' => (string)__('Open the AI site PageBuilder workspace.'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-view-dashboard-edit-outline',
                    'button_class' => 'btn-primary',
                    'url' => $nativeEntryUrl,
                ],
                [
                    'code' => 'open_domain_management',
                    'label' => (string)__('Open domain management'),
                    'description' => (string)__('Manage domains for the generated site.'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-earth-box',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $domainManagementUrl,
                ],
                [
                    'code' => 'open_website_management',
                    'label' => (string)__('Open website management'),
                    'description' => (string)__('Manage generated websites and publish state.'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-sitemap-outline',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $websiteManagementUrl,
                ],
                [
                    'code' => 'open_page_index',
                    'label' => (string)__('Open page index'),
                    'description' => (string)__('Review generated PageBuilder pages.'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-file-document-multiple-outline',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $pageIndexUrl,
                ],
                [
                    'code' => 'handoff_scope_site_ready',
                    'label' => (string)__('Mark site ready'),
                    'description' => (string)__('Continue once the site scope is ready for PageBuilder.'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-web-check',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']),
                ],
                [
                    'code' => 'handoff_scope_workspace_track',
                    'label' => (string)__('Workspace track'),
                    'description' => (string)__('handoff supports workspace_track=html_blocks or virtual_theme.'),
                    'type' => 'link',
                    'icon' => 'mdi mdi-source-branch',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $nativeEntryUrl,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $sessionState
     * @param array<string, mixed> $scope
     */
    private function resolveNativeEntryUrl(?array $sessionState, array $scope, string $fallbackUrl): string
    {
        $workspaceUrl = \trim((string)($scope['pagebuilder_workspace_url'] ?? ''));
        if ($workspaceUrl !== '') {
            return $workspaceUrl;
        }

        $workspacePublicId = \trim((string)($scope['pagebuilder_workspace_public_id'] ?? ''));
        if ($workspacePublicId !== '') {
            return $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $workspacePublicId]);
        }

        $sessionPublicId = \trim((string)($sessionState['public_id'] ?? ''));
        if ($sessionPublicId !== '') {
            return $this->url->getBackendUrl('websites/backend/site-builder-agent/pagebuilder-handoff', ['public_id' => $sessionPublicId]);
        }

        return $fallbackUrl;
    }
}