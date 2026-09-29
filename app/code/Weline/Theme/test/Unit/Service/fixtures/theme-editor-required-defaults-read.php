<?php
declare(strict_types=1);

// Execute the real controller actions. Authentication and persistence are
// isolated boundaries; no configured owner, database or files are written.
namespace Weline\Framework\App\Controller {
    class BackendController {
        public object $request;
        public function fetchJson(array $data): string { return json_encode($data, JSON_THROW_ON_ERROR); }
    }
}
namespace Weline\Framework\Manager {
    class ObjectManager {
        public static array $instances = [];
        public static function getInstance(string $class): object { return self::$instances[$class]; }
    }
}
namespace {
    require dirname(__DIR__, 8) . '/vendor/autoload.php';
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Weline\\')) {
            $path = dirname(__DIR__, 8) . '/app/code/' . str_replace('\\', '/', $class) . '.php';
            if (is_file($path)) { require $path; }
        }
    }, true, true);
    if (!function_exists('__')) { function __(string $message): string { return $message; } }

    use Weline\Framework\Manager\ObjectManager;
    use Weline\Framework\Runtime\ScopeIdentity;
    use Weline\SystemConfig\Api\Scope\ScopeContext;
    use Weline\Theme\Api\Scoped\ThemeEditorContext;
    use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
    use Weline\Theme\Controller\Backend\ThemeEditor;
    use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;
    use Weline\Theme\Service\Scoped\ThemeScopedWorkspaceRequestService;
    use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
    use Weline\Theme\Service\WidgetDefaultInjectionService;
    use Weline\Theme\Service\SlotRendererService;

    $context = new ThemeEditorContext(new ScopeContext(ScopeIdentity::website(0, 'default'),
        'default.__store__.__channel__', 'normal', ['default.__store__.__channel__']), 'frontend', 'layout', 1, 'homepage');
    $store = (object)['writes' => 0, 'revision' => 8, 'payload' => ['nodes' => [
        'removed' => ['node_uid' => 'removed', 'source' => 'user_deleted', 'is_active' => false],
        'clear' => ['widget_code' => '__no_widget_placements__'],
    ]]];
    $before = $store->payload;
    $workspace = new class($store) {
        public function __construct(private object $store) {}
        public function load(...$args): array { return ['revision' => 6, 'content_revision' => $this->store->revision, 'draft_payload' => $this->store->payload]; }
    };
    ObjectManager::$instances[ThemeScopedWorkspaceInterface::class] = $workspace;
    ObjectManager::$instances[ThemeEditorContextFactory::class] = new class($context) {
        public function __construct(private object $context) {}
        public function fromInput(...$args): object { return $this->context; }
    };
    ObjectManager::$instances[ThemeLayoutScopeNormalizer::class] = new class {
        public function encodeStorageScope(string $scope, string $mode): string { return $scope; }
    };
    ObjectManager::$instances[ThemeScopedWorkspaceRequestService::class] = new class($workspace) {
        public function __construct(private object $workspace) {}
        public function load(array $input): array { return $this->workspace->load(); }
    };
    ObjectManager::$instances[WidgetDefaultInjectionService::class] = new class($store) {
        public function __construct(private object $store) {}
        public function reconcileRequiredDefaultsForIdentity(...$args): array { return $this->install(); }
        public function applyRequiredMissingForIdentity(...$args): array { return $this->install(); }
        private function install(): array {
            ++$this->store->writes;
            ++$this->store->revision;
            $this->store->payload['nodes']['added'] = ['widget_code' => 'default-component', 'is_active' => true];
            return ['applied' => 1, 'skipped' => 0, 'blockers' => [], 'items' => []];
        }
    };
    ObjectManager::$instances[SlotRendererService::class] = new class { public function clearCache(): void {} };
    $input = ['theme_id' => 1, 'page_type' => 'homepage', 'editor_context' => $context->toArray()];
    $controller = (new ReflectionClass(ThemeEditor::class))->newInstanceWithoutConstructor();
    $controller->request = new class($input) {
        public function __construct(private array $input) {}
        public function getBodyParams(): array { return $this->input; }
        public function getParams(): array { return $this->input; }
        public function getParam(string $key, mixed $default = null): mixed { return $this->input[$key] ?? $default; }
        public function getData(string $key): mixed { return null; }
    };
    $response = ($argv[1] ?? '') === 'apply' ? $controller->postApplyRequiredDefaults() : $controller->postReconcileRequiredDefaults();
    echo json_encode(['response' => json_decode($response, true, flags: JSON_THROW_ON_ERROR), 'before' => $before,
        'payload' => $store->payload, 'writes' => $store->writes, 'revision' => $store->revision], JSON_THROW_ON_ERROR), "\n";
}
