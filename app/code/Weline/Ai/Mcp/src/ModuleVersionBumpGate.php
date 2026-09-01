<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Fail closed when an edit-plan touches registration-affecting module paths
 * without bumping that module's etc/module.php version.
 *
 * Authority: Framework/doc/3-开发/模块版本与升级门禁.md + hard-constraints module_version_bump.
 */
final class ModuleVersionBumpGate
{
    /**
     * @param array<string, string> $pathToPostContent project-relative path => postimage
     * @param array<string, string> $pathToPreContent project-relative path => preimage (empty for creates)
     * @throws ToolException
     */
    public static function assertPlanSatisfies(array $pathToPostContent, array $pathToPreContent = []): void
    {
        $affected = [];
        foreach (array_keys($pathToPostContent) as $path) {
            $module = self::moduleOfRegistrationAffectingPath($path);
            if ($module === null) {
                continue;
            }
            $key = $module['module_key'];
            if (!isset($affected[$key])) {
                $affected[$key] = $module;
                $affected[$key]['trigger_paths'] = [];
            }
            $affected[$key]['trigger_paths'][] = $path;
        }
        if ($affected === []) {
            return;
        }

        $violations = [];
        foreach ($affected as $moduleKey => $module) {
            $modulePhp = $module['module_php'];
            if (!\array_key_exists($modulePhp, $pathToPostContent)) {
                $violations[] = [
                    'module' => $moduleKey,
                    'reason' => 'module_php_not_in_plan',
                    'required_path' => $modulePhp,
                    'trigger_paths' => $module['trigger_paths'],
                    'hint' => 'Bump etc/module.php version in the same edit-plan (at least patch +1).',
                    'upgrade_commands' => self::upgradeCommands($moduleKey, $module['trigger_paths']),
                ];
                continue;
            }

            $pre = (string) ($pathToPreContent[$modulePhp] ?? '');
            $post = (string) $pathToPostContent[$modulePhp];
            $oldVersion = self::parseVersion($pre) ?? '0.0.0';
            $newVersion = self::parseVersion($post);
            if ($newVersion === null) {
                $violations[] = [
                    'module' => $moduleKey,
                    'reason' => 'module_php_version_missing',
                    'required_path' => $modulePhp,
                    'old_version' => $oldVersion,
                    'trigger_paths' => $module['trigger_paths'],
                    'hint' => 'etc/module.php postimage must define a string version key.',
                    'upgrade_commands' => self::upgradeCommands($moduleKey, $module['trigger_paths']),
                ];
                continue;
            }
            if (version_compare($newVersion, $oldVersion, '<=')) {
                $violations[] = [
                    'module' => $moduleKey,
                    'reason' => 'module_php_version_not_increased',
                    'required_path' => $modulePhp,
                    'old_version' => $oldVersion,
                    'new_version' => $newVersion,
                    'trigger_paths' => $module['trigger_paths'],
                    'hint' => 'version must strictly increase (semver). Example: '
                        . $oldVersion . ' → ' . self::suggestNextPatch($oldVersion),
                    'upgrade_commands' => self::upgradeCommands($moduleKey, $module['trigger_paths']),
                ];
            }
        }

        if ($violations === []) {
            return;
        }

        throw new ToolException(
            'EDIT_MODULE_VERSION_REQUIRED',
            'Registration-affecting edits require a same-plan etc/module.php version bump '
            . 'and a subsequent setup:upgrade (or --route).',
            false,
            [
                'violations' => $violations,
                'doc' => 'app/code/Weline/Framework/doc/3-开发/模块版本与升级门禁.md',
                'hard_constraint' => 'module_version_bump',
            ],
        );
    }

    /**
     * @return array{module_key:string,vendor:string,module:string,module_php:string,trigger_paths:list<string>}|null
     */
    public static function moduleOfRegistrationAffectingPath(string $path): ?array
    {
        $path = str_replace('\\', '/', ltrim(trim($path), '/'));
        if (!preg_match('#^app/code/([^/]+)/([^/]+)/(.*)$#', $path, $matches)) {
            return null;
        }
        $vendor = $matches[1];
        $module = $matches[2];
        $rest = $matches[3];
        if (!self::isRegistrationAffectingRelative($rest)) {
            return null;
        }

        return [
            'module_key' => $vendor . '_' . $module,
            'vendor' => $vendor,
            'module' => $module,
            'module_php' => 'app/code/' . $vendor . '/' . $module . '/etc/module.php',
            'trigger_paths' => [$path],
        ];
    }

    public static function isRegistrationAffectingRelative(string $rest): bool
    {
        $rest = str_replace('\\', '/', ltrim($rest, '/'));
        if ($rest === 'hook.php' || $rest === 'register.php' || $rest === 'etc/event.xml') {
            return true;
        }
        if (str_starts_with($rest, 'Model/') || str_starts_with($rest, 'Controller/')) {
            return str_ends_with(strtolower($rest), '.php');
        }

        return false;
    }

    public static function parseVersion(string $phpSource): ?string
    {
        if ($phpSource === '') {
            return null;
        }
        if (!preg_match("/['\"]version['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $phpSource, $matches)) {
            return null;
        }
        $version = trim($matches[1]);

        return $version !== '' ? $version : null;
    }

    public static function suggestNextPatch(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));
        while (count($parts) < 3) {
            $parts[] = 0;
        }
        $parts[2]++;

        return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
    }

    /**
     * @param list<string> $triggerPaths
     * @return list<string>
     */
    private static function upgradeCommands(string $moduleKey, array $triggerPaths): array
    {
        $needsSchema = false;
        $needsRoute = false;
        foreach ($triggerPaths as $path) {
            $norm = str_replace('\\', '/', $path);
            if (str_contains($norm, '/Model/') || str_ends_with($norm, '/etc/event.xml') || str_ends_with($norm, '/register.php')) {
                $needsSchema = true;
            }
            if (str_contains($norm, '/Controller/') || str_ends_with($norm, '/hook.php')) {
                $needsRoute = true;
            }
        }
        if ($needsSchema) {
            return ['php bin/w setup:upgrade -m ' . $moduleKey];
        }
        if ($needsRoute) {
            return ['php bin/w setup:upgrade --route --module=' . $moduleKey];
        }

        return ['php bin/w setup:upgrade -m ' . $moduleKey];
    }
}
