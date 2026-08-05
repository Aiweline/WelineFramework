<?php

declare(strict_types=1);

namespace Weline\Framework\Authorization\Resource;

use Weline\Framework\App\Env;

/**
 * Scans enabled modules' etc/resumable_tasks.php for backend_acl (D-9).
 * Does not depend on Weline\Acl.
 */
final class ResumableTaskAclScanner
{
    /**
     * @return list<array<string,mixed>>
     */
    public function scan(?array $activeModules = null): array
    {
        $modules = $activeModules;
        if ($modules === null) {
            $modules = Env::getInstance()->getActiveModules();
        }
        if (!\is_array($modules)) {
            return [];
        }

        $rows = [];
        foreach ($modules as $moduleName => $moduleMeta) {
            $moduleName = \is_string($moduleName) ? $moduleName : (string)($moduleMeta['name'] ?? '');
            if ($moduleName === '') {
                continue;
            }
            $basePath = '';
            if (\is_array($moduleMeta)) {
                $basePath = (string)($moduleMeta['base_path'] ?? $moduleMeta['path'] ?? '');
            }
            if ($basePath === '') {
                continue;
            }
            $file = \rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'resumable_tasks.php';
            if (!\is_file($file)) {
                continue;
            }
            $tasks = require $file;
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $taskId => $task) {
                if (!\is_array($task)) {
                    continue;
                }
                // Frontend-only tasks do not enter backend ACL catalog.
                $area = \strtolower(\trim((string)($task['area'] ?? $task['scope'] ?? 'backend')));
                if ($area === 'frontend' || $area === 'customer') {
                    continue;
                }
                if (!\array_key_exists('backend_acl', $task)) {
                    continue;
                }
                $rows[] = [
                    'module' => $moduleName,
                    'task_id' => \is_string($taskId) ? $taskId : (string)($task['id'] ?? $taskId),
                    'backend_acl' => $task['backend_acl'],
                    'name' => (string)($task['name'] ?? $task['title'] ?? $taskId),
                    'description' => (string)($task['description'] ?? ''),
                ];
            }
        }
        return $rows;
    }
}
