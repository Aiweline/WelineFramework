<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Register;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Register\RegisterInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutDataService;

class Installer implements RegisterInterface
{
    /**
     * 延后安装的主题队列（按 module_name 去重）
     *
     * @var array<string, array>
     */
    private static array $themeInstallQueue = [];

    /**
     * 本进程内已安装的主题模块标记，避免重复安装
     *
     * @var array<string, bool>
     */
    private static array $installedThemeModules = [];

    /**
     * @var WelineTheme
     */
    private WelineTheme $welineTheme;

    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * @var LayoutDataService
     */
    private LayoutDataService $layoutDataService;

    /**
     * Installer 初始函数...
     *
     * @param WelineTheme $welineTheme
     * @param Printing $printing
     * @param LayoutDataService $layoutDataService
     */
    public function __construct(
        WelineTheme       $welineTheme,
        Printing          $printing,
        LayoutDataService $layoutDataService
    )
    {
        $this->welineTheme = $welineTheme;
        $this->printing = $printing;
        $this->layoutDataService = $layoutDataService;
    }

    /**
     * @DESC         |注册主题
     *
     * 参数区：
     *
     * @param string $type
     * @param string $module_name
     * @param array|string $param
     * @param string $version
     * @param string $description
     *
     * @return string
     * @throws \ReflectionException
     * @throws \Weline\Framework\Exception\Core
     */
    public function register(string $type, string $module_name, array|string $param, string $version = '', string $description = ''): string
    {
        self::$themeInstallQueue[$module_name] = [$type, $module_name, $param, $version, $description];
        $this->installQueuedThemesByParentDependency();
        return '';
    }

    /**
     * 按 parent 依赖安装队列中的主题：
     * 1. 先按队列内 parent 关系排序（父主题在前）
     * 2. 仅安装“父主题已存在（库中或本轮已先安装）”的主题
     */
    private function installQueuedThemesByParentDependency(): void
    {
        if (empty(self::$themeInstallQueue)) {
            return;
        }
        do {
            $progress = false;
            $sorted = $this->sortThemeQueueByParent(array_values(self::$themeInstallQueue));
            foreach ($sorted as $args) {
                $module_name = (string)($args[1] ?? '');
                if ($module_name === '') {
                    continue;
                }
                if (isset(self::$installedThemeModules[$module_name])) {
                    unset(self::$themeInstallQueue[$module_name]);
                    continue;
                }
                if (!$this->canInstallThemeNow($args)) {
                    continue;
                }
                [$type, $module, $param, $version, $description] = $args;
                $this->installTheme($type, $module, $param, $version, $description);
                self::$installedThemeModules[$module_name] = true;
                unset(self::$themeInstallQueue[$module_name]);
                $progress = true;
            }
        } while ($progress && !empty(self::$themeInstallQueue));
    }

    /**
     * 判断主题当前是否可安装：无 parent，或 parent 主题已存在于数据库。
     */
    private function canInstallThemeNow(array $args): bool
    {
        $param = $args[2] ?? [];
        if (!is_array($param)) {
            return false;
        }
        $parent = trim((string)($param['parent'] ?? ''));
        if ($parent === '') {
            return true;
        }
        return $this->resolveParentId($parent) > 0;
    }

    /**
     * 队列内按 parent 拓扑排序：父主题先于子主题。
     */
    private function sortThemeQueueByParent(array $themes): array
    {
        $count = count($themes);
        if ($count <= 1) {
            return $themes;
        }

        $nameToIndex = [];
        for ($i = 0; $i < $count; $i++) {
            $param = $themes[$i][2] ?? [];
            if (!is_array($param)) {
                continue;
            }
            $name = trim((string)($param['name'] ?? ''));
            if ($name !== '' && !isset($nameToIndex[$name])) {
                $nameToIndex[$name] = $i;
            }
        }
        if (isset($nameToIndex['Default 默认主题']) && !isset($nameToIndex['default'])) {
            $nameToIndex['default'] = $nameToIndex['Default 默认主题'];
        }

        $dependencies = array_fill(0, $count, []);
        $successors = array_fill(0, $count, []);
        $inDegree = array_fill(0, $count, 0);

        for ($i = 0; $i < $count; $i++) {
            $param = $themes[$i][2] ?? [];
            if (!is_array($param)) {
                continue;
            }
            $parent = trim((string)($param['parent'] ?? ''));
            if ($parent === '') {
                continue;
            }
            $provider = $nameToIndex[$parent] ?? null;
            if ($provider === null || $provider === $i) {
                continue;
            }
            $dependencies[$i][] = $provider;
        }

        for ($i = 0; $i < $count; $i++) {
            $inDegree[$i] = count($dependencies[$i]);
            foreach ($dependencies[$i] as $dep) {
                $successors[$dep][] = $i;
            }
        }

        $queue = [];
        for ($i = 0; $i < $count; $i++) {
            if ($inDegree[$i] === 0) {
                $queue[] = $i;
            }
        }

        $ordered = [];
        while (!empty($queue)) {
            $idx = array_shift($queue);
            $ordered[] = $themes[$idx];
            foreach ($successors[$idx] as $child) {
                $inDegree[$child]--;
                if ($inDegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        if (count($ordered) !== $count) {
            return $themes;
        }
        return $ordered;
    }

    /**
     * 解析父主题 ID。
     * 兼容 parent=default 与框架默认主题名 "Default 默认主题" 的映射。
     */
    private function resolveParentId(string $parent): int
    {
        /** @var WelineTheme $parentTheme */
        $parentTheme = clone $this->welineTheme;
        $parentTheme->clearData()->clearQuery();
        $parentTheme->load('name', $parent);
        if ($parentTheme->getId()) {
            return (int)$parentTheme->getId();
        }
        if ($parent === 'default') {
            $parentTheme->clearData()->clearQuery();
            $parentTheme->load('name', 'Default 默认主题');
            if ($parentTheme->getId()) {
                return (int)$parentTheme->getId();
            }
        }
        return 0;
    }

    /**
     * 安装单个主题。
     */
    private function installTheme(string $type, string $module_name, array|string $param, string $version = '', string $description = ''): void
    {
        if (!isset($param['name']) || !isset($param['path'])) {
            throw new ConsoleException('注册文件参数params必须包含：name和path。 样例：["name"=>"default主题"，"path"=>__DIR__]');
        }

        $theme_path = str_replace(Env::path_CODE_DESIGN, '', $param['path']);
        $parent_id = 0;
        $parent = trim((string)($param['parent'] ?? ''));
        if ($parent !== '') {
            $parent_id = $this->resolveParentId($parent);
            if ($parent_id === 0) {
                throw new Exception(__('父主题：%{1} 不存在！', $parent));
            }
        }

        $this->welineTheme->clearData()->clearQuery();
        $this->welineTheme->load(WelineTheme::fields_MODULE_NAME, $module_name);

        $action_string = __('安装');
        if ($this->welineTheme->getId()) {
            $existingPath = str_replace(Env::path_CODE_DESIGN, '', rtrim($this->welineTheme->getPath(), DS . '/'));
            $newPath = rtrim($theme_path, DS . '/');
            if ($existingPath === $newPath && $this->welineTheme->getName() === $param['name']) {
                return;
            }
            $this->printing->setup($param['name'] . __(' 主题更新...'));
            $action_string = __('更新');
        }

        $this->welineTheme
            ->setName($param['name'])
            ->setModuleName($module_name)
            ->setParentId($parent_id)
            ->setPath($theme_path);

        try {
            if ($this->welineTheme->getId()) {
                $this->welineTheme->save();
            } else {
                $this->welineTheme->clearQuery();
                $res = $this->welineTheme->setId(0)
                    ->setIsActive(true)
                    ->save();
                if (!$res) {
                    throw new Exception(__('主题注册失败！'));
                }
            }
            $this->printing->success($param['name'] . __("主题{$action_string}完成!"));
            try {
                $this->layoutDataService->collectLayouts(true);
            } catch (\Throwable $e) {
                $this->printing->warning(__('布局收集失败: ') . $e->getMessage());
            }
        } catch (\Exception $exception) {
            $this->printing->error($param['name'] . __("主题{$action_string}异常!"));
            $this->printing->error($exception->getMessage());
            throw $exception;
        }
    }
}
