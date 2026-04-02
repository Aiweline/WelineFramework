<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 虚拟主题解析器：将 VirtualTheme 伪装成 WelineTheme 对象
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\VirtualTheme;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 虚拟主题解析器
 * 负责将 PageBuilder 的 VirtualTheme 伪装成 WelineTheme 对象，供主题系统使用
 */
class VirtualThemeResolver
{
    private const VIRTUAL_THEME_BASE_PATH = 'generated/pagebuilder/virtual_themes';

    public function __construct(
        private readonly VirtualTheme $virtualThemeModel
    ) {
    }

    /**
     * 解析虚拟主题并返回伪装的 WelineTheme 对象
     *
     * @param int $virtualThemeId 虚拟主题ID
     * @return WelineTheme|null 伪装的 WelineTheme 对象，不存在时返回 null
     */
    public function resolveVirtualTheme(int $virtualThemeId): ?WelineTheme
    {
        if ($virtualThemeId <= 0) {
            return null;
        }

        /** @var VirtualTheme $virtualTheme */
        $virtualTheme = clone $this->virtualThemeModel;
        $virtualTheme->clearData()->clearQuery();
        $virtualTheme->load($virtualThemeId);

        if (!$virtualTheme->getId()) {
            return null;
        }

        return $this->createWelineThemeAdapter($virtualTheme);
    }

    /**
     * 获取虚拟主题的根路径
     *
     * @param int $virtualThemeId 虚拟主题ID
     * @return string 虚拟主题根路径（绝对路径）
     */
    public function getVirtualThemePath(int $virtualThemeId): string
    {
        return BP . self::VIRTUAL_THEME_BASE_PATH . DS . $virtualThemeId . DS;
    }

    /**
     * 获取虚拟主题的相对路径（相对于项目根目录）
     *
     * @param int $virtualThemeId 虚拟主题ID
     * @return string 虚拟主题相对路径
     */
    public function getVirtualThemeRelativePath(int $virtualThemeId): string
    {
        return self::VIRTUAL_THEME_BASE_PATH . '/' . $virtualThemeId . '/';
    }

    /**
     * 检查虚拟主题是否存在
     *
     * @param int $virtualThemeId 虚拟主题ID
     * @return bool 存在返回 true，否则返回 false
     */
    public function isVirtualThemeExists(int $virtualThemeId): bool
    {
        if ($virtualThemeId <= 0) {
            return false;
        }

        /** @var VirtualTheme $virtualTheme */
        $virtualTheme = clone $this->virtualThemeModel;
        $virtualTheme->clearData()->clearQuery();
        $virtualTheme->load($virtualThemeId);

        return $virtualTheme->getId() > 0;
    }

    /**
     * 创建 WelineTheme 适配器对象
     * 将 VirtualTheme 伪装成 WelineTheme，设置必要的属性
     *
     * @param VirtualTheme $virtualTheme 虚拟主题对象
     * @return WelineTheme 伪装的 WelineTheme 对象
     */
    private function createWelineThemeAdapter(VirtualTheme $virtualTheme): WelineTheme
    {
        $virtualThemeId = $virtualTheme->getId();
        $virtualPath = $this->getVirtualThemeRelativePath($virtualThemeId);

        /** @var WelineTheme $welineTheme */
        $welineTheme = ObjectManager::make(WelineTheme::class);
        $welineTheme->clearData()->clearQuery();

        // 伪装成 WelineTheme 对象
        $welineTheme->setId(0); // 虚拟主题不占用 WelineTheme 的 ID
        $welineTheme->setName('Virtual Theme #' . $virtualThemeId);
        $welineTheme->setPath($virtualPath);
        $welineTheme->setModuleName('GuoLaiRen_PageBuilder');
        $welineTheme->setIsActive(false); // 虚拟主题不直接激活

        // 传递虚拟主题的配置
        $config = $virtualTheme->getConfig();
        $config['virtual_theme_id'] = $virtualThemeId;
        $config['is_virtual'] = true;
        $config['virtual_theme_name'] = $virtualTheme->getName();
        $config['session_id'] = $virtualTheme->getSessionId();
        $config['website_id'] = $virtualTheme->getWebsiteId();
        $welineTheme->setConfig($config);

        // 设置原始路径（用于某些场景需要获取原始路径）
        $welineTheme->setData('origin_path', $virtualPath);
        $welineTheme->setData('virtual_theme_id', $virtualThemeId);
        $welineTheme->setData('is_virtual_theme', true);

        return $welineTheme;
    }

    /**
     * 确保虚拟主题目录结构存在
     *
     * @param int $virtualThemeId 虚拟主题ID
     * @return bool 成功返回 true，失败返回 false
     */
    public function ensureVirtualThemeDirectories(int $virtualThemeId): bool
    {
        if ($virtualThemeId <= 0) {
            return false;
        }

        $basePath = $this->getVirtualThemePath($virtualThemeId);
        $directories = [
            $basePath,
            $basePath . 'frontend' . DS,
            $basePath . 'frontend' . DS . 'layouts' . DS,
            $basePath . 'frontend' . DS . 'components' . DS,
            $basePath . 'backend' . DS,
            $basePath . 'backend' . DS . 'layouts' . DS,
            $basePath . 'backend' . DS . 'components' . DS,
        ];

        foreach ($directories as $dir) {
            if (!\is_dir($dir)) {
                if (!\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 获取虚拟主题的布局目录路径
     *
     * @param int $virtualThemeId 虚拟主题ID
     * @param string $area 区域（frontend/backend）
     * @return string 布局目录路径
     */
    public function getLayoutsPath(int $virtualThemeId, string $area = 'frontend'): string
    {
        return $this->getVirtualThemePath($virtualThemeId) . $area . DS . 'layouts' . DS;
    }

    /**
     * 获取虚拟主题的组件目录路径
     *
     * @param int $virtualThemeId 虚拟主题ID
     * @param string $area 区域（frontend/backend）
     * @return string 组件目录路径
     */
    public function getComponentsPath(int $virtualThemeId, string $area = 'frontend'): string
    {
        return $this->getVirtualThemePath($virtualThemeId) . $area . DS . 'components' . DS;
    }
}
