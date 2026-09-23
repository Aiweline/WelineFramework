<?php

declare(strict_types=1);

namespace Weline\Framework\App\Controller {
    class BackendController { public object $session; }
}
namespace Weline\Framework\Manager {
    class ObjectManager {
        public static array $instances = [];
        public static function getInstance(string $class): object { return self::$instances[$class]; }
    }
}
namespace {
    require dirname(__DIR__, 8) . '/vendor/autoload.php';
    require dirname(__DIR__, 4) . '/Controller/Backend/ThemeEditor.php';
    use Weline\Framework\Manager\ObjectManager;
    use Weline\Framework\Runtime\ScopeIdentity;
    use Weline\SystemConfig\Api\Scope\ScopeContext;
    use Weline\Theme\Api\Scoped\ThemeEditorContext;
    use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
    use Weline\Theme\Controller\Backend\ThemeEditor;
    use Weline\Theme\Service\Scoped\ThemeScopedLayoutWriteService;
    use Weline\Theme\Service\ThemeChromeWidgetRemovalService;

    $scenario = $argv[1];
    $uid = str_repeat('a', 32);
    ObjectManager::$instances[ThemeChromeWidgetRemovalService::class] = new class($scenario) {
        public function __construct(private string $scenario) {}
        public function remove(ThemeEditorContext $context, string $uid, string $actor): ?array {
            return $this->scenario === 'chrome' ? ['status' => 'removed'] : null;
        }
    };
    $workspace = new class($scenario, $uid) {
        public int $loads = 0;
        public function __construct(private string $scenario, private string $uid) {}
        public function load(ThemeEditorContext $context, bool $draft): array {
            $this->loads++;
            return ['draft_payload' => ['nodes' => $this->scenario === 'missing' ? [] : [$this->uid => ['node_uid' => $this->uid]]]];
        }
    };
    $writer = new class {
        public array $removed = [];
        public function removeWidget(ThemeEditorContext $context, string $uid, string $actor, string $name): void {
            $this->removed[] = [$context->layoutType, $uid];
        }
    };
    ObjectManager::$instances[ThemeScopedWorkspaceInterface::class] = $workspace;
    ObjectManager::$instances[ThemeScopedLayoutWriteService::class] = $writer;
    $reflection = new ReflectionClass(ThemeEditor::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $controller->session = new class {
        public function getUserId(): int { return 1; }
        public function getUsername(): string { return 'fixture'; }
    };
    $context = new ThemeEditorContext(new ScopeContext(ScopeIdentity::global(), '0.0.0', 'normal', ['0.0.0']), 'frontend', themeId: 3, layoutType: 'product');
    $result = $reflection->getMethod('removeScopedLayoutNodeFromWorkspace')->invoke($controller, $context, $uid);
    echo json_encode(['removed' => $result, 'layout_writes' => $writer->removed, 'layout_reads' => $workspace->loads], JSON_THROW_ON_ERROR);
}
