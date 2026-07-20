<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Resumable\ResumableTaskHandlerInterface;

/**
 * Discovers explicit `etc/resumable_tasks.php` declarations from enabled
 * modules. This is intentionally separate from Queue/Async registration.
 */
final class ResumableTaskHandlerRegistry
{
    /** @var array<string,ResumableTaskTypeDefinition>|null */
    private ?array $definitions = null;

    /**
     * @param list<string>|null $configurationFiles Test-only explicit config paths.
     */
    public function __construct(
        private readonly ?array $configurationFiles = null,
    ) {
    }

    public function definition(string $typeCode): ResumableTaskTypeDefinition
    {
        $definition = $this->definitions()[$typeCode] ?? null;
        if ($definition === null) {
            throw new ResumableTaskStoreException('unknown_task_type', 'The requested resumable task type is not registered.');
        }
        return $definition;
    }

    public function handler(string $typeCode): ResumableTaskHandlerInterface
    {
        $definition = $this->definition($typeCode);
        $handler = ObjectManager::getInstance($definition->handlerClass);
        if (!$handler instanceof ResumableTaskHandlerInterface || $handler->typeCode() !== $definition->typeCode) {
            throw new ResumableTaskStoreException('invalid_task_handler', 'The registered resumable task handler violates its declaration.');
        }
        return $handler;
    }

    /** @return array<string,ResumableTaskTypeDefinition> */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];
        // ObjectManager supplies an empty array for optional array arguments.
        // Empty means "use enabled-module discovery", not "disable every
        // handler"; only a non-empty test list overrides discovery.
        foreach (($this->configurationFiles !== null && $this->configurationFiles !== [])
            ? $this->configurationFiles
            : $this->discoverConfigurationFiles() as $file) {
            $module = $this->moduleForConfigurationFile($file);
            $declared = require $file;
            if (!is_array($declared)) {
                throw new \RuntimeException("Resumable task declaration must return an array: {$file}");
            }
            foreach ($declared as $typeCode => $entry) {
                $typeCode = trim((string)$typeCode);
                $handlerClass = is_string($entry) ? $entry : (string)($entry['handler'] ?? '');
                $declaredModule = is_array($entry) ? trim((string)($entry['module'] ?? $module)) : $module;
                if ($typeCode === '' || $handlerClass === '' || !class_exists($handlerClass)
                    || !is_a($handlerClass, ResumableTaskHandlerInterface::class, true)) {
                    throw new \RuntimeException("Invalid resumable task declaration: {$file}:{$typeCode}");
                }
                if (isset($definitions[$typeCode])) {
                    throw new \RuntimeException("Duplicate resumable task type declaration: {$typeCode}");
                }
                /** @var class-string<ResumableTaskHandlerInterface> $handlerClass */
                $definitions[$typeCode] = new ResumableTaskTypeDefinition($typeCode, $declaredModule, $handlerClass);
            }
        }
        ksort($definitions);
        return $this->definitions = $definitions;
    }

    /** @return list<string> */
    private function discoverConfigurationFiles(): array
    {
        $files = [];
        $env = Env::getInstance();
        foreach ($env->getActiveModules() as $moduleName => $module) {
            // Env's DataObject accessor is not the module-path resolver. Its
            // value is normally empty, which silently made every registered
            // task type undiscoverable. Active module metadata already owns
            // the canonical absolute base path for app and vendor modules.
            $modulePath = is_array($module) ? trim((string)($module['base_path'] ?? '')) : '';
            if ($modulePath === '') {
                continue;
            }
            $file = rtrim($modulePath, '/\\') . '/etc/resumable_tasks.php';
            if (is_file($file)) {
                $files[] = $file;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function moduleForConfigurationFile(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        if (preg_match('#/app/code/([^/]+)/([^/]+)/etc/resumable_tasks\\.php$#', $normalized, $matches) === 1) {
            return $matches[1] . '_' . $matches[2];
        }
        return 'external';
    }
}
