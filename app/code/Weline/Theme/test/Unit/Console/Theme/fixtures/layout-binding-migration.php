<?php

declare(strict_types=1);

namespace Weline\Framework\Console {
    abstract class CommandAbstract {
        public object $printer;
        public function __construct() {
            $this->printer = new class {
                public array $messages = [];
                public function note(string $message): void { $this->messages[] = $message; }
                public function success(string $message): void { $this->messages[] = $message; }
            };
        }
    }
}
namespace Weline\Framework\Manager {
    final class ObjectManager {
        public static function getInstance(string $class): object { return new $class(); }
    }
}
namespace Weline\Theme\Service\LayoutEntity {
    final class ThemeLayoutEntityBakeCoordinator {
        public static array $call = [];
        public function getLastRebakeReport(): array { return ['migrated' => 3, 'unmapped' => [['entity' => 'r99', 'reason' => '历史身份无法映射']]]; }
        public function rebakeAfterInjectionCollect(?int $themeId, array $changes): int {
            self::$call = [$themeId, $changes];
            return 3;
        }
    }
}
namespace {
    $theme = dirname(__DIR__, 5);
    define('BP', sys_get_temp_dir() . '/weline-migration-test-' . bin2hex(random_bytes(6)));
    require $theme . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php';
    require $theme . '/Console/Theme/Layout/MigrateBindings.php';
    $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths();
    $legacy = $paths->pagePhtml(901, 'scope', 'identity', 'r99');
    mkdir(dirname($legacy), 0775, true);
    file_put_contents($legacy, 'old template');
    $command = new \Weline\Theme\Console\Theme\Layout\MigrateBindings();
    $command->execute();
    $result = ['call' => \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::$call,
        'messages' => $command->printer->messages, 'legacy_retained' => is_file($legacy)];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(BP, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir(BP);
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
