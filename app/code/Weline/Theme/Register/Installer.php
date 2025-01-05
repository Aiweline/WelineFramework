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
     * Installer 初始函数...
     *
     * @param WelineTheme $welineTheme
     * @param Printing $printing
     */
    public function __construct(
        WelineTheme $welineTheme,
        Printing    $printing
    )
    {
        $this->welineTheme = $welineTheme;
        $this->printing = $printing;
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
        // 检测是否有父主题
        $parent_id = 0;
        if (isset($param['parent']) && $parent = $param['parent']) {
            $parent = $this->welineTheme->load('name', $parent);
            if (!$parent->getId()) {
                throw new Exception(__('父主题：%1 不存在！', $parent));
            }
            $parent_id = $parent->getId();
        }

        // 检查主题是否已经安装
        $this->welineTheme->clearData();
        $this->welineTheme->load('name', $param['name']);

        $action_string = __('安装');
        if ($this->welineTheme->getId()) {
            if ($this->welineTheme->getPath() !== $param['path'] . DS) {
                $this->printing->setup($param['name'] . __(' 主题更新...'));
                $action_string = __('更新');
            } else {
                return '';
            }
        }
        // 处理主题路径
        $theme_path = str_replace(Env::path_CODE_DESIGN, '', $param['path']);
        // 主题数据
        $this->welineTheme
            ->setName($param['name'])
            ->setModuleName($module_name)
            ->setParentId($parent_id)
            ->setIsActive(false)
            ->setPath($theme_path);
        // 开始主题注册 save 方法自带事务
        try {
            if ($this->welineTheme->getId()) {
                // 更新
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
        } catch (\Exception $exception) {
            $this->printing->error($param['name'] . __("主题{$action_string}异常!"));
            $this->printing->success($exception->getMessage());
            throw  $exception;
        }

        return '';
    }
}
