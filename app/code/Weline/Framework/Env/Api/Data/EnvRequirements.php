<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Env\Api\Data;

/**
 * 环境需求数据对象
 * 
 * @DESC 封装合并后的环境需求，包含 PHP 版本、扩展、函数、items 等
 */
class EnvRequirements
{
    /** @var string|null PHP 版本约束，如 ^8.1 */
    private ?string $phpVersion = null;

    /** @var array 需要的扩展列表 */
    private array $extensions = [];

    /** @var array 需要的函数列表（须未被 disable_functions） */
    private array $functions = [];

    /** @var array 复杂依赖项列表 */
    private array $items = [];

    /** @var array 推荐的扩展列表（非必需，安装失败不阻断）每项为 ['name'=>string, 'platform'=>string, 'reason'=>string] */
    private array $recommendedExtensions = [];

    /** @var array 推荐的函数列表 */
    private array $recommendedFunctions = [];

    /** @var array 推荐的复杂依赖项列表 */
    private array $recommendedItems = [];

    /** @var array 数据来源记录 [source => data] */
    private array $sources = [];

    /**
     * 设置 PHP 版本约束
     */
    public function setPhpVersion(?string $version): self
    {
        $this->phpVersion = $version;
        return $this;
    }

    /**
     * 获取 PHP 版本约束
     */
    public function getPhpVersion(): ?string
    {
        return $this->phpVersion;
    }

    /**
     * 添加扩展（不区分大小写去重）
     */
    public function addExtension(string $extension): self
    {
        // 扩展名不区分大小写，统一转小写比较
        $extensionLower = strtolower($extension);
        foreach ($this->extensions as $existing) {
            if (strtolower($existing) === $extensionLower) {
                return $this; // 已存在，跳过
            }
        }
        $this->extensions[] = $extension;
        return $this;
    }

    /**
     * 批量添加扩展
     */
    public function addExtensions(array $extensions): self
    {
        foreach ($extensions as $extension) {
            $this->addExtension($extension);
        }
        return $this;
    }

    /**
     * 获取扩展列表
     */
    public function getExtensions(): array
    {
        return $this->extensions;
    }

    /**
     * 添加函数
     */
    public function addFunction(string $function): self
    {
        if (!in_array($function, $this->functions, true)) {
            $this->functions[] = $function;
        }
        return $this;
    }

    /**
     * 批量添加函数
     */
    public function addFunctions(array $functions): self
    {
        foreach ($functions as $function) {
            $this->addFunction($function);
        }
        return $this;
    }

    /**
     * 获取函数列表
     */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    /**
     * 添加复杂依赖项（基于 name + module 去重）
     *
     * @param array $item 包含 name, description, script_linux, script_windows, module 等
     */
    public function addItem(array $item): self
    {
        $itemName = $item['name'] ?? '';
        $itemModule = $item['module'] ?? '';
        $itemKey = $itemModule . '::' . $itemName;

        // 检查是否已存在相同的 item
        foreach ($this->items as $existing) {
            $existingName = $existing['name'] ?? '';
            $existingModule = $existing['module'] ?? '';
            $existingKey = $existingModule . '::' . $existingName;
            
            if ($existingKey === $itemKey) {
                return $this; // 已存在，跳过
            }
        }

        $this->items[] = $item;
        return $this;
    }

    /**
     * 批量添加复杂依赖项
     */
    public function addItems(array $items): self
    {
        foreach ($items as $item) {
            $this->addItem($item);
        }
        return $this;
    }

    /**
     * 获取复杂依赖项列表
     */
    public function getItems(): array
    {
        return $this->items;
    }

    // ==================== 推荐项（recommended）====================

    /**
     * 添加推荐扩展（支持字符串或带平台条件的数组）
     *
     * @param string|array $extension 字符串=跨平台扩展名，数组=['name'=>string, 'platform'=>string, 'reason'=>string]
     */
    public function addRecommendedExtension(string|array $extension): self
    {
        // 统一为数组格式
        $item = \is_string($extension)
            ? ['name' => $extension, 'platform' => 'all', 'reason' => '']
            : [
                'name'     => $extension['name'] ?? '',
                'platform' => $extension['platform'] ?? 'all',
                'reason'   => $extension['reason'] ?? '',
            ];

        if (empty($item['name'])) {
            return $this;
        }

        $nameLower = \strtolower($item['name']);

        // 如果已在必需列表中，不重复添加
        foreach ($this->extensions as $existing) {
            if (\strtolower($existing) === $nameLower) {
                return $this;
            }
        }
        // 去重
        foreach ($this->recommendedExtensions as $existing) {
            if (\strtolower($existing['name']) === $nameLower) {
                return $this;
            }
        }

        $this->recommendedExtensions[] = $item;
        return $this;
    }

    /**
     * 批量添加推荐扩展
     */
    public function addRecommendedExtensions(array $extensions): self
    {
        foreach ($extensions as $extension) {
            $this->addRecommendedExtension($extension);
        }
        return $this;
    }

    /**
     * 获取推荐扩展列表（已归一化为数组格式）
     *
     * @return array[] 每项为 ['name'=>string, 'platform'=>string, 'reason'=>string]
     */
    public function getRecommendedExtensions(): array
    {
        return $this->recommendedExtensions;
    }

    /**
     * 检查平台条件是否匹配当前运行环境
     *
     * @param string $platform 'all'|'unix'|'linux'|'windows'|'darwin'
     */
    public static function matchesPlatform(string $platform): bool
    {
        $platform = \strtolower($platform);
        if ($platform === 'all' || $platform === '') {
            return true;
        }
        $family = \strtolower(PHP_OS_FAMILY); // 'windows', 'linux', 'darwin', 'bsd' 等
        return match ($platform) {
            'windows' => $family === 'windows',
            'linux'   => $family === 'linux',
            'darwin'  => $family === 'darwin',
            'unix'    => $family !== 'windows', // Linux + macOS + BSD 等
            default   => true,
        };
    }

    /**
     * 添加推荐函数
     */
    public function addRecommendedFunction(string $function): self
    {
        if (!in_array($function, $this->recommendedFunctions, true)
            && !in_array($function, $this->functions, true)) {
            $this->recommendedFunctions[] = $function;
        }
        return $this;
    }

    /**
     * 批量添加推荐函数
     */
    public function addRecommendedFunctions(array $functions): self
    {
        foreach ($functions as $function) {
            $this->addRecommendedFunction($function);
        }
        return $this;
    }

    /**
     * 获取推荐函数列表
     */
    public function getRecommendedFunctions(): array
    {
        return $this->recommendedFunctions;
    }

    /**
     * 添加推荐复杂依赖项
     */
    public function addRecommendedItem(array $item): self
    {
        $itemName = $item['name'] ?? '';
        $itemModule = $item['module'] ?? '';
        $itemKey = $itemModule . '::' . $itemName;

        foreach ($this->recommendedItems as $existing) {
            $existingKey = ($existing['module'] ?? '') . '::' . ($existing['name'] ?? '');
            if ($existingKey === $itemKey) {
                return $this;
            }
        }

        $this->recommendedItems[] = $item;
        return $this;
    }

    /**
     * 批量添加推荐复杂依赖项
     */
    public function addRecommendedItems(array $items): self
    {
        foreach ($items as $item) {
            $this->addRecommendedItem($item);
        }
        return $this;
    }

    /**
     * 获取推荐复杂依赖项列表
     */
    public function getRecommendedItems(): array
    {
        return $this->recommendedItems;
    }

    // ==================== 数据来源 ====================

    /**
     * 记录数据来源
     */
    public function addSource(string $source, array $data): self
    {
        $this->sources[$source] = $data;
        return $this;
    }

    /**
     * 获取数据来源
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * 合并另一个 EnvRequirements
     */
    public function merge(EnvRequirements $other): self
    {
        // 合并 PHP 版本（取更严格的约束，或第一个非空值）
        if ($other->getPhpVersion() !== null && $this->phpVersion === null) {
            $this->phpVersion = $other->getPhpVersion();
        }

        // 合并扩展
        $this->addExtensions($other->getExtensions());

        // 合并函数
        $this->addFunctions($other->getFunctions());

        // 合并 items
        $this->addItems($other->getItems());

        // 合并推荐项
        $this->addRecommendedExtensions($other->getRecommendedExtensions());
        $this->addRecommendedFunctions($other->getRecommendedFunctions());
        $this->addRecommendedItems($other->getRecommendedItems());

        // 合并来源
        foreach ($other->getSources() as $source => $data) {
            $this->sources[$source] = $data;
        }

        return $this;
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'php' => $this->phpVersion,
            'extensions' => $this->extensions,
            'functions' => $this->functions,
            'items' => $this->items,
            'recommended_extensions' => $this->recommendedExtensions,
            'recommended_functions' => $this->recommendedFunctions,
            'recommended_items' => $this->recommendedItems,
            'sources' => array_keys($this->sources),
        ];
    }
}
