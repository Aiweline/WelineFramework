<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Resource\Config;

use Weline\Framework\System\File\Data\File;

abstract class ResourceReader extends \Weline\Framework\Module\File\Reader implements ResourceReaderInterface
{
    public string $file;
    public string $path;
    public string $source_type;

    public function __construct(string $path, string $file, $source_type, array $data = [])
    {
        $this->file = $file;
        $this->path = $path;
        $this->source_type = $source_type;
        parent::__construct($path, $data);
    }

    public function getSourceType(): string
    {
        return $this->source_type;
    }

    public function getFileList(null|\Closure $callback = null): array
    {
        if (empty($callback)) {
            $callback = function ($data) {
                $need_data = [];
                foreach ($data as $module => $module_data) {
                    foreach ($module_data as $dir => $dir_data) {
                        foreach ($dir_data as $file_index => $dir_file) {
                            /**@var File $dir_file */
                            if ($this->file === $dir_file->getBaseName() || empty($this->file)) {
                                // 跳过编译后的文件（base 目录下的文件是编译产物，不应该被扫描）
                                $file_path = $dir_file->getOrigin();
                                $relate_path = $dir_file->getRelate();
                                $normalized_path = str_replace(['/', '\\'], DS, $file_path);
                                $normalized_relate = str_replace(['/', '\\'], DS, $relate_path);
                                
                                // 检查是否是 base 目录下的文件（编译产物，跳过）
                                if (strpos($normalized_path, DS . 'base' . DS) !== false ||
                                    strpos($normalized_path, DS . 'base' . DS . 'weline.modules.js') !== false ||
                                    strpos($normalized_relate, '/base/') !== false ||
                                    strpos($normalized_relate, '\\base\\') !== false ||
                                    strpos($normalized_relate, '/base/weline.modules.js') !== false ||
                                    strpos($normalized_relate, '\\base\\weline.modules.js') !== false) {
                                    // 跳过编译后的文件
                                    continue;
                                }
                                
                                // 从文件路径中确定 area（严格按路径结构判断：view/statics/{frontend|backend}/weline.modules.js）
                                $area = null; // 不设置默认值，必须从路径中确定
                                
                                // 方法1：从绝对路径结构中提取 area（最精确的方式）
                                // 路径格式：.../view/statics/{frontend|backend}/weline.modules.js
                                $path_parts = explode(DS, $normalized_path);
                                $statics_index = array_search('statics', $path_parts);
                                if ($statics_index !== false && isset($path_parts[$statics_index + 1])) {
                                    $potential_area = strtolower(trim($path_parts[$statics_index + 1]));
                                    if (in_array($potential_area, ['frontend', 'backend'])) {
                                        $area = $potential_area;
                                    }
                                }
                                
                                // 方法2：如果从绝对路径无法确定，检查相对路径
                                if ($area === null) {
                                    $relate_parts = explode(DS, $normalized_relate);
                                    $relate_statics_index = array_search('statics', $relate_parts);
                                    if ($relate_statics_index !== false && isset($relate_parts[$relate_statics_index + 1])) {
                                        $relate_potential_area = strtolower(trim($relate_parts[$relate_statics_index + 1]));
                                        if (in_array($relate_potential_area, ['frontend', 'backend'])) {
                                            $area = $relate_potential_area;
                                        }
                                    }
                                }
                                
                                // 方法3：如果仍然无法确定，检查路径中是否包含 backend（最后备用方式）
                                if ($area === null) {
                                    // 检查路径中是否明确包含 backend 目录
                                    if (strpos($normalized_path, DS . 'backend' . DS) !== false || 
                                        strpos($normalized_path, DS . 'backend' . DS . 'weline.modules.js') !== false ||
                                        strpos($relate_path, '/backend/') !== false ||
                                        strpos($relate_path, '\\backend\\') !== false ||
                                        strpos($relate_path, '/backend/weline.modules.js') !== false ||
                                        strpos($relate_path, '\\backend\\weline.modules.js') !== false) {
                                        $area = 'backend';
                                    } else {
                                        // 如果路径中没有明确的 backend，默认为 frontend
                                        $area = 'frontend';
                                    }
                                }
                                
                                // 验证：如果文件路径中包含 backend，但 area 被识别为 frontend，强制设置为 backend
                                if ($area === 'frontend' && (
                                    strpos($normalized_path, DS . 'backend' . DS) !== false ||
                                    strpos($relate_path, '/backend/') !== false ||
                                    strpos($relate_path, '\\backend\\') !== false
                                )) {
                                    // 路径中包含 backend，但被识别为 frontend，强制设置为 backend
                                    $area = 'backend';
                                }
                                
                                $need_data[] = [
                                    'module' => $module,
                                    'dir' => $dir,
                                    'area' => $area,
                                    'file' => $dir_file->getRelate(),
                                    'origin' => $dir_file->getOrigin(),
                                ];
                            }
                        }
                    }
                }
                return $need_data;
            };
        }
        return parent::getFileList($callback);
    }
}
