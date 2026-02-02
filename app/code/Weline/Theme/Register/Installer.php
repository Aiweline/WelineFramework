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
        // 参数检查
        if (!isset($param['name']) || !isset($param['path'])) {
            throw new ConsoleException('注册文件参数params必须包含：name和path。 样例：["name"=>"default主题"，"path"=>__DIR__]');
        }
        
        // 处理主题路径（提前处理，后续查找和比较都用相同的格式）
        $theme_path = str_replace(Env::path_CODE_DESIGN, '', $param['path']);
        
        // 检测是否有父主题（使用新实例避免数据残留）
        $parent_id = 0;
        if (isset($param['parent']) && $parent = $param['parent']) {
            /** @var WelineTheme $parentTheme */
            $parentTheme = clone $this->welineTheme;
            $parentTheme->clearData()->clearQuery();
            $parentTheme->load('name', $parent);
            if (!$parentTheme->getId()) {
                throw new Exception(__('父主题：%{1} 不存在！', $parent));
            }
            $parent_id = $parentTheme->getId();
        }

        // 使用 module_name 来查找现有主题（module_name 是唯一标识符）
        // 这样可以避免多个主题使用相同 name 时的冲突
        $this->welineTheme->clearData()->clearQuery();
        $this->welineTheme->load(WelineTheme::fields_MODULE_NAME, $module_name);

        $action_string = __('安装');
        if ($this->welineTheme->getId()) {
            // 主题已存在，检查是否需要更新
            $existingPath = str_replace(Env::path_CODE_DESIGN, '', rtrim($this->welineTheme->getPath(), DS . '/'));
            $newPath = rtrim($theme_path, DS . '/');
            
            if ($existingPath === $newPath && $this->welineTheme->getName() === $param['name']) {
                // 路径和名称都相同，无需更新
                return '';
            }
            $this->printing->setup($param['name'] . __(' 主题更新...'));
            $action_string = __('更新');
        }
        
        // 主题数据
        $this->welineTheme
            ->setName($param['name'])
            ->setModuleName($module_name)
            ->setParentId($parent_id)
            ->setPath($theme_path);
            
        // 开始主题注册 save 方法自带事务
        try {
            if ($this->welineTheme->getId()) {
                // 更新 - 不改变激活状态
                $this->welineTheme->save();
            } else {
                // 新安装
                $this->welineTheme->clearQuery();
                $res = $this->welineTheme->setId(0)
                    ->setIsActive(true)
                    ->save();
                if (!$res) {
                    throw new Exception(__('主题注册失败！'));
                }
            }
            $this->printing->success($param['name'] . __("主题{$action_string}完成!"));
            
            // 触发布局收集（强制刷新缓存）
            try {
                $this->layoutDataService->collectLayouts(true);
            } catch (\Throwable $e) {
                // 布局收集失败不影响主题注册
                $this->printing->warning(__('布局收集失败: ') . $e->getMessage());
            }
        } catch (\Exception $exception) {
            $this->printing->error($param['name'] . __("主题{$action_string}异常!"));
            $this->printing->error($exception->getMessage());
            throw $exception;
        }

        return '';
    }
}
