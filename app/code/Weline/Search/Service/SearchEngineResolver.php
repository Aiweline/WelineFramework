<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Api\SearchEngineInterface;
use Weline\Search\Engine\MysqlSearchEngine;
use Weline\Search\Engine\RedisSearchEngine;
use Weline\Search\Engine\WlsMemorySearchEngine;
use Weline\Search\Engine\ElasticsearchSearchEngine;
use Weline\SystemConfig\Api\ConfigReader;

class SearchEngineResolver
{
    public const DEFAULT_ENGINE = 'mysql';
    public const CONFIG_PATH = 'search.engine.default';

    /** @var array<string, SearchEngineInterface>|null */
    private ?array $engines = null;

    private ?string $testingCode = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ?ConfigReader $configReader = null,
    ) {
    }

    public static function forTesting(?string $code = null): self
    {
        $resolver = new self(ObjectManager::getInstance());
        $resolver->testingCode = $code;

        return $resolver;
    }

    public function resolve(?string $code = null): SearchEngineInterface
    {
        $code = trim((string)($code ?: $this->configuredCode()));
        if ($code === '') {
            $code = self::DEFAULT_ENGINE;
        }

        $engines = $this->all();
        $engine = $engines[$code] ?? null;
        if (!$engine instanceof SearchEngineInterface || !$engine->isAvailable()) {
            if ($code !== self::DEFAULT_ENGINE) {
                $fallback = $engines[self::DEFAULT_ENGINE] ?? null;
                if ($fallback instanceof SearchEngineInterface && $fallback->isAvailable()) {
                    return $fallback;
                }
            }
            throw new \RuntimeException('Search engine not configured: ' . $code);
        }

        return $engine;
    }

    public function configuredCode(): string
    {
        if ($this->testingCode !== null) {
            return $this->testingCode;
        }
        try {
            if ($this->configReader instanceof ConfigReader) {
                $code = trim((string)$this->configReader->get(
                    self::CONFIG_PATH,
                    'Weline_Search',
                    ConfigReader::area_FRONTEND,
                    '',
                ));
                if ($code !== '') {
                    return $code;
                }
            }
        } catch (\Throwable) {
        }

        return self::DEFAULT_ENGINE;
    }

    /**
     * @return array<string, SearchEngineInterface>
     */
    public function all(bool $forceReload = false): array
    {
        if (!$forceReload && $this->engines !== null) {
            return $this->engines;
        }

        $map = [
            'mysql' => $this->objectManager->getInstance(MysqlSearchEngine::class),
            'wls_memory' => $this->objectManager->getInstance(WlsMemorySearchEngine::class),
            'redis' => $this->objectManager->getInstance(RedisSearchEngine::class),
            'elasticsearch' => $this->objectManager->getInstance(ElasticsearchSearchEngine::class),
        ];

        try {
            foreach (ExtendsData::getExtendedBy('Weline_Search', $forceReload) as $extensions) {
                foreach ($extensions as $extension) {
                    if ($this->extensionName($extension) !== 'Engine') {
                        continue;
                    }
                    $class = $this->extensionClass($extension);
                    if ($class === '' || !class_exists($class)) {
                        continue;
                    }
                    $instance = $this->objectManager->getInstance($class);
                    if ($instance instanceof SearchEngineInterface) {
                        $map[$instance->code()] = $instance;
                    }
                }
            }
        } catch (\Throwable) {
        }

        $this->engines = $map;

        return $this->engines;
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
        $segments = explode('/', $filePath);

        return trim((string)($segments[0] ?? ''));
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
