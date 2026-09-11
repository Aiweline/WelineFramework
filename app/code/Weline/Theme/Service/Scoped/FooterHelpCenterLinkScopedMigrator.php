<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeScopeWorkspace;

/**
 * Hard-cut leftover: scoped layout drafts/releases still store footer-help-center-link
 * after the widget was renamed to footer-faq-link. Injection-table migration alone is
 * not enough — publish validates the effective scoped payload.
 */
final class FooterHelpCenterLinkScopedMigrator
{
    public const LEGACY_WIDGET_CODE = 'footer-help-center-link';
    public const CURRENT_WIDGET_CODE = 'footer-faq-link';

    public function __construct(
        private readonly ThemeScopeWorkspace $workspaces,
        private readonly ThemeScopedWorkspaceInterface $scopedWorkspace,
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ScopeIdentityCatalogInterface $catalog,
    ) {
    }

    /**
     * @return array{scanned:int,renamed_workspaces:int,renamed_nodes:int,skipped:list<string>}
     */
    public function migrate(): array
    {
        $scanned = 0;
        $renamedWorkspaces = 0;
        $renamedNodes = 0;
        $skipped = [];

        $rows = (clone $this->workspaces)->clearData()->clearQuery()
            ->where(ThemeScopeWorkspace::schema_fields_RESOURCE_TYPE, ThemeEditorContext::RESOURCE_LAYOUT)
            ->select()
            ->fetch()
            ->getItems();

        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!$row instanceof ThemeScopeWorkspace || $row->getId() <= 0) {
                continue;
            }
            ++$scanned;
            try {
                $renamed = $this->migrateWorkspace($row);
            } catch (\Throwable $e) {
                $skipped[] = 'ws' . $row->getId() . ':' . $e->getMessage();
                continue;
            }
            if ($renamed > 0) {
                ++$renamedWorkspaces;
                $renamedNodes += $renamed;
            }
        }

        return [
            'scanned' => $scanned,
            'renamed_workspaces' => $renamedWorkspaces,
            'renamed_nodes' => $renamedNodes,
            'skipped' => $skipped,
        ];
    }

    private function migrateWorkspace(ThemeScopeWorkspace $workspace): int
    {
        $context = $this->contextFromWorkspace($workspace);
        if ($context->identityHash() !== (string)$workspace->getData(ThemeScopeWorkspace::schema_fields_IDENTITY_HASH)) {
            throw new \RuntimeException('theme_scope_identity_hash_mismatch');
        }

        $state = $this->scopedWorkspace->load($context, true);
        $nodes = \is_array($state['draft_payload']['nodes'] ?? null) ? $state['draft_payload']['nodes'] : [];
        $commands = [];
        foreach ($nodes as $uid => $node) {
            if (!\is_array($node)) {
                continue;
            }
            if ((string)($node['widget_code'] ?? '') !== self::LEGACY_WIDGET_CODE) {
                continue;
            }
            $nodeUid = \strtolower(\trim((string)$uid));
            if (\preg_match('/^[a-f0-9]{32}$/D', $nodeUid) !== 1) {
                continue;
            }
            $commands[] = ThemePatchCommand::fromArray([
                'op' => ThemePatchCommand::OP_SET,
                'path' => '/nodes/' . $nodeUid . '/widget_code',
                'value' => self::CURRENT_WIDGET_CODE,
            ]);
        }
        if ($commands === []) {
            return 0;
        }

        $expectedParent = $state['expected_parent_release_id'] ?? null;
        $this->scopedWorkspace->applyChanges(
            context: $context,
            expectedRevision: (int)($state['revision'] ?? 0),
            expectedParentReleaseId: $expectedParent === null ? null : (int)$expectedParent,
            changes: $commands,
            actorId: 'setup:upgrade',
            actorName: 'Weline_Theme',
            summary: 'migrate_footer_help_center_link_to_faq',
            skipContentValidation: true,
        );

        return \count($commands);
    }

    private function contextFromWorkspace(ThemeScopeWorkspace $workspace): ThemeEditorContext
    {
        $storageScope = (string)$workspace->getData(ThemeScopeWorkspace::schema_fields_SCOPE);
        $identity = $this->scopes->fromStorageScope($storageScope, true) ?? ScopeIdentity::global();
        $authoritative = $this->catalog->authoritativeIdentity($identity);
        $base = $this->scopes->contextFromClaims($authoritative->toArray(), $authoritative);
        $storeMode = (string)$workspace->getData(ThemeScopeWorkspace::schema_fields_STORE_MODE);
        if ($storeMode === '' || !\in_array($storeMode, [
            ScopeIdentity::MODE_NORMAL,
            ScopeIdentity::MODE_DEV,
            ScopeIdentity::MODE_TEST,
        ], true)) {
            $storeMode = $base->storeMode;
        }
        $scope = $base->storeMode === $storeMode
            ? $base
            : new ScopeContext(
                $base->identity,
                $base->storageScope,
                $storeMode,
                $base->fallbackStorageScopes,
            );

        return new ThemeEditorContext(
            scope: $scope,
            area: (string)$workspace->getData(ThemeScopeWorkspace::schema_fields_AREA),
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: (int)$workspace->getData(ThemeScopeWorkspace::schema_fields_THEME_ID),
            layoutType: (string)$workspace->getData(ThemeScopeWorkspace::schema_fields_LAYOUT_TYPE),
            layoutOption: (string)$workspace->getData(ThemeScopeWorkspace::schema_fields_LAYOUT_OPTION),
            locale: (string)$workspace->getData(ThemeScopeWorkspace::schema_fields_LOCALE),
            targetType: (string)$workspace->getData(ThemeScopeWorkspace::schema_fields_TARGET_TYPE),
            targetId: (int)$workspace->getData(ThemeScopeWorkspace::schema_fields_TARGET_ID),
        );
    }
}
