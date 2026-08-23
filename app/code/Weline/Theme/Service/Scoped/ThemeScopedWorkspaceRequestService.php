<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Framework\App\Env;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

/** Validates HTTP-shaped commands before invoking the scoped workspace API. */
final class ThemeScopedWorkspaceRequestService
{
    private const MAX_COMMANDS = 512;
    private const MAX_CHANGES_JSON_BYTES = 2_097_152;
    private const MAX_COMMAND_JSON_BYTES = 524_288;
    private const MAX_NOTE_BYTES = 255;

    public function __construct(
        private readonly ThemeEditorContextFactory $contexts,
        private readonly ThemeScopedWorkspaceInterface $workspace,
        private readonly ThemeScopedResourceAdapterInterface $adapter,
        private readonly WelineTheme $themes,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function load(array $input): array
    {
        $context = $this->contexts->fromInput($input);
        $result = $this->workspace->load($context, true);
        $this->projectEditorDraft($context, $result);

        return $result;
    }

    /** @param array<string,mixed> $input */
    public function apply(array $input, string $actorId, string $actorName = ''): array
    {
        $context = $this->contexts->fromInput($input);
        $changes = $input['changes'] ?? null;
        if (\is_string($changes)) {
            if (\strlen($changes) > self::MAX_CHANGES_JSON_BYTES) {
                throw new \InvalidArgumentException('theme_patch_changes_too_large');
            }
            $changes = \json_decode($changes, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($changes) || $changes === []) {
            throw new \InvalidArgumentException('theme_patch_changes_required');
        }
        if (!\array_is_list($changes)) {
            throw new \InvalidArgumentException('theme_patch_changes_list_required');
        }
        if (\count($changes) > self::MAX_COMMANDS) {
            throw new \InvalidArgumentException('theme_patch_changes_limit_exceeded');
        }
        $encodedChanges = \json_encode(
            $changes,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (\strlen($encodedChanges) > self::MAX_CHANGES_JSON_BYTES) {
            throw new \InvalidArgumentException('theme_patch_changes_too_large');
        }
        $commands = [];
        foreach ($changes as $change) {
            if (!\is_array($change)) {
                throw new \InvalidArgumentException('theme_patch_command_type_invalid');
            }
            $command = ThemePatchCommand::fromArray($change);
            $encodedCommand = \json_encode(
                $command->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            if (\strlen($encodedCommand) > self::MAX_COMMAND_JSON_BYTES) {
                throw new \InvalidArgumentException('theme_patch_command_too_large');
            }
            $this->assertThemeBindingValue($context, $command);
            $commands[] = $command;
        }

        $result = $this->workspace->applyChanges(
            context: $context,
            expectedRevision: $this->requiredRevision($input),
            expectedParentReleaseId: $this->nullableId($input['expected_parent_release_id'] ?? null),
            changes: $commands,
            actorId: $actorId,
            actorName: $actorName,
            summary: $this->note($input['summary'] ?? '', 'summary'),
        );
        $this->projectEditorDraft($context, $result);

        return $result;
    }

    /** @param array<string,mixed> $input */
    public function publish(array $input, string $actorId, string $actorName = ''): array
    {
        $context = $this->contexts->fromInput($input);
        $result = $this->workspace->publish(
            context: $context,
            expectedRevision: $this->requiredRevision($input),
            expectedParentReleaseId: $this->nullableId($input['expected_parent_release_id'] ?? null),
            actorId: $actorId,
            actorName: $actorName,
            reason: $this->note($input['reason'] ?? '', 'reason'),
        );
        $publishedThemeId = $context->themeId > 0
            ? $context->themeId
            : (int)($result['payload']['theme_id'] ?? 0);
        $result['cache_invalidation'] = $this->cacheCleaner->clearScopedCaches(
            $context->scope,
            $publishedThemeId > 0 ? $publishedThemeId : null,
            'theme_scoped_publish',
        );

        return $result;
    }

    private function assertThemeBindingValue(ThemeEditorContext $context, ThemePatchCommand $command): void
    {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_THEME_BINDING
            || $command->operation === ThemePatchCommand::OP_INHERIT
        ) {
            return;
        }
        if ($command->operation !== ThemePatchCommand::OP_SET
            || !\is_int($command->value)
            || $command->value <= 0
        ) {
            throw new \InvalidArgumentException('theme_binding_theme_id_invalid');
        }
        $theme = clone $this->themes;
        $theme->clearData()->clearQuery()->load($command->value);
        if ((int)$theme->getId() !== $command->value) {
            throw new \InvalidArgumentException('theme_binding_theme_not_found');
        }
        if (!$this->themeContext->themeSupportsArea($theme, $context->area)) {
            throw new \InvalidArgumentException('theme_binding_theme_area_unsupported');
        }
    }

    /** @param array<string,mixed> $state */
    private function projectEditorDraft(ThemeEditorContext $context, array &$state): void
    {
        if ($context->resourceType !== ThemeEditorContext::RESOURCE_LAYOUT) {
            return;
        }

        try {
            $payload = $state['draft_payload'] ?? null;
            if (!\is_array($payload)) {
                throw new \RuntimeException('theme_scope_layout_draft_payload_missing');
            }
            $this->adapter->projectDraft($context, $payload);
        } catch (\Throwable $e) {
            // The scoped workspace is canonical and has already been loaded or
            // committed. A rebuildable compatibility projection must never turn
            // that successful operation into a revision-conflict-producing retry.
            $state['compatibility_projection'] = [
                'ok' => false,
                'code' => 'theme_scope_layout_draft_projection_failed',
            ];
            Env::log_error(
                'theme_scope_projection',
                'Theme layout draft compatibility projection failed: ' . $e->getMessage(),
            );
        }
    }

    /** @param array<string,mixed> $input */
    private function requiredRevision(array $input): int
    {
        if (!\array_key_exists('expected_revision', $input)) {
            throw new \InvalidArgumentException('theme_scope_expected_revision_required');
        }
        $revision = $input['expected_revision'];
        if (\is_string($revision) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $revision) === 1) {
            $revision = (int)$revision;
        }
        if (!\is_int($revision) || $revision < 0) {
            throw new \InvalidArgumentException('theme_scope_expected_revision_invalid');
        }

        return $revision;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_string($value) && \preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!\is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException('theme_scope_parent_release_invalid');
        }

        return $value;
    }

    private function note(mixed $value, string $field): string
    {
        if ($value === null) {
            return '';
        }
        if (!\is_scalar($value)) {
            throw new \InvalidArgumentException('theme_scope_' . $field . '_invalid');
        }
        $value = \trim((string)$value);
        if (\strlen($value) > self::MAX_NOTE_BYTES) {
            throw new \InvalidArgumentException('theme_scope_' . $field . '_too_long');
        }

        return $value;
    }
}
