<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Api\WarmupProviderInterface;
use Weline\Cdn\WarmupProvider\FpcExtraDeclaredUrls;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * 预热Provider扫描器：内置 ∪ Extends。
 *
 * @package Weline_Cdn
 */
class WarmupProviderScanner
{
    /** @var list<class-string<WarmupProviderInterface>> */
    private const BUILTIN = [
        FpcExtraDeclaredUrls::class,
    ];

    private Scan $fileScanner;
    private ObjectManager $objectManager;
    private ?array $cachedProviders = null;
    private ?int $cachedExtendsMtime = null;

    public function __construct(
        Scan $fileScanner,
        ObjectManager $objectManager
    ) {
        $this->fileScanner = $fileScanner;
        $this->objectManager = $objectManager;
    }

    /**
     * @return list<class-string>
     */
    public function scanProviders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->cachedProviders !== null) {
            $currentMtime = ExtendsData::getRegistryFileMtime();
            if ($currentMtime === $this->cachedExtendsMtime) {
                return $this->cachedProviders;
            }
        }

        $providers = [];
        foreach (self::BUILTIN as $className) {
            if (class_exists($className) && $this->isWarmupProvider($className)) {
                $providers[$className] = $className;
            }
        }

        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Cdn', $forceReload);
            $modules = Env::getInstance()->getModuleList();

            foreach ($extendedBy as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    if (($extension['is_sticker_extension'] ?? false) === true) {
                        continue;
                    }
                    $relativePath = $extension['relative_path'] ?? '';
                    if (!str_starts_with($relativePath, 'extends/module/Weline_Cdn/')) {
                        continue;
                    }
                    $sourceFile = $extension['source_file'] ?? '';
                    if ($sourceFile === '' || !file_exists($sourceFile)) {
                        continue;
                    }
                    $sourceModuleInfo = $modules[$sourceModule] ?? null;
                    if (empty($sourceModuleInfo) || !($sourceModuleInfo['status'] ?? false)) {
                        continue;
                    }
                    $className = $this->getClassNameFromFile($sourceFile, (string)$sourceModule, $sourceModuleInfo);
                    if ($className && class_exists($className) && $this->isWarmupProvider($className)) {
                        $providers[$className] = $className;
                    }
                }
            }
        } catch (\Exception $e) {
            w_log_error('扫描WarmupProvider失败: ' . $e->getMessage());
        }

        $list = array_values($providers);
        $this->cachedProviders = $list;
        $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();

        return $list;
    }

    /**
     * @return list<array{url?:string}|string>
     */
    public function collectUrls(bool $forceReload = false): array
    {
        $urls = [];
        foreach ($this->scanProviders($forceReload) as $className) {
            try {
                $providerUrls = call_user_func([$className, 'execute']);
                if (is_array($providerUrls)) {
                    $urls = array_merge($urls, $providerUrls);
                }
            } catch (\Exception $e) {
                w_log_error("执行WarmupProvider失败: {$className}, 错误: " . $e->getMessage());
            }
        }

        return $urls;
    }

    private function isWarmupProvider(string $className): bool
    {
        try {
            $reflection = new \ReflectionClass($className);

            return $reflection->implementsInterface(WarmupProviderInterface::class);
        } catch (\Throwable) {
            return false;
        }
    }

    private function getClassNameFromFile(string $filePath, string $moduleName, array $module): ?string
    {
        $fileName = basename($filePath, '.php');

        if (isset($module['namespace_path'])) {
            return $module['namespace_path'] . '\\Extends\\Module\\Weline_Cdn\\' . $fileName;
        }

        $relativePath = str_replace(BP . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace(['.php', '\\'], ['', '\\'], $relativePath);
        $relativePath = str_replace('/', '\\', $relativePath);
        $relativePath = preg_replace('/extends[\\\\\/]module[\\\\\/]Weline_Cdn/', 'Extends\\Module\\Weline_Cdn', $relativePath);
        $relativePath = str_replace('extends\\Weline_Cdn', 'Extends\\Module\\Weline_Cdn', $relativePath);

        return $relativePath;
    }
}
