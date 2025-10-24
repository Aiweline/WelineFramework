<?php
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Model;

use Weline\Framework\System\File\Data\File;
use Weline\Framework\System\File\Scanner;

class Reader extends \Weline\Framework\System\ModuleFileReader
{
    private ?array $models;

    public function __construct(
        Scanner $scanner,
                $path = 'Model' . DS
    )
    {
        parent::__construct($scanner, $path);
        $this->scanner = $scanner;
        $this->path    = $path;
    }

    /**
     * @DESC          # 读取模型 开发模式没有缓存，非开发模式读取缓存
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/6 21:34
     * 参数区：
     * @return array
     */
    public function read(): array
    {
        if (empty($this->models)) {
            # 模型读取回调（排除非模型文件）
            $callback     = function ($files) {
                foreach ($files as $vendor => $vendor_modules) {
                    foreach ($vendor_modules as $module => $models) {
                        foreach ($models as $model_path => $model_files) {
                            /**@var File $model_file */
                            foreach ($model_files as $key => $model_file) {
                                $model_class = $model_file->getNamespace() . '\\' . $model_file->getFilename();
                                $model_class = str_replace('\\\\', '\\', $model_class);
                                $model       = new \ReflectionClass($model_class);
                                if (!$model->getParentClass() || ($model->getParentClass()->getName() !== \Weline\Framework\Database\Model::class)) {
                                    unset($model_files[$key]);
                                }
                            }
                            if (empty($model_files)) {
                                unset($models[$model_path]);
                            } else {
                                $models[$model_path] = $model_files;
                            }
                        }
                        if (empty($models)) {
                            unset($vendor_modules[$module]);
                        } else {
                            $vendor_modules[$module] = $models;
                        }
                    }
                    if (empty($vendor_modules)) {
                        unset($files[$vendor]);
                    } else {
                        $files[$vendor] = $vendor_modules;
                    }
                }
                return $files;
            };
            $this->models = $this->getFileList($callback);
        }

        return $this->models;
    }
}
