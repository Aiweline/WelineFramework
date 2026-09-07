<?php

declare(strict_types=1);

// Isolated process: persistence doubles avoid connecting to the configured database.
namespace Weline\Theme\Model {
    class ThemeScopeWorkspace
    {
        public const schema_fields_DRAFT_REVISION_ID = 'draft_revision_id';
        public function getData(string $key): int { return 7; }
    }

    class ThemeScopePatch
    {
        public const schema_fields_REVISION_ID = 'revision_id';
        public const schema_fields_ID = 'id';
        public static array $rows = [];
        private int $id = 0;
        private ?int $revisionId = null;
        public function clearData(): static { return $this; }
        public function clearQuery(): static { return $this; }
        public function where(string $field, int $value): static { $this->revisionId = $value; return $this; }
        public function select(): static { return $this; }
        public function fetchArray(): array { return array_values(array_filter(self::$rows, fn(array $row): bool => $row['revision_id'] === $this->revisionId)); }
        public function load(int $id): static { $this->id = $id; return $this; }
        public function getId(): int { return $this->id; }
        public function delete(): static { self::$rows = array_values(array_filter(self::$rows, fn(array $row): bool => $row['id'] !== $this->id)); return $this; }
        public function fetch(): static { return $this; }
    }
}

namespace {
    require dirname(__DIR__, 8) . '/vendor/autoload.php';

    use Weline\Framework\Runtime\ScopeIdentity;
    use Weline\SystemConfig\Api\Scope\ScopeContext;
    use Weline\Theme\Api\Scoped\ThemeEditorContext;
    use Weline\Theme\Api\Scoped\ThemePatchCommand;
    use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
    use Weline\Theme\Model\ThemeScopePatch;
    use Weline\Theme\Model\ThemeScopeWorkspace;
    use Weline\Theme\Service\Scoped\ThemePatchEngine;
    use Weline\Theme\Service\ThemeEditorDraftResetService;

    // PHPUnit's interface double delegates to the real patch engine. Production
    // workspace applyChanges already owns transaction/revision persistence.
    $test = new class ('fixture') extends \PHPUnit\Framework\TestCase {
        public function workspace(): ThemeScopedWorkspaceInterface { return $this->createMock(ThemeScopedWorkspaceInterface::class); }
    };
    $scoped = $test->workspace();
    $scope = new ScopeContext(ScopeIdentity::global(), '0.0.0', 'normal', ['0.0.0']);
    $context = (new ThemeEditorContext($scope, 'frontend', themeId: 1))->withResource($argv[1]);
    $owned = [ThemePatchCommand::fromArray(['op' => 'set', 'path' => $argv[2], 'value' => 'Owned'])];
    $publishedRevision = (int)$argv[3];
    $state = [
        'revision' => 3, 'draft_revision_id' => 7, 'published_revision_id' => $publishedRevision,
        'expected_parent_release_id' => 11, 'owned_paths' => [$argv[2]],
    ];
    ThemeScopePatch::$rows = [
        ['id' => 20, 'revision_id' => 6, 'path' => $argv[2], 'value' => 'Historical'],
        ['id' => 21, 'revision_id' => 7, 'path' => $argv[2], 'value' => 'Owned'],
    ];
    $history = ThemeScopePatch::$rows;
    $scoped->method('load')->willReturnCallback(static fn(): array => $state);
    $scoped->method('applyChanges')->willReturnCallback(
        static function ($actualContext, $revision, $parent, $changes) use ($context, &$state, &$owned): array {
            if ($actualContext !== $context || $revision !== 3 || $parent !== 11) {
                throw new \RuntimeException('Reset lost the workspace context or optimistic revision.');
            }
            $owned = (new ThemePatchEngine())->mergeOwnedCommands($owned, $changes);
            $state['revision']++;
            $state['draft_revision_id'] = 8;
            return ['revision' => 4, 'revision_id' => 8];
        },
    );
    $reflection = new \ReflectionClass(ThemeEditorDraftResetService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    if ($reflection->hasProperty('patches')) {
        $reflection->getProperty('patches')->setValue($service, new ThemeScopePatch());
    }
    if ($reflection->hasProperty('scopedWorkspace')) {
        $reflection->getProperty('scopedWorkspace')->setValue($service, $scoped);
    }
    $count = $reflection->getMethod('clearDraftPatchesForWorkspace')->invoke($service, new ThemeScopeWorkspace(), $context);
    echo json_encode([
        'history_before' => $history,
        'history_after' => ThemeScopePatch::$rows,
        'owned_after' => $owned,
        'revision_after' => $state['revision'],
        'draft_revision_after' => $state['draft_revision_id'],
        'published_revision_after' => $state['published_revision_id'],
        'count' => $count,
    ], JSON_THROW_ON_ERROR);
}
