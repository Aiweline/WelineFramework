<?php
declare(strict_types=1);

namespace Weline\Framework\Manager {
    class ObjectManager {
        public static array $instances = [];
        public static function getInstance(string $class): object { return self::$instances[$class] ??= new $class(); }
    }
}
namespace Weline\Framework\Runtime {
    class RequestContext { public static function getId(): ?string { return null; } }
}
namespace Weline\Framework\Output\Cli {
    class Printing { public function note($text): void {} public function warning($text): void {} public function success($text): void {} }
}
namespace Weline\Theme\Model {
    class ThemeScopeVersion {
        public function clearData(): self { return $this; }
        public function clearQuery(): self { return $this; }
        public function setData($data): self { return $this; }
        public function select(): self { return $this; }
        public function fetchArray(): array { return [['version_id'=>1]]; }
        public function toVersionIdentity(): \Weline\Theme\Api\Version\ThemeVersionIdentity { return $GLOBALS['identity']; }
    }
    class WelineTheme extends ThemeScopeVersion { public function load($id): self { return $this; } }
}
namespace Weline\Theme\Helper {
    class LayoutScanner { public static function scanLayouts(...$args): array { return ['homepage'=>['default']]; } }
}
namespace Weline\Theme\Service\Version {
    class ThemeVersionResourceSnapshotService {
        public function head($identity): array { return ['package_default_json'=>'{"current_package_defaults":true}']; }
        public function resources($identity): array { return []; }
    }
}
namespace Weline\Theme\Service\LayoutEntity {
    class RequiredDefaultInjectionBakeMerger { public function loadDeclarations(): array { return []; } }
}
namespace Weline\Theme\Service {
    class ThemeLayoutScopeNormalizer {
        public function identityFromEncodedScope($scope): object { return (object)['websiteCode'=>'fixture']; }
    }
    class ThemeRuntimeCacheCleaner { public function clearAllThemeRelatedCaches(...$args): array { return []; } }
    /** Fixture stub: upgrade solidify scopes to website-bound themes only. */
    class ThemeApplicationUsageService {
        public function themesForDefaultUpgrade(): array { return []; }
    }
    class ThemeRuntimeLayoutResolver {
        public function buildContext(...$args): never {
            if ($GLOBALS['scenario'] === 'write') {
                // Real file staging fails because this candidate's basename
                // exceeds the filesystem limit, regardless of process UID.
                (new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBatchPublisher())->publish(
                    $GLOBALS['identity'], [$GLOBALS['bad_candidate']=>'new source']);
                throw new \RuntimeException('fixture_expected_real_write_failure');
            }
            if ($GLOBALS['scenario'] === 'compile') { throw new \ParseError('fixture_candidate_compile_error'); }
            if (in_array($GLOBALS['scenario'], ['missing-owner','existing-owner'],true)) { throw new \InvalidArgumentException('system_config_website_scope_not_found'); }
            throw new \RuntimeException('historical_resource_snapshot_missing');
        }
    }
}
namespace Weline\SystemConfig\Api\Scope {
    class ScopeIdentityCatalogInterface {
        public function websiteIdForCode($code): int {
            if ($GLOBALS['scenario'] === 'missing-owner') { throw new \InvalidArgumentException('system_config_website_scope_not_found'); }
            return 0;
        }
    }
}
namespace {
    function __($text): string { return (string)$text; }
    $theme = dirname(__DIR__, 4);
    $parent = sys_get_temp_dir() . '/weline-upgrade-boundary-' . bin2hex(random_bytes(6));
    mkdir($parent, 0770, true);
    define('BP', $parent);
    foreach (['Api/Version/ThemeVersionIdentity.php','Service/LayoutEntity/ThemeLayoutEntityPaths.php',
        'Service/LayoutEntity/ThemeLayoutEntityOwnerLock.php','Service/LayoutEntity/ThemeLayoutEntityBatchPublisher.php',
        'Service/LayoutEntity/ThemeLayoutEntityInjectionTargets.php','Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php',
        'Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php'] as $file) { require $theme . '/' . $file; }
    $scenario = $argv[1];
    $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(1,'fixture.store.channel','normal','frontend',1,'formal',1);
    $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths($parent . '/theme-layout-entities');
    \Weline\Framework\Manager\ObjectManager::$instances[$paths::class] = $paths;
    $old = $paths->root() . '1/frontend/legacy/v1/page.phtml';
    mkdir(dirname($old),0770,true); file_put_contents($old,'old source');
    $sidecar = dirname($old) . '/binding.json'; file_put_contents($sidecar,'old binding');
    $bad_candidate = $paths->root() . '1/frontend/scope/fixture/store/channel/mode/normal/v1/' . str_repeat('x',260) . '.phtml';
    $coordinator = (new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class))->newInstanceWithoutConstructor();
    $service = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradeSolidifyService($paths,$coordinator,
        new \Weline\Theme\Service\ThemeRuntimeCacheCleaner(),new \Weline\Framework\Output\Cli\Printing(),
        new \Weline\Theme\Service\ThemeApplicationUsageService());
    try {
        $result = $service->cutoverFromThemeCommand(null);
        $error = null;
    } catch (\Throwable $exception) { $error = $exception->getMessage(); $result = null; }
    echo json_encode(['error'=>$error,'old_source_exists'=>is_file($old),'sidecar_exists'=>is_file($sidecar),
        'unmapped'=>$result['unmapped']??[]],JSON_THROW_ON_ERROR), "\n";
    $paths->purgeAllEntities(); rmdir($parent);
}
