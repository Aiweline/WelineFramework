<?php
declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiModel;
use Weline\Framework\System\File\Scan;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Aiweline\Ai\AiVendorModel;

/**
 * AI供应商模型扫描器服务
 * 
 * 功能：
 * - 自动扫描AiVendorModel类
 * - 注册新发现的供应商模型到数据库
 * - 更新现有模型信息
 * - 支持多模块扫描
 */
class VendorModelScanner
{
    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var Scan
     */
    private Scan $fileScanner;

    /**
     * 构造函数
     * 
     * @param AiModel $aiModel
     * @param Scan $fileScanner
     */
    public function __construct(
        AiModel $aiModel,
        Scan $fileScanner
    ) {
        $this->aiModel = $aiModel;
        $this->fileScanner = $fileScanner;
    }

    /**
     * 扫描所有供应商模型
     * 
     * @return array
     * @throws Exception
     */
    public function scanAllVendorModels(): array
    {
        $scannedModels = [];
        
        // 1. 扫描 Aiweline 模块的供应商模型
        $aiwelineDir = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . 'Aiweline';
        if (is_dir($aiwelineDir)) {
            $vendorModelFiles = $this->fileScanner->globFile($aiwelineDir . '/**/Model/**/*.php');
            foreach ($vendorModelFiles as $modelFile) {
                try {
                    $model = $this->loadVendorModel($modelFile);
                    if ($model) {
                        $this->registerVendorModel($model);
                        $scannedModels[] = $model;
                    }
                } catch (\Exception $e) {
                    error_log("加载供应商模型失败: {$modelFile}, 错误: " . $e->getMessage());
                }
            }
        }
        
        // 2. 扫描其他模块的供应商模型
        $otherModulesModels = $this->scanOtherModulesVendorModels();
        foreach ($otherModulesModels as $model) {
            try {
                $this->registerVendorModel($model);
                $scannedModels[] = $model;
            } catch (\Exception $e) {
                error_log("注册其他模块供应商模型失败: " . $e->getMessage());
            }
        }

        return $scannedModels;
    }
    
    /**
     * 扫描其他模块的供应商模型
     * 
     * @return array
     */
    private function scanOtherModulesVendorModels(): array
    {
        $models = [];
        
        try {
            // 获取所有已安装的模块
            $modules = Env::getInstance()->getModuleList();
            
            foreach ($modules as $moduleName => $module) {
                // 跳过 Weline_Ai 模块本身
                if ($moduleName === 'Weline_Ai') {
                    continue;
                }
                
                // 获取模块基础路径
                $basePath = $module['base_path'] ?? '';
                if (empty($basePath) || !($module['status'] ?? false)) {
                    continue;
                }
                
                // 扫描所有PHP文件，寻找AiVendorModel子类
                $phpFiles = $this->fileScanner->globFile($basePath . '/**/*.php');
                
                foreach ($phpFiles as $phpFile) {
                    try {
                        $model = $this->loadVendorModel($phpFile, $moduleName, $module);
                        if ($model) {
                            $models[] = $model;
                        }
                    } catch (\Exception $e) {
                        // 忽略非供应商模型文件
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("扫描其他模块供应商模型失败: " . $e->getMessage());
        }
        
        return $models;
    }

    /**
     * 加载供应商模型
     * 
     * @param string $modelFile
     * @param string|null $moduleName
     * @param array|null $module
     * @return AiVendorModel|null
     */
    private function loadVendorModel(string $modelFile, ?string $moduleName = null, ?array $module = null): ?AiVendorModel
    {
        // 先加载文件
        if (!file_exists($modelFile)) {
            return null;
        }
        
        require_once $modelFile;
        
        // 从文件路径推断类名
        $className = $this->getClassNameFromFile($modelFile, $moduleName, $module);
        
        if (!$className) {
            return null;
        }

        // 检查类是否存在
        if (!class_exists($className, false)) {
            return null;
        }

        // 创建实例
        $instance = new $className();
        
        // 验证是否是AiVendorModel子类
        if (!$instance instanceof AiVendorModel) {
            return null;
        }

        return $instance;
    }

    /**
     * 从文件路径获取类名
     * 
     * @param string $filePath
     * @param string|null $moduleName
     * @param array|null $module
     * @return string|null
     */
    private function getClassNameFromFile(string $filePath, ?string $moduleName = null, ?array $module = null): ?string
    {
        // 如果是其他模块的文件
        if ($moduleName && $module && $moduleName !== 'Weline_Ai') {
            // 从模块信息获取命名空间
            $namespacePath = $module['namespace_path'] ?? '';
            if (empty($namespacePath)) {
                return null;
            }
            
            // 构建完整类名
            $relativePath = str_replace($module['base_path'], '', $filePath);
            $relativePath = trim($relativePath, '/\\');
            $relativePath = str_replace(['/', '\\'], '\\', $relativePath);
            $relativePath = str_replace('.php', '', $relativePath);
            
            return "\\{$namespacePath}\\{$relativePath}";
        }

        // 默认处理 Aiweline 模块
        $relativePath = str_replace(BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace(['/', '\\'], '\\', $relativePath);
        $relativePath = str_replace('.php', '', $relativePath);
        
        return "\\{$relativePath}";
    }

    /**
     * 注册供应商模型
     * 
     * @param AiVendorModel $vendorModel
     * @return bool
     */
    private function registerVendorModel(AiVendorModel $vendorModel): bool
    {
        $vendor = $vendorModel->getVendor();
        $product = $vendorModel->getProduct();
        $model = $vendorModel->getModel();
        
        // 检查是否已存在
        $existingModel = $this->aiModel->reset()
            ->where('vendor', $vendor)
            ->where('product', $product)
            ->where('model', $model)
            ->find()
            ->fetch();

        if ($existingModel->getId()) {
            // 更新现有模型
            return $this->updateExistingVendorModel($existingModel, $vendorModel);
        } else {
            // 创建新模型
            return $this->createNewVendorModel($vendorModel);
        }
    }

    /**
     * 创建新供应商模型
     * 
     * @param AiVendorModel $vendorModel
     * @return bool
     */
    private function createNewVendorModel(AiVendorModel $vendorModel): bool
    {
        $vendor = $vendorModel->getVendor();
        $product = $vendorModel->getProduct();
        $model = $vendorModel->getModel();
        $modelCode = "{$vendor}-{$product}-{$model}";
        
        $data = [
            'vendor' => $vendor,
            'product' => $product,
            'model' => $model,
            'class' => get_class($vendorModel),
            'default_api_key' => $vendorModel->getApiKey(),
            'default_api_url' => $vendorModel->getApiUrl(),
            'supplier' => $vendor,
            'model_code' => $modelCode,
            'name' => $modelCode, // 使用model_code作为name
            'version' => '1.0',
            'config' => json_encode([]),
            'capabilities' => json_encode([]),
            'cost_per_token' => '0.000000',
            'status' => 'active',
            'is_active' => 0,
            'is_default' => 0,
            'is_copy' => 0,
            'origin_model_id' => null,
        ];

        $newModel = new AiModel();
        $newModel->setData($data)->save();
        
        return true;
    }

    /**
     * 更新现有供应商模型
     * 
     * @param AiModel $existingModel
     * @param AiVendorModel $vendorModel
     * @return bool
     */
    private function updateExistingVendorModel(AiModel $existingModel, AiVendorModel $vendorModel): bool
    {
        // 只更新允许更新的字段
        $updateData = [
            'class' => get_class($vendorModel),
            'default_api_key' => $vendorModel->getApiKey(),
            'default_api_url' => $vendorModel->getApiUrl(),
        ];

        foreach ($updateData as $field => $value) {
            $existingModel->setData($field, $value);
        }

        $existingModel->save();
        
        return true;
    }

    /**
     * 获取供应商模型统计信息
     * 
     * @return array
     */
    public function getVendorModelStats(): array
    {
        $totalCount = $this->aiModel->reset()
            ->select()
            ->fetch()
            ->count();

        $activeCount = $this->aiModel->reset()
            ->where('is_active', 1)
            ->select()
            ->fetch()
            ->count();

        return [
            'total' => $totalCount,
            'active' => $activeCount,
            'inactive' => $totalCount - $activeCount
        ];
    }
}
