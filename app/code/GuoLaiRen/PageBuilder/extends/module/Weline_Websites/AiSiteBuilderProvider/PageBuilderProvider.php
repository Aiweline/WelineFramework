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
        return (string)__('AI 寤虹珯宸ヤ綔鍙?路 PageBuilder');
    }

    public function getDescription(): string
    {
        return (string)__('Websites 璐熻矗鍑嗗淇℃伅銆佸煙鍚嶅拰闀滃儚宸ヤ綔鍖猴紝鐪熸鐨?AI 寤虹珯銆佽櫄鎷熶富棰樸€侀〉闈㈢墿鍖栧拰鍙鍖栫紪杈戝叏閮ㄤ氦缁?PageBuilder 鍘熺敓娴佺▼銆?);
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
        $entryUrl = $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/index');
        $nativeEntryUrl = $this->resolveNativeEntryUrl($sessionState, $scope, $entryUrl);
        $domainManagementUrl = $this->url->getBackendUrl('pagebuilder/backend/domainManagement/index');
        $websiteManagementUrl = $this->url->getBackendUrl('pagebuilder/backend/websiteManagement/index');
        $pageIndexUrl = $this->url->getBackendUrl('pagebuilder/backend/page/index');

        $resolvedScope = [
            'provider_code' => 'pagebuilder',
            'preferred_editor' => 'pagebuilder',
            'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
            'provider_authority' => 'pagebuilder_native',
            /** 涓?PageBuilder can_publish 缁勫悎锛?=鍩熷悕灏辩华鍙彂甯冿紝0=浠呰崏绋?*/
            'site_ready' => (int)($scope['site_ready'] ?? 1),
            /** virtual_theme | html_block_nodes锛屽叏绔欎簩閫変竴杞?*/
            'workspace_track' => \trim((string)($scope['workspace_track'] ?? 'virtual_theme')) !== 'html_block_nodes'
                ? 'virtual_theme'
                : 'html_block_nodes',
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
            'badge' => (string)__('PageBuilder 鎵╁睍'),
            'target_url' => $nativeEntryUrl,
            'target_label' => (string)__('杩涘叆 PageBuilder 鍘熺敓娴佺▼'),
            'workspace_label' => (string)__('鍒涘缓 Websites 闀滃儚宸ヤ綔鍖?),
            'handoff_label' => (string)__('缁х画鍒?PageBuilder 鍘熺敓宸ヤ綔鍙?),
            'native_entry_url' => $nativeEntryUrl,
            'welcome_message' => (string)__('宸蹭负浣犲垱寤哄吋瀹?PageBuilder 鐨勯暅鍍忓伐浣滃尯銆傝繖閲岀户缁敹闆嗙珯鐐瑰噯澶囦俊鎭紝鐪熸鐨勮櫄鎷熶富棰樺拰鍙鍖栫紪杈戜細鍦?PageBuilder 涓畬鎴愩€?),
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
                    'description' => (string)__('鍏堝畬鎴愮珯鐐圭畝浠嬨€佺洰鏍囧煙鍚嶃€佹敞鍐屽晢閫夋嫨绛夊噯澶囦俊鎭紱鐩爣鍩熷悕琛ラ綈鍓嶄笉杩涘叆鏂规鐢熸垚锛岀劧鍚庢妸娴佺▼浜ょ粰 PageBuilder銆?),
                    'ai_recommendation' => (string)__('AI 浼氬厛鏁寸悊缃戠珯绾ц祫鏂欒緭鍏ワ紝杩涘叆 PageBuilder 鍚庡啀涓€娆℃€х敓鎴愯崏绋跨珯鐐广€佽櫄鎷熶富棰樸€侀〉闈㈠拰鍙鍖栭瑙堛€?),
                    'confirm_label' => (string)__('纭鍑嗗淇℃伅骞惰繘鍏?PageBuilder'),
                    'scope_patch' => [
                        'journey_stage' => 'prepare',
                        'preferred_editor' => 'pagebuilder',
                        'provider_handoff_mode' => self::HANDOFF_MODE_NATIVE_WORKSPACE,
                    ],
                ],
                'generate' => [
                    'title' => (string)__('PageBuilder AI 寤虹珯'),
                    'description' => (string)__('浠庤繖涓€姝ュ紑濮嬬敱 PageBuilder 鍘熺敓宸ヤ綔鍖烘帴绠°€傝櫄鎷熶富棰樸€侀〉闈㈢墿鍖栥€佸彲瑙嗗寲棰勮鍜岀紪杈戦兘浠?PageBuilder 鐘舵€佷负鍑嗐€?),
                    'ai_recommendation_title' => (string)__('PageBuilder 鍘熺敓闂幆'),
                    'ai_recommendation' => (string)__('杩涘叆 PageBuilder 鍚庢墽琛屸€滆櫄鎷熶富棰樼紪鎺掆€濓紝绯荤粺浼氬垱寤烘垨鎭㈠鐪熷疄鑽夌绔欑偣锛屾壒閲忕墿鍖栭〉闈紝骞剁洿鎺ョ粰鍑?preview/full?visual_editor=1 鐨勭湡瀹炲彲瑙嗗寲鍦板潃銆?),
                    'confirm_label' => (string)__('缁х画鍒?PageBuilder 鍘熺敓宸ヤ綔鍙?),
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
                        (string)__('鑽夌绔欑偣浼氬厛鍒涘缓锛屽悗缁彂甯冧粛鏄悓涓€涓?website_id'),
                        (string)__('铏氭嫙涓婚鍜岄〉闈㈤兘鐢?PageBuilder 鏈嶅姟灞傝礋璐ｅ啓鍏?),
                        (string)__('鍙鍖栭瑙堜笌缂栬緫瀹屽叏澶嶇敤鐜版湁 preview/full 涓?page/edit 鏍稿績'),
                    ],
                ],
                'complete' => [
                    'description' => (string)__('鏈€鍚庡湪 Websites 閲屽彧鍋氶暅鍍忕‘璁わ紝鐪熷疄鍙戝竷浠嶉拡瀵瑰悓涓€涓崏绋跨珯鐐广€?),
                    'ai_recommendation' => (string)__('浼樺厛鍥炵湅闀滃儚宸ヤ綔鍖洪噷鐨勫彲瑙嗗寲 iframe 鍜岀紪杈戝櫒鍏ュ彛锛岀‘璁?URLs 涓?PageBuilder 鍘熺敓宸ヤ綔鍖轰繚鎸佷竴鑷淬€?),
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
                    'label' => (string)__('鎵撳紑 PageBuilder 宸ヤ綔鍙?),
                    'description' => (string)__('杩涘叆 PageBuilder 鍘熺敓 AI 寤虹珯宸ヤ綔鍖猴紝缁х画铏氭嫙涓婚鍜屽彲瑙嗗寲缂栬緫娴佺▼銆?),
                    'type' => 'link',
                    'icon' => 'mdi mdi-view-dashboard-edit-outline',
                    'button_class' => 'btn-primary',
                    'url' => $nativeEntryUrl,
                ],
                [
                    'code' => 'open_domain_management',
                    'label' => (string)__('绠＄悊鍩熷悕'),
                    'description' => (string)__('鏌ョ湅鎴栧鐞?PageBuilder 渚х殑鍩熷悕浠诲姟銆?),
                    'type' => 'link',
                    'icon' => 'mdi mdi-earth-box',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $domainManagementUrl,
                ],
                [
                    'code' => 'open_website_management',
                    'label' => (string)__('绠＄悊绔欑偣'),
                    'description' => (string)__('璺冲埌 PageBuilder 鐨勭珯鐐圭鐞嗗垪琛ㄣ€?),
                    'type' => 'link',
                    'icon' => 'mdi mdi-sitemap-outline',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $websiteManagementUrl,
                ],
                [
                    'code' => 'open_page_index',
                    'label' => (string)__('鎵撳紑椤甸潰鍒楄〃'),
                    'description' => (string)__('杩涘叆 PageBuilder 椤甸潰绠＄悊鐣岄潰銆?),
                    'type' => 'link',
                    'icon' => 'mdi mdi-file-document-multiple-outline',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $pageIndexUrl,
                ],
                [
                    'code' => 'handoff_scope_site_ready',
                    'label' => (string)__('鍐欏叆 site_ready锛堝煙鍚嶉棬绂侊級'),
                    'description' => (string)__('閫氳繃 Websites 浼氳瘽 merge-scope 鍐欏叆 site_ready=1 琛ㄧず鍩熷悕娴佺▼瀹屾垚锛? 鏃?PageBuilder 浠呭厑璁歌崏绋裤€傝瑙佹ā鍧?doc 璁″垝-AI寤虹珯宸ヤ綔鍙?Websites渚?md銆?),
                    'type' => 'link',
                    'icon' => 'mdi mdi-web-check',
                    'button_class' => 'btn-outline-secondary',
                    'url' => $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']),
                ],
                [
                    'code' => 'handoff_scope_workspace_track',
                    'label' => (string)__('璇存槑锛歸orkspace_track 鍙岃建'),
                    'description' => (string)__('handoff 鍙甫 workspace_track=html_block_nodes锛堥粯璁?HTML 鍖哄潡锛夋垨 virtual_theme锛堥珮绾ц櫄鎷熶富棰橈級銆傝繘鍏?PageBuilder 宸ヤ綔鍖哄悗鍙湪銆岄樁娈?銆嶅崱鐗囧垏鎹€?),
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
