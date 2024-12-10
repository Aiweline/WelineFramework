<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Config\Reader;

use Weline\Framework\App\Env;
use Weline\Framework\Resource\Config\ResourceReader;
use Weline\Framework\System\File\Data\File;
use Weline\Framework\View\Template;

class Less extends ResourceReader
{
    private array $config_resources = [];

    public function __construct(string $path = 'view', string $file = '', $source_type = 'less', array $data = [])
    {
        parent::__construct($path, $file, $source_type, $data);
    }

    public function getTheme()
    {
        return Env::getInstance()->getConfig('theme');
    }

    public function getFileList(\Closure $callback = null): array
    {
        $callback = function ($data) {
            $need_data = [];
            foreach ($data as $vendor => $module_data) {
                foreach ($module_data as $name => $dir_data) {
                    foreach ($dir_data as $dir => $dir_files) {
                        /**@var File $dir_file */
                        foreach ($dir_files as $dir_file) {
                            if ($this->source_type === $dir_file->getExtension()) {
                                $area = 'frontend';
                                if (is_int(strpos($dir_file->getNamespace(), 'backend'))) {
                                    $area = 'backend';
                                }
                                $need_data[] = [
                                    'module' => $vendor . '_' . $name,
                                    'dir'    => $dir,
                                    'area'   => $area,
                                    'file'   => $dir_file->getRelate(),
                                    'origin' => $dir_file->getOrigin(),
                                ];
                            }
                        }
                    }
                }
            }
            return $need_data;
        };
        return parent::getFileList($callback);
    }

    public function getResourceFiles(): array
    {
        # less文件
        $less_files = $this->getFileList();

        foreach ($less_files as $require_config_key => $less_file) {
            $area = $less_file['area'];
            if (!isset($this->config_resources[$area])) {
                $this->config_resources[$area] = '';
            }
            $content = file_get_contents($less_file['origin']);
            # 替换模块的路径
            foreach (Env::getInstance()->getModuleList() as $module_name => $module_info) {
                $related_file_path = str_replace(trim($module_info['base_path'], DS) . DS . 'view', '/', $less_file['dir']);
                $related_file_path = str_replace('//', '/', $related_file_path);
                $related_file_path = str_replace('//', '/', $related_file_path);
                $file_path         = $this->fetchFile($module_name . '::' . $related_file_path);
                $file_path         = str_replace('//', '/', $file_path);
                $file_path         = str_replace('//', '/', $file_path);
                $content           = str_replace($module_name, $file_path, $content);
            }
            $this->config_resources[$area] .= $content;
        }
        return $this->config_resources;
    }

    protected Template $template;

    private function getTemplate()
    {
        if (!isset($this->template, $_)) {
            /**@var Template $template */
            $this->template = Template::getInstance()->init();
        }
        return $this->template;
    }

    public function fetchFile(string $source)
    {
        return $this->getTemplate()->fetchTagSourceFile('statics', $source);
    }
}
