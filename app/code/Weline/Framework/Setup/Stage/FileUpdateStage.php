<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\System\File\Io\File;

/**
 * 文件更新阶段
 * 
 * 职责：管理模块文件、函数文件等的批量更新，确保一次性写入
 * 
 * @package Weline\Framework\Setup\Stage
 */
class FileUpdateStage extends AbstractStage
{
    /**
     * @var array 待更新的文件数据 [文件路径 => 文件内容]
     */
    private array $fileData = [];
    
    /**
     * @var array 原始文件内容备份 [文件路径 => 文件内容]
     */
    private array $originalFileData = [];
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        // 文件更新阶段不需要依赖注入，使用空构造函数
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'file_update';
    }
    
    /**
     * 添加待更新的文件
     * 
     * @param string $filePath 文件路径
     * @param string $content 文件内容
     * @return void
     */
    public function addFile(string $filePath, string $content): void
    {
        $this->fileData[$filePath] = $content;
    }
    
    /**
     * 添加待更新的模块文件
     * 
     * @param array $modules 模块数据
     * @return void
     */
    public function addModulesFile(array $modules): void
    {
        $filePath = Env::path_MODULES_FILE;
        $content = '<?php return ' . var_export($modules, true) . ';';
        $this->addFile($filePath, $content);
    }
    
    /**
     * 添加待更新的函数文件
     * 
     * @param string $content 函数文件内容
     * @return void
     */
    public function addFunctionsFile(string $content): void
    {
        $filePath = Env::path_FUNCTIONS_FILE;
        $this->addFile($filePath, $content);
    }
    
    /**
     * @inheritDoc
     */
    public function prepare(array $context = []): void
    {
        // 如果已经准备过，跳过（避免重复准备）
        if ($this->prepared) {
            return;
        }
        
        // 备份原始文件内容
        foreach ($this->fileData as $filePath => $content) {
            if (is_file($filePath)) {
                $this->originalFileData[$filePath] = file_get_contents($filePath);
            } else {
                $this->originalFileData[$filePath] = null; // 标记文件不存在
            }
        }
        
        $this->prepared = true;
        $this->clearErrors();
    }
    
    /**
     * @inheritDoc
     */
    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }
        
        // 验证文件路径是否有效
        foreach (array_keys($this->fileData) as $filePath) {
            $dir = dirname($filePath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                $this->addError(__('无法创建目录：%{1}', [$dir]));
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        if (!$this->prepared) {
            throw new Exception(__('阶段 %{1} 尚未准备，无法提交', [$this->getName()]));
        }
        
        if ($this->committed) {
            // 已经提交过，跳过
            return;
        }
        
        $failedFiles = [];
        
        // 一次性写入所有文件
        foreach ($this->fileData as $filePath => $content) {
            try {
                // 确保目录存在
                $dir = dirname($filePath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                
                // 写入文件
                $file = new File();
                $file->open($filePath, $file::mode_w_add);
                $file->write($content);
                $file->close();
            } catch (\Exception $e) {
                $failedFiles[] = $filePath;
                $this->addError(__('写入文件 %{1} 失败：%{2}', [$filePath, $e->getMessage()]));
            }
        }
        
        if (!empty($failedFiles)) {
            throw new Exception(__('部分文件写入失败：%{1}', [implode(', ', $failedFiles)]));
        }
        
        $this->committed = true;
        $this->clearErrors();
    }
    
    /**
     * @inheritDoc
     */
    public function rollback(): void
    {
        if (!$this->prepared) {
            return;
        }
        
        // 恢复原始文件内容
        foreach ($this->originalFileData as $filePath => $originalContent) {
            try {
                if ($originalContent === null) {
                    // 文件原本不存在，删除它
                    if (is_file($filePath)) {
                        @unlink($filePath);
                    }
                } else {
                    // 恢复原始内容
                    $file = new File();
                    $file->open($filePath, $file::mode_w_add);
                    $file->write($originalContent);
                    $file->close();
                }
            } catch (\Exception $e) {
                // 回滚失败，记录错误但不抛出异常
                $this->addError(__('回滚文件 %{1} 失败：%{2}', [$filePath, $e->getMessage()]));
            }
        }
        
        $this->prepared = false;
        $this->committed = false;
    }
}
