<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/13
 * 时间：16:50
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\DeveloperWorkspace\Controller;

use Weline\DeveloperWorkspace\Helper\Data;
use Weline\DeveloperWorkspace\Model\Document as DocumentModel;

class Document extends BaseController
{
    /**
     * 获取文档详情（API）
     * 如果是自动导入的文档，从文件系统实时读取内容
     * 如果是用户创建的文档，从数据库读取内容
     */
    public function index()
    {
        try {
            $documentId = (int)($this->request->getParam('id') ?? 0);
            if (!$documentId) {
                return $this->fetchJson(['error' => __('文档ID不能为空')], 400);
            }
            
            $document = $this->getDocumentModel()->load($documentId);
            if (!$document->getId()) {
                return $this->fetchJson(['error' => __('文档不存在')], 404);
            }
            
            $content = '';
            $isAutoImported = (int)($document->getData('is_auto_imported') ?? 0) === 1;
            
            if ($isAutoImported) {
                // 自动导入的文档：从文件系统实时读取内容
                $moduleName = $document->getModuleName() ?? '';
                $filePath = $document->getFilePath() ?? '';
                
                if ($moduleName && $filePath) {
                    // 重置错误信息
                    $this->lastError = null;
                    $result = $this->loadDocumentFromFile($moduleName, $filePath);
                    if ($result === false) {
                        // 获取详细错误信息
                        $errorMsg = $this->getDocumentLoadError($moduleName, $filePath);
                        return $this->fetchJson(['error' => $errorMsg], 500);
                    }
                    $content = $result;
                } else {
                    $errorMsg = __('文档路径信息不完整');
                    if (empty($moduleName)) {
                        $errorMsg .= '：模块名为空';
                    }
                    if (empty($filePath)) {
                        $errorMsg .= '：文件路径为空';
                    }
                    return $this->fetchJson(['error' => $errorMsg], 500);
                }
            } else {
                // 用户创建的文档：从数据库读取内容
                $content = $document->getDecodeContent() ?? '';
                // 清理HTML注释（数据库中的内容也可能包含HTML注释）
                $content = $this->cleanHtmlComments($content);
            }
            
            return $this->fetchJson([
                'id' => $document->getId(),
                'title' => $document->getTitle() ?? '',
                'summary' => $document->getData('summary') ?? '',
                'content' => $content,
                'category_id' => $document->getCategoryId(),
                'module_name' => $document->getModuleName() ?? '',
                'file_name' => $document->getFileName() ?? '',
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * 从文件系统加载文档内容
     * 
     * @param string $moduleName 模块名
     * @param string $relativePath 相对于模块根目录的路径
     * @return string|false 文档内容，失败返回false
     */
    private function loadDocumentFromFile(string $moduleName, string $relativePath): string|false
    {
        try {
            // 获取模块信息
            $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
            if (!isset($modules[$moduleName])) {
                $this->lastError = "模块 '{$moduleName}' 不存在";
                return false;
            }
            
            $module = $modules[$moduleName];
            $moduleBasePath = $module['base_path'] ?? '';
            if (empty($moduleBasePath)) {
                $this->lastError = "模块 '{$moduleName}' 的 base_path 为空";
                return false;
            }
            
            // 构建完整文件路径：模块根目录/相对路径
            $moduleBasePath = rtrim($moduleBasePath, '/\\');
            $fullPath = $moduleBasePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            
            // 安全检查：确保文件在模块目录内
            $realModulePath = realpath($moduleBasePath);
            $realFullPath = realpath($fullPath);
            if ($realModulePath === false) {
                $this->lastError = "模块目录不存在：{$moduleBasePath}";
                return false;
            }
            if ($realFullPath === false) {
                $this->lastError = "文档文件不存在：{$fullPath}";
                return false;
            }
            
            // 确保文件路径在模块目录内（防止路径遍历攻击）
            if (strpos($realFullPath, $realModulePath) !== 0) {
                $this->lastError = "文件路径不在模块目录内：{$realFullPath}";
                return false;
            }
            
            // 检查文件扩展名，只允许.md文件
            $fileExtension = strtolower(pathinfo($realFullPath, PATHINFO_EXTENSION));
            if ($fileExtension !== 'md') {
                $this->lastError = "只允许读取.md格式的文档文件，当前文件扩展名：{$fileExtension}";
                return false;
            }
            
            // 读取文件内容
            if (!is_file($realFullPath)) {
                $this->lastError = "不是有效的文件：{$realFullPath}";
                return false;
            }
            if (!is_readable($realFullPath)) {
                $this->lastError = "文件不可读：{$realFullPath}";
                return false;
            }
            
            // 读取并转换为UTF-8编码
            $content = file_get_contents($realFullPath);
            if ($content === false) {
                $this->lastError = "无法读取文件内容：{$realFullPath}";
                return false;
            }
            
            // 如果文件为空，直接返回
            if (empty($content)) {
                return '';
            }
            
            // 检查是否已经是有效的UTF-8编码
            $contentToClean = '';
            if (mb_check_encoding($content, 'UTF-8')) {
                $contentToClean = $content;
            } else {
                // 检测文件编码并转换
                $detectEncodings = ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1', 'Windows-1252', 'ASCII'];
                $encoding = mb_detect_encoding($content, $detectEncodings, true);
                
                if ($encoding === false || empty($encoding)) {
                    $encoding = 'GBK';
                }
                
                $encoding = (string)$encoding;
                
                // 转换为UTF-8
                $utf8Content = mb_convert_encoding($content, 'UTF-8', $encoding);
                
                // 验证转换结果
                if ($utf8Content === false || !mb_check_encoding($utf8Content ?? '', 'UTF-8')) {
                    // 尝试其他编码
                    $fallbackEncodings = ['GBK', 'GB2312', 'BIG5', 'ISO-8859-1', 'Windows-1252', 'ASCII'];
                    $contentToClean = $content; // 默认使用原始内容
                    foreach ($fallbackEncodings as $enc) {
                        if ($enc === $encoding) {
                            continue;
                        }
                        $testContent = @mb_convert_encoding($content, 'UTF-8', $enc);
                        if ($testContent !== false && mb_check_encoding($testContent, 'UTF-8')) {
                            $contentToClean = $testContent;
                            break;
                        }
                    }
                    
                    // 如果还是失败，使用最后的备选方案
                    if ($contentToClean === $content) {
                        $utf8Content = mb_convert_encoding($content, 'UTF-8', 'UTF-8//IGNORE');
                        if ($utf8Content === false) {
                            $utf8Content = mb_convert_encoding($content, 'UTF-8', 'GBK//IGNORE');
                        }
                        if ($utf8Content !== false) {
                            $contentToClean = $utf8Content;
                        }
                    }
                } else {
                    $contentToClean = $utf8Content;
                }
            }
            
            // 清理HTML注释
            $cleanedContent = $this->cleanHtmlComments($contentToClean);
            
            return $cleanedContent;
        } catch (\Exception $e) {
            $this->lastError = "读取文档时发生异常：" . $e->getMessage();
            return false;
        }
    }
    
    /**
     * 存储最后一次错误信息
     */
    private ?string $lastError = null;
    
    /**
     * 获取文档加载错误信息
     * 
     * @param string $moduleName 模块名
     * @param string $filePath 文件路径
     * @return string 错误信息
     */
    private function getDocumentLoadError(string $moduleName, string $filePath): string
    {
        if ($this->lastError) {
            return __('无法读取文档文件') . '：' . $this->lastError;
        }
        return __('无法读取文档文件') . "（模块：{$moduleName}，路径：{$filePath}）";
    }
    
    /**
     * 清理HTML注释
     * 
     * @param string|null $content 原始内容
     * @return string 清理后的内容
     */
    private function cleanHtmlComments(?string $content): string
    {
        if (empty($content)) {
            return '';
        }
        
        // 清理HTML注释（包括单行和多行注释）
        // 匹配格式：<!-- ... --> 或 <!-- ... \n ... -->
        $cleanedContent = preg_replace('/<!--[\s\S]*?-->/', '', $content ?? '');
        
        // preg_replace 可能返回 null，需要处理
        if ($cleanedContent === null) {
            $cleanedContent = $content ?? '';
        }
        
        // 清理后可能产生多余的空行，移除连续的空行（保留单个空行）
        $cleanedContent = preg_replace('/\n{3,}/', "\n\n", $cleanedContent ?? '');
        
        // preg_replace 可能返回 null，需要处理
        if ($cleanedContent === null) {
            $cleanedContent = '';
        }
        
        // 清理首尾空白
        $cleanedContent = trim($cleanedContent ?? '');
        
        return $cleanedContent;
    }
    
    private function getDocumentModel(): DocumentModel
    {
        return $this->_objectManager::getInstance(DocumentModel::class);
    }
}

