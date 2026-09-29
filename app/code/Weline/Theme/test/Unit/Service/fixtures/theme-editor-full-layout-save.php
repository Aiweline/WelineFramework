<?php
declare(strict_types=1);

// Run the real HTTP action and canonical form conversion with only external
// controller/persistence boundaries replaced. No configured owner is changed.
namespace Weline\Framework\App\Controller {
    class BackendController {
        public object $session;
        public object $request;
        public function fetchJson(array $data): string { return json_encode($data, JSON_THROW_ON_ERROR); }
        public function getEventManager(): object { return new class { public function dispatch(...$args): void {} }; }
    }
}
namespace Weline\Framework\Manager {
    class ObjectManager {
        public static array $instances = [];
        public static function getInstance(string $class): object { return self::$instances[$class]; }
    }
}
namespace Weline\Theme\Service {
    if (!str_starts_with($argv[1] ?? '', 'compat_')) { class ThemeLayoutService {
        public static int $calls = 0;
        public function saveLayout(...$args): bool { self::$calls++; throw new \RuntimeException('legacy_outer_transaction_path'); }
    } }
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
    use Weline\Theme\Service\Scoped\ThemeLayoutPayloadDiffer;
    use Weline\Theme\Service\Scoped\ThemeLayoutSnapshotNormalizer;
    use Weline\Theme\Service\Scoped\ThemeNodePlacementResolver;
    use Weline\Theme\Service\Scoped\ThemePatchEngine;
    use Weline\Theme\Service\Scoped\ThemeScopedEditorSaveService;
    use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySaveException;
    use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
    use Weline\Theme\Service\ThemeLayoutService;
    use Weline\Theme\Service\ThemeScopeVersionService;

    $scenario = $argv[1];
    $context = new ThemeEditorContext(new ScopeContext(ScopeIdentity::website(0, 'default'), 'default.__store__.__channel__', 'normal', ['default.__store__.__channel__']), 'frontend', 'layout', 1, 'homepage');
    $node = static fn(string $uid, string $code, string $area, string $slot): array => [
        'node_uid' => str_repeat($uid, 32), 'widget_module' => 'Weline_Test', 'widget_type' => 'content', 'widget_code' => $code,
        'area' => $area, 'slot_id' => $slot, 'sort_order' => 0, 'is_active' => true, 'source' => 'default_injection', 'config' => [],
    ];
    $before = ['theme_id' => 1, 'nodes' => [
        str_repeat('a', 32) => $node('a', 'content', 'content', 'homepage-main'),
        str_repeat('d', 32) => array_replace($node('d', 'removed-music', 'content', 'content'), ['is_active' => false, 'source' => 'user_deleted']),
        str_repeat('c', 32) => $node('c', 'header', 'header', 'header'),
        str_repeat('e', 32) => $node('e', 'footer', 'footer', 'footer'),
        str_repeat('f', 32) => $node('f', 'all-menu', 'content', 'all-menu'),
    ], 'selection' => ['layout_option' => 'default']];
    $payload = $before;
    $resourceRevision = 4;
    $GLOBALS['fixture_content_revision'] = 3;
    $events = [];
    $projection = [];
    $normalizer = new ThemeLayoutSnapshotNormalizer(new ThemeNodePlacementResolver());
    $test = new class('fixture') extends \PHPUnit\Framework\TestCase {
        public function workspace(): ThemeScopedWorkspaceInterface { return $this->createMock(ThemeScopedWorkspaceInterface::class); }
        public function double(string $class): object { return $this->createMock($class); }
        public function facade(): object { return $this->getMockBuilder(ThemeLayoutService::class)->disableOriginalConstructor()->onlyMethods(['getLayout'])->getMock(); }
    };
    $workspace = $test->workspace();
    $workspace->method('load')->willReturnCallback(static function () use (&$resourceRevision, &$payload): array {
        return ['revision' => $resourceRevision, 'expected_parent_release_id' => 284, 'draft_payload' => $payload];
    });
    $workspace->method('replaceEffectivePayload')->willReturnCallback(static function ($actualContext, $revision, $parent, $target) use ($context, $scenario, $normalizer, &$resourceRevision, &$payload, &$events, &$projection): array {
        $events[] = 'replace.begin';
        if ($actualContext->identityHash() !== $context->identityHash()) { throw new RuntimeException('context_changed'); }
        if ($revision !== $resourceRevision) { throw new RuntimeException('theme_scope_revision_conflict'); }
        if ($parent !== 284) { throw new RuntimeException('theme_scope_parent_release_conflict'); }
        $changes = (new ThemeLayoutPayloadDiffer())->diff($payload, $target);
        $next = (new ThemePatchEngine())->apply($payload, $changes);
        foreach ($normalizer->denormalize($context, $next) as $area) {
            foreach ($area['widgets'] as $widget) {
                $projection[] = $widget['widget_code'];
                if ($widget['widget_code'] === '__no_widget_placements__') { throw new RuntimeException('layout_state_is_not_a_registered_widget'); }
            }
        }
        $payload = $next;
        $events[] = 'db.commit';
        $saved = ['revision' => ++$resourceRevision, 'revision_id' => 99, 'theme_version_id' => 7, 'content_revision' => ++$GLOBALS['fixture_content_revision'], 'draft_payload' => $payload];
        $events[] = 'phtml.publish';
        if ($scenario === 'bake_failure') { throw new ThemeLayoutEntitySaveException($saved, new RuntimeException('candidate_write_failed')); }
        return $saved;
    });
    ObjectManager::$instances[ThemeScopedEditorSaveService::class] = new ThemeScopedEditorSaveService($workspace);
    ObjectManager::$instances[ThemeLayoutSnapshotNormalizer::class] = $normalizer;
    ObjectManager::$instances[ThemeEditorContextFactory::class] = new class($context) {
        public function __construct(private object $context) {}
        public function fromInput(array $input, string $resource): object { return $this->context; }
    };
    ObjectManager::$instances[ThemeLayoutScopeNormalizer::class] = new class {
        public function encodeStorageScope(string $scope, string $mode): string { return $scope; }
    };
    ObjectManager::$instances[ThemeScopeVersionService::class] = new class {
        public function getCurrent(...$args): object { return new class {
            public function clearData(): static { return $this; }
            public function clearQuery(): static { return $this; }
            public function load(int $id): static { return $this; }
            public function getVersionId(): int { return 7; }
            public function getContentRevision(): int { return $GLOBALS['fixture_content_revision']; }
        }; }
    };
    $form = [];
    if (!in_array($scenario, ['empty', 'empty_then_nonempty'], true)) {
        $form = ['content' => [
            $before['nodes'][str_repeat('a', 32)],
            array_replace($node('b', 'child', 'content', 'children'), [
                'parent_uid' => str_repeat('a', 32), 'anchor_uid' => null, 'position' => 'inside', 'sort_order' => 17,
                'is_active' => false, 'config' => ['enabled' => false, 'nullable' => null, 'items' => [], 'zero' => 0],
            ]),
        ]];
    }
    $input = ['theme_id' => 1, 'page_type' => 'homepage', 'editor_context' => $context->toArray(), 'layout_data' => $form,
        'expected_revision' => $scenario === 'revision' ? 3 : 4, 'expected_parent_release_id' => $scenario === 'parent' ? 283 : 284,
        'expected_content_revision' => $scenario === 'content_revision' ? 2 : 3];
    if (str_starts_with($scenario, 'compat_')) {
        $outerCalls = 0;
        $transactions = $test->double(\Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface::class);
        $transactions->method('isActive')->willReturn(false);
        $transactions->method('runWrite')->willReturnCallback(static function () use (&$outerCalls): never {
            $outerCalls++;
            throw new RuntimeException('legacy_outer_transaction_path');
        });
        $connection = $test->double(\Weline\Framework\Database\ConnectionFactory::class);
        $layout = $test->double(\Weline\Theme\Model\ThemeLayout::class);
        $layout->method('getConnection')->willReturn($connection);
        $scopes = $test->double(\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class);
        $scopeNormalizer = new ThemeLayoutScopeNormalizer($scopes);
        $facade = $test->facade();
        $facadeReflection = new ReflectionClass(ThemeLayoutService::class);
        foreach (['themeLayout' => $layout, 'scopeNormalizer' => $scopeNormalizer, 'transactions' => $transactions] as $key => $value) {
            $facadeReflection->getProperty($key)->setValue($facade, $value);
        }
        ObjectManager::$instances[\Weline\Theme\Service\ThemeRuntimeLayoutResolver::class] = new class($context) {
            public function __construct(private object $context) {}
            public function buildContext(...$args): object { return $this->context; }
        };
        $facade->method('getLayout')->willReturn(['content' => ['widgets' => $form['content']]]);
        if ($scenario === 'compat_copy') {
            $success = $facade->copyLayout(1, 'source', 'homepage');
        } else {
            $wrapperReflection = new ReflectionClass(\Weline\Theme\Service\LayoutWorkspace::class);
            $wrapper = $wrapperReflection->newInstanceWithoutConstructor();
            foreach (['layoutService' => $facade, 'layout' => $layout, 'transactions' => $transactions] as $key => $value) {
                $wrapperReflection->getProperty($key)->setValue($wrapper, $value);
            }
            $success = $wrapper->replaceLayout(1, 'homepage', $form, \Weline\Theme\Api\Layout\LayoutStatus::DRAFT,
                new \Weline\Theme\Api\Layout\LayoutIdentity(scope: $context->scope->storageScope));
        }
        echo json_encode(['response' => ['success' => $success], 'events' => $events, 'legacy_calls' => $outerCalls], JSON_THROW_ON_ERROR);
        exit;
    }
    $reflection = new ReflectionClass(ThemeEditor::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('layoutService')->setValue($controller, new ThemeLayoutService());
    $controller->request = new class($input) {
        public function __construct(private array $input) {}
        public function getData(string $key): array { return $this->input; }
        public function replace(array $input): void { $this->input = $input; }
    };
    $controller->session = new class {
        public function getUserId(): int { return 1; }
        public function getUsername(): string { return 'fixture'; }
    };
    $response = json_decode($controller->postSaveLayout(), true, flags: JSON_THROW_ON_ERROR);
    $emptyResponse = null;
    $emptyPayload = null;
    $renderedPage = null;
    if ($scenario === 'empty_then_nonempty') {
        $emptyResponse = $response;
        $emptyPayload = $payload;
        $input['layout_data'] = ['content' => [$node('b', 'replacement', 'content', 'homepage-bottom')]];
        $input['expected_revision'] = $resourceRevision;
        $input['expected_content_revision'] = $GLOBALS['fixture_content_revision'];
        $controller->request->replace($input);
        $response = json_decode($controller->postSaveLayout(), true, flags: JSON_THROW_ON_ERROR);
        $registry = $test->double(\Weline\Theme\Service\ThemePlaceableRegistry::class);
        $registry->method('find')->willReturn(null);
        ObjectManager::$instances[\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityWidgetRenderer::class] = new class {
            public function renderResolved(array $entry, ...$args): string { return '<b>' . $entry['widget_code'] . '</b>'; }
        };
        $source = '<main><section data-wslot="homepage-main"><w:widget module="Weline_Test" type="content" code="template-default">TEMPLATE-DEFAULT-ONE</w:widget><?php echo "<i>DYNAMIC</i>"; ?></section>'
            . '<section data-wslot="homepage-bottom"><w:widget module="Weline_Test" type="content" code="template-default-two">TEMPLATE-DEFAULT-TWO</w:widget></section></main>';
        $compiled = (new \Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler($registry))->compile($source, $payload['nodes']);
        ob_start();
        try { eval('?>' . $compiled); $renderedPage = (string)ob_get_contents(); }
        finally { ob_end_clean(); }
    }
    echo json_encode(['response' => $response, 'before' => $before, 'payload' => $payload, 'events' => $events,
        'legacy_calls' => ThemeLayoutService::$calls, 'projected_widget_codes' => $projection,
        'empty_response' => $emptyResponse, 'empty_payload' => $emptyPayload, 'rendered_page' => $renderedPage], JSON_THROW_ON_ERROR);
}
