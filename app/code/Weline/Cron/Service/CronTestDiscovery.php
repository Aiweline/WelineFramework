<?php
declare(strict_types=1);

namespace Weline\Cron\Service;

use Weline\Cron\Attribute\CronTestHelp;
use Weline\Cron\CronTaskInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * 扫描各模块 Cron/*.php：带 #[CronTestHelp] 或存在 test(array):string 的类。
 */
final class CronTestDiscovery
{
    /**
     * @return list<array{id: string, class: string, module: string, description: string, examples: list<string>, manual_help: list<string>, has_test: bool, execute_name: ?string, tip: string}>
     */
    public static function discover(): array
    {
        $scan = ObjectManager::getInstance(Scan::class);
        $out = [];
        $seenIds = [];

        foreach (Env::getInstance()->getActiveModules() as $module) {
            $cronDir = ($module['base_path'] ?? '') . 'Cron';
            if (!\is_dir($cronDir)) {
                continue;
            }
            // remove_path 必须为空，否则 globFile 会改成相对路径，is_file() 在 CWD 下失败
            $files = [];
            $scan->globFile($cronDir . DS . '*', $files, '.php', '', '', false, false);
            foreach ($files as $path) {
                if (!\is_string($path) || !\is_file($path)) {
                    continue;
                }
                $fqcn = self::fqcnFromFile($path);
                if ($fqcn === null || !\class_exists($fqcn)) {
                    continue;
                }
                try {
                    $ref = new \ReflectionClass($fqcn);
                } catch (\Throwable) {
                    continue;
                }
                if (!$ref->isInstantiable()) {
                    continue;
                }
                $helpAttrs = $ref->getAttributes(CronTestHelp::class);
                $testMethod = $ref->hasMethod('test') ? $ref->getMethod('test') : null;
                $hasTest = $testMethod !== null
                    && $testMethod->isPublic()
                    && !$testMethod->isStatic()
                    && $testMethod->getNumberOfRequiredParameters() <= 1;

                if ($helpAttrs === [] && !$hasTest) {
                    continue;
                }

                $description = '';
                $examples = [];
                $manualHelp = [];
                if ($helpAttrs !== []) {
                    /** @var CronTestHelp $h */
                    $h = $helpAttrs[0]->newInstance();
                    $description = $h->description;
                    $examples = $h->examples;
                    $manualHelp = $h->manual_help;
                }

                $executeName = null;
                $tip = '';
                if ($ref->implementsInterface(CronTaskInterface::class)) {
                    try {
                        /** @var CronTaskInterface $inst */
                        $inst = ObjectManager::getInstance($fqcn);
                        $executeName = $inst->execute_name();
                        $tip = $inst->tip();
                    } catch (\Throwable) {
                        continue;
                    }
                    $id = $executeName;
                } else {
                    $short = $ref->getShortName();
                    $id = self::camelToSnake($short);
                }

                if (isset($seenIds[$id])) {
                    $id = $id . '_' . \preg_replace('/[^a-z0-9]/i', '_', $ref->getShortName());
                }
                $seenIds[$id] = true;

                $out[] = [
                    'id' => $id,
                    'class' => $fqcn,
                    'module' => (string) ($module['name'] ?? ''),
                    'description' => $description,
                    'examples' => $examples,
                    'manual_help' => $manualHelp,
                    'has_test' => $hasTest,
                    'execute_name' => $executeName,
                    'tip' => $tip,
                ];
            }
        }

        \usort($out, static fn (array $a, array $b): int => \strcmp($a['module'] . $a['id'], $b['module'] . $b['id']));

        return $out;
    }

    public static function findById(string $id): ?array
    {
        $id = \strtolower(\trim($id));
        foreach (self::discover() as $row) {
            if (\strtolower($row['id']) === $id) {
                return $row;
            }
            if ($row['execute_name'] !== null && \strtolower((string) $row['execute_name']) === $id) {
                return $row;
            }
        }

        return null;
    }

    private static function fqcnFromFile(string $path): ?string
    {
        $s = @\file_get_contents($path);
        if ($s === false) {
            return null;
        }
        if (!\preg_match('/namespace\s+([^;\s]+)\s*;/', $s, $m)) {
            return null;
        }
        if (!\preg_match('/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $s, $c)) {
            return null;
        }

        return $m[1] . '\\' . $c[1];
    }

    private static function camelToSnake(string $s): string
    {
        return \strtolower((string) \preg_replace('/(?<!^)[A-Z]/', '_$0', $s));
    }
}
