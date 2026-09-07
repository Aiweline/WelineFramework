<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\Customer\Interface\SocialLoginProviderInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

/**
 * Discover SocialLoginProviderInterface implementations via extends registry.
 */
final class SocialLoginProviderScanner
{
    private const RELATIVE_PREFIX = 'extends/module/Weline_Customer/SocialLoginProvider/';

    /** @var list<array<string, mixed>>|null */
    private ?array $cachedDefinitions = null;

    private ?int $cachedExtendsMtime = null;

    public function __construct(
        private readonly ObjectManager $objectManager
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function scanProviderDefinitions(bool $forceReload = false): array
    {
        $currentMtime = ExtendsData::getRegistryFileMtime();
        if (!$forceReload && $this->cachedDefinitions !== null && $currentMtime === $this->cachedExtendsMtime) {
            return $this->cachedDefinitions;
        }

        $definitions = [];
        $extendedBy = ExtendsData::getExtendedBy('Weline_Customer', $forceReload);
        $modules = Env::getInstance()->getModuleList();

        foreach ($extendedBy as $sourceModule => $extensions) {
            $sourceModuleInfo = $modules[$sourceModule] ?? null;
            if (empty($sourceModuleInfo) || !($sourceModuleInfo['status'] ?? false)) {
                continue;
            }

            foreach ((array) $extensions as $extension) {
                if (($extension['is_sticker_extension'] ?? false) === true) {
                    continue;
                }

                $relativePath = (string) ($extension['relative_path'] ?? '');
                if (!str_starts_with($relativePath, self::RELATIVE_PREFIX)) {
                    continue;
                }

                $sourceFile = (string) ($extension['source_file'] ?? '');
                if ($sourceFile === '' || !is_file($sourceFile)) {
                    continue;
                }

                $className = $this->getClassNameFromFile($sourceFile);
                if ($className === '') {
                    continue;
                }

                require_once $sourceFile;
                if (!class_exists($className)) {
                    continue;
                }

                try {
                    $reflection = new \ReflectionClass($className);
                    if (!$reflection->implementsInterface(SocialLoginProviderInterface::class)) {
                        continue;
                    }
                    if ($reflection->isAbstract() || $reflection->isInterface()) {
                        continue;
                    }
                } catch (\Throwable $throwable) {
                    w_log_error('检查社媒登录提供商接口失败: ' . $className . ', 错误: ' . $throwable->getMessage());
                    continue;
                }

                $definitions[] = [
                    'class_name' => $className,
                    'source_module' => (string) $sourceModule,
                    'source_file' => $sourceFile,
                    'relative_path' => $relativePath,
                    'file_path' => (string) ($extension['file_path'] ?? $sourceFile),
                ];
            }
        }

        $this->cachedDefinitions = $definitions;
        $this->cachedExtendsMtime = $currentMtime;

        return $definitions;
    }

    /**
     * @return list<SocialLoginProviderInterface>
     */
    public function getProviderInstances(bool $forceReload = false): array
    {
        $providers = [];
        foreach ($this->scanProviderDefinitions($forceReload) as $definition) {
            $className = (string) ($definition['class_name'] ?? '');
            if ($className === '') {
                continue;
            }

            try {
                $provider = $this->objectManager->getInstance($className);
                if ($provider instanceof SocialLoginProviderInterface) {
                    $providers[] = $provider;
                }
            } catch (\Throwable $throwable) {
                w_log_error('实例化社媒登录提供商失败: ' . $className . ', 错误: ' . $throwable->getMessage());
            }
        }

        return $providers;
    }

    private function getClassNameFromFile(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return '';
        }

        if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
            return '';
        }

        if (!preg_match('/(?:final\s+)?class\s+(\w+)/', $content, $classMatch)) {
            return '';
        }

        return trim($namespaceMatch[1]) . '\\' . trim($classMatch[1]);
    }
}
