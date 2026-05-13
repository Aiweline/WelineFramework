<?php

declare(strict_types=1);

namespace Weline\Frontend\Service\Head;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Interface\HeadContextProviderInterface;
use Weline\Frontend\Interface\HeadPolicyProviderInterface;

class HeadProviderRegistry
{
    /**
     * @var array{context: array<int, HeadContextProviderInterface>, policy: array<int, HeadPolicyProviderInterface>}|null
     */
    private ?array $cachedProviders = null;

    public function __construct(
        private readonly ObjectManager $objectManager
    ) {
    }

    /**
     * @return HeadContextProviderInterface[]
     */
    public function getContextProviders(bool $forceReload = false): array
    {
        return $this->getProviders($forceReload)['context'];
    }

    /**
     * @return HeadPolicyProviderInterface[]
     */
    public function getPolicyProviders(bool $forceReload = false): array
    {
        return $this->getProviders($forceReload)['policy'];
    }

    /**
     * @return array{context: array<int, HeadContextProviderInterface>, policy: array<int, HeadPolicyProviderInterface>}
     */
    private function getProviders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->cachedProviders !== null) {
            return $this->cachedProviders;
        }

        $providers = [
            'context' => [],
            'policy' => [],
        ];
        $seen = [];

        foreach ($this->extensionRows($forceReload) as $extension) {
            $this->registerExtension($providers, $seen, $extension);
        }

        $this->cachedProviders = $providers;
        return $providers;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extensionRows(bool $forceReload): array
    {
        $rows = [];

        try {
            foreach (ExtendsData::getExtendedBy('Weline_Frontend', $forceReload) as $extensions) {
                foreach ($extensions as $extension) {
                    if (is_array($extension)) {
                        $rows[] = $extension;
                    }
                }
            }
        } catch (\Throwable) {
        }

        foreach ($this->scanSourceExtensionRows() as $extension) {
            $rows[] = $extension;
        }

        return $rows;
    }

    /**
     * Runtime fallback for freshly added providers before generated/extends.php is rebuilt.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanSourceExtensionRows(): array
    {
        $rows = [];

        try {
            $modules = Env::getInstance()->getModuleList();
        } catch (\Throwable) {
            return [];
        }

        foreach ($modules as $moduleName => $module) {
            if (empty($module['status']) || empty($module['base_path'])) {
                continue;
            }
            $basePath = rtrim((string)$module['base_path'], '/\\');
            foreach (['HeadContextProvider', 'HeadPolicyProvider'] as $extendName) {
                $dir = $basePath . DIRECTORY_SEPARATOR . 'extends'
                    . DIRECTORY_SEPARATOR . 'module'
                    . DIRECTORY_SEPARATOR . 'Weline_Frontend'
                    . DIRECTORY_SEPARATOR . $extendName;
                if (!is_dir($dir)) {
                    continue;
                }
                try {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($iterator as $file) {
                        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                            continue;
                        }
                        $sourceFile = $file->getPathname();
                        $rows[] = [
                            'source_module' => (string)$moduleName,
                            'source_file' => $sourceFile,
                            'file_path' => $extendName . '/' . $file->getFilename(),
                            'class_name' => $this->classFromFile($sourceFile),
                        ];
                    }
                } catch (\Throwable) {
                }
            }
        }

        return $rows;
    }

    /**
     * @param array{context: array<int, HeadContextProviderInterface>, policy: array<int, HeadPolicyProviderInterface>} $providers
     * @param array<string, bool> $seen
     * @param array<string, mixed> $extension
     */
    private function registerExtension(array &$providers, array &$seen, array $extension): void
    {
        $extendName = $this->extensionName($extension);
        if (!in_array($extendName, ['HeadContextProvider', 'HeadPolicyProvider'], true)) {
            return;
        }

        $class = $this->extensionClass($extension);
        if ($class === '' || isset($seen[$class]) || !class_exists($class)) {
            return;
        }

        try {
            $instance = $this->objectManager->getInstance($class);
        } catch (\Throwable) {
            return;
        }

        if ($extendName === 'HeadContextProvider' && $instance instanceof HeadContextProviderInterface) {
            $providers['context'][] = $instance;
            $seen[$class] = true;
        }
        if ($extendName === 'HeadPolicyProvider' && $instance instanceof HeadPolicyProviderInterface) {
            $providers['policy'][] = $instance;
            $seen[$class] = true;
        }
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionName(array $extension): string
    {
        $extendName = trim((string)($extension['extend_name'] ?? ''));
        if ($extendName !== '') {
            return $extendName;
        }

        $filePath = str_replace('\\', '/', (string)($extension['file_path'] ?? ''));
        return trim((string)(explode('/', $filePath)[0] ?? ''));
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionClass(array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $class = trim((string)($extension[$key] ?? ''));
            if ($class !== '') {
                return $class;
            }
        }

        return $this->classFromFile((string)($extension['source_file'] ?? ''));
    }

    private function classFromFile(string $sourceFile): string
    {
        if ($sourceFile === '' || !is_file($sourceFile) || !is_readable($sourceFile)) {
            return '';
        }

        $content = file_get_contents($sourceFile, false, null, 0, 4096);
        if ($content === false) {
            return '';
        }

        $namespace = '';
        $className = '';
        if (preg_match('/^\s*namespace\s+([^;]+)\s*;/m', $content, $matches) === 1) {
            $namespace = trim($matches[1]);
        }
        if (preg_match('/^\s*(?:abstract\s+)?(?:final\s+)?class\s+(\w+)/m', $content, $matches) === 1) {
            $className = trim($matches[1]);
        }

        return $namespace !== '' && $className !== '' ? $namespace . '\\' . $className : '';
    }
}
