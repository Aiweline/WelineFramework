<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Service;

use Weline\Ai\Interface\AdapterModelBindingInterface;
use Weline\Ai\Interface\ScenarioAdapterInterface;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Framework\System\File\Scan;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;

/**
 * 场景适配器扫描器服务
 * 
 * 功能：
 * - 自动扫描适配器目录
 * - 注册新发现的适配器
 * - 更新适配器信息
 * - 验证适配器有效性
 */
class AdapterScanner
{
    /**
     * 适配器目录
     */
    private const ADAPTER_DIR = 'app/code/Weline/Ai/Adapter/';
    
    /**
     * 适配器文件后缀
     */
    private const ADAPTER_SUFFIX = 'Adapter.php';

    /**
     * @var AiScenarioAdapter
     */
    private AiScenarioAdapter $scenarioAdapter;

    /**
     * @var Scan
     */
    private Scan $fileScanner;

    /**
     * 已注册的适配器缓存
     * 
     * @var array
     */
    private array $registeredAdapters = [];

    /**
     * 构造函数
     * 
     * @param AiScenarioAdapter $scenarioAdapter
     * @param Scan $fileScanner
     */
    public function __construct(
        AiScenarioAdapter $scenarioAdapter,
        Scan $fileScanner
    ) {
        $this->scenarioAdapter = $scenarioAdapter;
        $this->fileScanner = $fileScanner;
    }

    /**
     * 扫描所有适配器
     * 
     * @return array
     * @throws Exception
     */
    public function scanAllAdapters(): array
    {
        $scannedAdapters = [];
        
        // 1. 首先扫描 Weline_Ai 模块的适配器
        $adapterDir = BP . DIRECTORY_SEPARATOR . self::ADAPTER_DIR;
        if (is_dir($adapterDir)) {
            $adapterFiles = $this->fileScanner->globFile($adapterDir . '/*' . self::ADAPTER_SUFFIX);
            foreach ($adapterFiles as $adapterFile) {
                try {
                    $adapter = $this->loadAdapter($adapterFile);
                    if ($adapter) {
                        $this->registerAdapter($adapter, $adapterFile);
                        $scannedAdapters[] = $adapter;
                    }
                } catch (\Exception $e) {
                    w_log_error("加载适配器失败: {$adapterFile}, 错误: " . $e->getMessage());
                }
            }
        }
        
        // 2. 扫描其他模块的 extends/Weline_Ai/Adapter 目录
        $otherModulesAdapters = $this->scanOtherModulesAdapters();
        foreach ($otherModulesAdapters as $adapterInfo) {
            try {
                $adapter = $adapterInfo['adapter'];
                $adapterFile = $adapterInfo['file'];
                $this->registerAdapter($adapter, $adapterFile);
                $scannedAdapters[] = $adapter;
            } catch (\Exception $e) {
                w_log_error("注册其他模块适配器失败: " . $e->getMessage());
            }
        }

        return $scannedAdapters;
    }
    
    /**
     * 扫描其他模块的适配器
     * 从 ExtendsData 获取所有扩展了 Weline_Ai 的模块，然后扫描这些模块的 extends/module/Weline_Ai/Adapter 目录
     * 
     * @return array
     */
    private function scanOtherModulesAdapters(): array
    {
        $adapters = [];
        
        try {
            // 从 ExtendsData 获取扩展了 Weline_Ai 的模块列表
            $extendedBy = ExtendsData::getExtendedBy('Weline_Ai');
            
            if (empty($extendedBy)) {
                return $adapters;
            }
            
            // 获取模块列表以获取模块路径
            $env = Env::getInstance();
            $moduleList = $env->getModuleList();
            
            // 遍历所有扩展了 Weline_Ai 的源模块
            foreach ($extendedBy as $sourceModule => $extensions) {
                // 获取源模块的路径
                if (!isset($moduleList[$sourceModule])) {
                    continue;
                }
                
                $moduleBasePath = $moduleList[$sourceModule]['base_path'] ?? '';
                if (empty($moduleBasePath)) {
                    continue;
                }
                
                // 构建适配器目录路径：extends/module/Weline_Ai/Adapter
                $adapterDir = rtrim($moduleBasePath, '/\\') . DIRECTORY_SEPARATOR 
                    . 'extends' . DIRECTORY_SEPARATOR 
                    . 'module' . DIRECTORY_SEPARATOR 
                    . 'Weline_Ai' . DIRECTORY_SEPARATOR 
                    . 'Adapter';
                
                // 检查目录是否存在
                if (!is_dir($adapterDir)) {
                    continue;
                }
                
                // 扫描该目录下的所有适配器文件
                $adapterFiles = $this->fileScanner->globFile($adapterDir . DIRECTORY_SEPARATOR . '*' . self::ADAPTER_SUFFIX);
                
                foreach ($adapterFiles as $adapterFile) {
                    try {
                        $adapter = $this->loadAdapter($adapterFile, $sourceModule);
                        if ($adapter) {
                            $adapters[] = [
                                'adapter' => $adapter,
                                'file' => $adapterFile
                            ];
                        }
                    } catch (\Exception $e) {
                        w_log_error("加载其他模块适配器失败: {$adapterFile}, 错误: " . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            w_log_error("从 ExtendsData 扫描其他模块适配器失败: " . $e->getMessage());
        }
        
        return $adapters;
    }

    /**
     * 加载适配器
     * 
     * @param string $adapterFile
     * @param string|null $moduleName
     * @param array|null $module
     * @return ScenarioAdapterInterface|null
     */
    private function loadAdapter(string $adapterFile, ?string $moduleName = null, ?array $module = null): ?ScenarioAdapterInterface
    {
        // 先加载文件
        if (!file_exists($adapterFile)) {
            w_log_error("文件不存在: {$adapterFile}");
            return null;
        }
        
        require_once $adapterFile;
        
        // 从文件路径推断类名
        $className = $this->getClassNameFromFile($adapterFile, $moduleName, $module);
        
        if (!$className) {
            return null;
        }

        // 检查类是否存在
        if (!class_exists($className, false)) {
            return null;
        }

        // 创建实例
        $instance = new $className();
        
        // 验证是否实现了接口
        if (!$instance instanceof ScenarioAdapterInterface) {
            throw new Exception("适配器类 {$className} 必须实现 ScenarioAdapterInterface 接口");
        }

        return $instance;
    }

    /**
     * 从文件路径获取类名
     * 
     * @param string $filePath
     * @param string|null $moduleName 源模块名（用于 extends 目录下的文件）
     * @param array|null $module
     * @return string|null
     */
    private function getClassNameFromFile(string $filePath, ?string $moduleName = null, ?array $module = null): ?string
    {
        // 如果是 extends 目录下的文件（来自其他模块的扩展），从文件内容中解析命名空间
        if (str_contains($filePath, DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR) 
            || str_contains($filePath, '/extends/')) {
            // 读取文件内容，解析命名空间和类名
            $content = file_get_contents($filePath);
            if ($content === false) {
                return null;
            }
            
            // 解析命名空间
            if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
                $namespace = trim($namespaceMatches[1]);
                
                // 解析类名
                if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                    $className = $classMatches[1];
                    return "\\{$namespace}\\{$className}";
                }
            }
            
            return null;
        }

        $fileName = basename($filePath, '.php');
        
        // 验证文件名格式（Weline_Ai 模块的适配器必须以 Adapter 结尾）
        if (!str_ends_with($fileName, 'Adapter')) {
            return null;
        }

        // 默认 Weline_Ai 模块的适配器
        return "\\Weline\\Ai\\Adapter\\{$fileName}";
    }

    /**
     * 注册适配器
     * 
     * @param ScenarioAdapterInterface $adapter
     * @param string $adapterFile 适配器文件的绝对路径
     * @return bool
     */
    private function registerAdapter(ScenarioAdapterInterface $adapter, string $adapterFile): bool
    {
        $code = $adapter->getCode();
        
        // 将绝对路径转换为相对根目录的路径
        $relativePath = $this->getRelativePath($adapterFile);
        
        // 检查是否已存在
        $existingAdapter = $this->scenarioAdapter->reset()
            ->where(AiScenarioAdapter::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        if ($existingAdapter->getId()) {
            // 更新现有适配器
            return $this->updateExistingAdapter($existingAdapter, $adapter, $relativePath);
        } else {
            // 创建新适配器
            return $this->createNewAdapter($adapter, $relativePath);
        }
    }

    /**
     * 将绝对路径转换为相对根目录的路径
     * 
     * @param string $absolutePath 绝对路径
     * @return string 相对根目录的路径
     */
    private function getRelativePath(string $absolutePath): string
    {
        $basePath = BP;
        $absolutePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath);
        $basePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath);
        
        // 如果路径以根目录开头，则提取相对路径
        if (str_starts_with($absolutePath, $basePath)) {
            $relativePath = substr($absolutePath, strlen($basePath));
            return ltrim($relativePath, DIRECTORY_SEPARATOR);
        }
        
        // 如果无法转换，返回原路径（可能是相对路径）
        return $absolutePath;
    }

    /**
     * 创建新适配器
     * 
     * @param ScenarioAdapterInterface $adapter
     * @param string $relativePath 相对根目录的文件路径
     * @return bool
     */
    private function createNewAdapter(ScenarioAdapterInterface $adapter, string $relativePath): bool
    {
        $data = [
            AiScenarioAdapter::schema_fields_CODE => $adapter->getCode(),
            AiScenarioAdapter::schema_fields_NAME => $adapter->getName(),
            AiScenarioAdapter::schema_fields_DESCRIPTION => $adapter->getDescription(),
            AiScenarioAdapter::schema_fields_VERSION => $adapter->getVersion(),
            AiScenarioAdapter::schema_fields_CLASS_NAME => get_class($adapter),
            AiScenarioAdapter::schema_fields_FILE_PATH => $relativePath,
            AiScenarioAdapter::schema_fields_SUPPORTED_MODELS => json_encode($adapter->getSupportedModelTypes()),
            AiScenarioAdapter::schema_fields_PARAM_TEMPLATE => json_encode($adapter->getParamTemplate()),
            AiScenarioAdapter::schema_fields_EXAMPLES => json_encode($adapter->getExamples()),
            AiScenarioAdapter::schema_fields_IS_ACTIVE => 1,
            AiScenarioAdapter::schema_fields_CREATED_TIME => time(),
            AiScenarioAdapter::schema_fields_UPDATED_TIME => time()
        ];
        $data = \array_merge($data, $this->buildAdapterModelBindingData($adapter));

        $newAdapter = new AiScenarioAdapter();
        $newAdapter->setData($data)->save();
        
        return true;
    }

    /**
     * 更新现有适配器
     * 
     * @param AiScenarioAdapter $existingAdapter
     * @param ScenarioAdapterInterface $adapter
     * @param string $relativePath 相对根目录的文件路径
     * @return bool
     */
    private function updateExistingAdapter(AiScenarioAdapter $existingAdapter, ScenarioAdapterInterface $adapter, string $relativePath): bool
    {
        // 只更新允许更新的字段
        $updateData = [
            AiScenarioAdapter::schema_fields_CODE => $adapter->getCode(),
            AiScenarioAdapter::schema_fields_NAME => $adapter->getName(),
            AiScenarioAdapter::schema_fields_DESCRIPTION => $adapter->getDescription(),
            AiScenarioAdapter::schema_fields_VERSION => $adapter->getVersion(),
            AiScenarioAdapter::schema_fields_CLASS_NAME => get_class($adapter),
            AiScenarioAdapter::schema_fields_FILE_PATH => $relativePath,
            AiScenarioAdapter::schema_fields_SUPPORTED_MODELS => json_encode($adapter->getSupportedModelTypes()),
            AiScenarioAdapter::schema_fields_PARAM_TEMPLATE => json_encode($adapter->getParamTemplate()),
            AiScenarioAdapter::schema_fields_EXAMPLES => json_encode($adapter->getExamples()),
            AiScenarioAdapter::schema_fields_UPDATED_TIME => time()
        ];
        $bindingData = $this->buildAdapterModelBindingData($adapter);
        if ($bindingData !== []) {
            if (
                isset($bindingData[AiScenarioAdapter::schema_fields_DEFAULT_MODEL])
                && \trim((string)$existingAdapter->getData(AiScenarioAdapter::schema_fields_DEFAULT_MODEL)) === ''
            ) {
                $updateData[AiScenarioAdapter::schema_fields_DEFAULT_MODEL] = $bindingData[AiScenarioAdapter::schema_fields_DEFAULT_MODEL];
            }
            if (
                isset($bindingData[AiScenarioAdapter::schema_fields_MODEL_BINDINGS])
                && \trim((string)$existingAdapter->getData(AiScenarioAdapter::schema_fields_MODEL_BINDINGS)) === ''
            ) {
                $updateData[AiScenarioAdapter::schema_fields_MODEL_BINDINGS] = $bindingData[AiScenarioAdapter::schema_fields_MODEL_BINDINGS];
            }
        }

        foreach ($updateData as $field => $value) {
            $existingAdapter->setData($field, $value);
        }

        $existingAdapter->save();

        return true;
    }

    /**
     * @return array<string,string>
     */
    private function buildAdapterModelBindingData(ScenarioAdapterInterface $adapter): array
    {
        if (!$adapter instanceof AdapterModelBindingInterface) {
            return [];
        }

        $bindings = [];
        foreach ($adapter->getDefaultModelBindings() as $modality => $modelCode) {
            $modality = AiModel::normalizePrimaryModality((string)$modality);
            $modelCode = \trim((string)$modelCode);
            if ($modality !== '' && $modelCode !== '') {
                $bindings[$modality] = $modelCode;
            }
        }

        if ($bindings === []) {
            return [];
        }

        $data = [
            AiScenarioAdapter::schema_fields_MODEL_BINDINGS => \json_encode(
                $bindings,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
            ),
        ];
        if (!empty($bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT])) {
            $data[AiScenarioAdapter::schema_fields_DEFAULT_MODEL] = $bindings[AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT];
        }

        return $data;
    }

    /**
     * 获取场景适配器配置的默认模型代码（来自 ai_scenario_adapter.default_model）
     *
     * @param string $code 适配器代码
     * @return string|null 默认模型代码，未配置或适配器不存在时返回 null
     */
    public function getDefaultModelCodeForAdapter(string $code): ?string
    {
        $record = $this->scenarioAdapter->reset()
            ->where(AiScenarioAdapter::schema_fields_CODE, $code)
            ->where(AiScenarioAdapter::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        if (!$record || !$record->getId()) {
            return null;
        }
        $defaultModel = $record->getData(AiScenarioAdapter::schema_fields_DEFAULT_MODEL);
        return $defaultModel !== null && $defaultModel !== '' ? (string)$defaultModel : null;
    }

    /**
     * 获取适配器实例
     * 
     * @param string $code
     * @return ScenarioAdapterInterface|null
     */
    public function getAdapter(string $code): ?ScenarioAdapterInterface
    {
        // 先从缓存获取
        if (isset($this->registeredAdapters[$code])) {
            return $this->registeredAdapters[$code];
        }

        // 从数据库获取
        $adapterRecord = $this->scenarioAdapter->reset()
            ->where(AiScenarioAdapter::schema_fields_CODE, $code)
            ->where(AiScenarioAdapter::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        // 检查是否找到记录（通过 getId() 判断，参考 DefaultModelManager 的实现）
        if (!$adapterRecord || !$adapterRecord->getId()) {
            // 如果数据库中没有找到，尝试直接从代码中加载（备用方案）
            return $this->loadAdapterFromCode($code);
        }

        $className = $adapterRecord->getData(AiScenarioAdapter::schema_fields_CLASS_NAME);
        if (empty($className)) {
            // 类名为空，尝试从代码中加载
            return $this->loadAdapterFromCode($code);
        }
        
        // 优先使用保存的文件路径
        $relativePath = $adapterRecord->getData(AiScenarioAdapter::schema_fields_FILE_PATH);
        $adapterFile = null;
        
        if (!empty($relativePath)) {
            // 将相对路径转换为绝对路径
            $adapterFile = BP . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
            if (file_exists($adapterFile)) {
                require_once $adapterFile;
            } else {
                w_log_error("适配器文件不存在: {$adapterFile}，尝试从代码中加载适配器: {$code}");
                return $this->loadAdapterFromCode($code);
            }
        } else {
            // 如果没有保存文件路径，则根据类名查找（向后兼容）
            $adapterFile = $this->getAdapterFileFromClassName($className);
            if ($adapterFile && file_exists($adapterFile)) {
                require_once $adapterFile;
            }
        }
        
        // 检查类是否存在（先不使用 autoload，因为可能文件已加载但类名格式不对）
        if (!class_exists($className, false)) {
            // 如果文件已加载但类不存在，可能是类名格式问题，尝试从代码中加载
            w_log_error("适配器类不存在: {$className}，尝试从代码中加载适配器: {$code}");
            return $this->loadAdapterFromCode($code);
        }

        try {
            $adapter = new $className();
        } catch (\Exception $e) {
            w_log_error("实例化适配器失败: {$className}，错误: " . $e->getMessage());
            // 实例化失败，尝试从代码中加载
            return $this->loadAdapterFromCode($code);
        }
        
        if (!$adapter instanceof ScenarioAdapterInterface) {
            w_log_error("适配器类 {$className} 未实现 ScenarioAdapterInterface 接口");
            return null;
        }

        // 验证适配器代码是否匹配
        if ($adapter->getCode() !== $code) {
            w_log_error("适配器代码不匹配: 期望 {$code}，实际 {$adapter->getCode()}");
            return null;
        }

        // 缓存适配器实例
        $this->registeredAdapters[$code] = $adapter;
        
        return $adapter;
    }

    /**
     * 根据类名获取适配器文件路径
     * 
     * 仅在 Weline_Ai 内置目录和 ExtendsData 中已登记的衍生模块中查找，
     * 避免遍历所有模块带来的性能开销。
     * 
     * @param string $className 完整的类名（包含命名空间）
     * @return string|null 文件路径，如果找不到则返回 null
     */
    private function getAdapterFileFromClassName(string $className): ?string
    {
        // 移除前导反斜杠
        $className = ltrim($className, '\\');
        
        // 1. 检查是否是 Weline_Ai 模块的适配器
        if (str_starts_with($className, 'Weline\\Ai\\Adapter\\')) {
            $shortClassName = str_replace('Weline\\Ai\\Adapter\\', '', $className);
            $adapterFile = BP . DIRECTORY_SEPARATOR . self::ADAPTER_DIR . $shortClassName . '.php';
            if (file_exists($adapterFile)) {
                return $adapterFile;
            }
        }
        
        // 2. 仅在 ExtendsData 中已登记的衍生模块中查找
        $extendedBy = ExtendsData::getExtendedBy('Weline_Ai');
        if (!empty($extendedBy)) {
            $env = Env::getInstance();
            $moduleList = $env->getModuleList();
            $namespaceParts = explode('\\', $className);
            $shortClassName = end($namespaceParts);
            
            foreach ($extendedBy as $sourceModule => $extensions) {
                if (!isset($moduleList[$sourceModule])) {
                    continue;
                }
                $moduleBasePath = $moduleList[$sourceModule]['base_path'] ?? '';
                if (empty($moduleBasePath)) {
                    continue;
                }
                
                $adapterDir = rtrim($moduleBasePath, '/\\') . DIRECTORY_SEPARATOR 
                    . 'extends' . DIRECTORY_SEPARATOR 
                    . 'module' . DIRECTORY_SEPARATOR 
                    . 'Weline_Ai' . DIRECTORY_SEPARATOR 
                    . 'Adapter';
                
                if (!is_dir($adapterDir)) {
                    continue;
                }
                
                $adapterFile = $adapterDir . DIRECTORY_SEPARATOR . $shortClassName . '.php';
                
                if (file_exists($adapterFile)) {
                    // 验证文件中的命名空间是否匹配
                    $content = file_get_contents($adapterFile);
                    if ($content && preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                        $fileNamespace = trim($matches[1]);
                        $expectedNamespace = substr($className, 0, strrpos($className, '\\'));
                        if ($fileNamespace === $expectedNamespace) {
                            return $adapterFile;
                        }
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * 根据适配器代码直接从代码中加载适配器（备用方案）
     * 当数据库中没有找到适配器或类不存在时，尝试扫描并加载
     * 
     * 仅扫描 Weline_Ai 内置目录和 ExtendsData 中已登记的衍生模块，
     * 避免遍历所有模块带来的性能开销。
     * 
     * @param string $code 适配器代码
     * @return ScenarioAdapterInterface|null
     */
    private function loadAdapterFromCode(string $code): ?ScenarioAdapterInterface
    {
        try {
            // 1. 首先扫描 Weline_Ai 模块的适配器
            $adapterDir = BP . DIRECTORY_SEPARATOR . self::ADAPTER_DIR;
            if (is_dir($adapterDir)) {
                $adapterFiles = $this->fileScanner->globFile($adapterDir . '/*' . self::ADAPTER_SUFFIX);
                foreach ($adapterFiles as $adapterFile) {
                    try {
                        $adapter = $this->loadAdapter($adapterFile);
                        if ($adapter && $adapter->getCode() === $code) {
                            $this->registerAdapter($adapter, $adapterFile);
                            $this->registeredAdapters[$code] = $adapter;
                            return $adapter;
                        }
                    } catch (\Exception $e) {
                        // 继续查找
                    }
                }
            }
            
            // 2. 仅扫描 ExtendsData 中已登记的衍生模块（避免遍历所有模块）
            $extendedBy = ExtendsData::getExtendedBy('Weline_Ai');
            if (!empty($extendedBy)) {
                $env = Env::getInstance();
                $moduleList = $env->getModuleList();
                
                foreach ($extendedBy as $sourceModule => $extensions) {
                    if (!isset($moduleList[$sourceModule])) {
                        continue;
                    }
                    $moduleBasePath = $moduleList[$sourceModule]['base_path'] ?? '';
                    if (empty($moduleBasePath)) {
                        continue;
                    }
                    
                    $adapterDir = rtrim($moduleBasePath, '/\\') . DIRECTORY_SEPARATOR 
                        . 'extends' . DIRECTORY_SEPARATOR 
                        . 'module' . DIRECTORY_SEPARATOR 
                        . 'Weline_Ai' . DIRECTORY_SEPARATOR 
                        . 'Adapter';
                    
                    if (!is_dir($adapterDir)) {
                        continue;
                    }
                    
                    $adapterFiles = $this->fileScanner->globFile($adapterDir . DIRECTORY_SEPARATOR . '*' . self::ADAPTER_SUFFIX);
                    
                    foreach ($adapterFiles as $adapterFile) {
                        try {
                            $adapter = $this->loadAdapter($adapterFile, $sourceModule);
                            if ($adapter && $adapter->getCode() === $code) {
                                $this->registerAdapter($adapter, $adapterFile);
                                $this->registeredAdapters[$code] = $adapter;
                                return $adapter;
                            }
                        } catch (\Exception $e) {
                            // 继续查找
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            w_log_error("从代码加载适配器失败: {$code}, 错误: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * 获取所有活跃的适配器
     * 
     * @return array
     */
    public function getAllActiveAdapters(): array
    {
        $adapterRecords = $this->scenarioAdapter->reset()
            ->where(AiScenarioAdapter::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        $adapters = [];
        
        // 检查结果类型
        if (!$adapterRecords || !is_iterable($adapterRecords)) {
            return $adapters;
        }
        
        $items = is_object($adapterRecords) && method_exists($adapterRecords, 'getItems') 
            ? $adapterRecords->getItems() 
            : $adapterRecords;
        
        foreach ($items as $record) {
            if (!is_object($record)) {
                continue;
            }
            
            $code = $record->getData(AiScenarioAdapter::schema_fields_CODE);
            $adapter = $this->getAdapter($code);
            
            if ($adapter) {
                $adapters[$code] = $adapter;
            }
        }

        return $adapters;
    }

    /**
     * 验证适配器
     * 
     * @param ScenarioAdapterInterface $adapter
     * @return array 验证错误列表
     */
    public function validateAdapter(ScenarioAdapterInterface $adapter): array
    {
        $errors = [];

        // 验证基本信息
        if (empty($adapter->getCode())) {
            $errors[] = '适配器代码不能为空';
        }

        if (empty($adapter->getName())) {
            $errors[] = '适配器名称不能为空';
        }

        if (empty($adapter->getVersion())) {
            $errors[] = '适配器版本不能为空';
        }

        // 验证支持的模型类型
        $supportedTypes = $adapter->getSupportedModelTypes();
        if (empty($supportedTypes) || !is_array($supportedTypes)) {
            $errors[] = '必须指定支持的模型类型';
        }

        // 验证参数模板
        try {
            $paramTemplate = $adapter->getParamTemplate();
            if (!is_array($paramTemplate)) {
                $errors[] = '参数模板必须是数组格式';
            }
        } catch (\Exception $e) {
            $errors[] = '参数模板格式错误: ' . $e->getMessage();
        }

        // 验证示例
        try {
            $examples = $adapter->getExamples();
            if (!is_array($examples)) {
                $errors[] = '示例必须是数组格式';
            }
        } catch (\Exception $e) {
            $errors[] = '示例格式错误: ' . $e->getMessage();
        }

        return $errors;
    }

    /**
     * 获取适配器统计信息
     * 
     * @return array
     */
    public function getAdapterStats(): array
    {
        $totalCount = $this->scenarioAdapter->reset()
            ->select()
            ->fetch()
            ->count();

        $activeCount = $this->scenarioAdapter->reset()
            ->where(AiScenarioAdapter::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->count();

        return [
            'total' => $totalCount,
            'active' => $activeCount,
            'inactive' => $totalCount - $activeCount
        ];
    }

    /**
     * 清理无效适配器
     * 
     * @return int 清理的数量
     */
    public function cleanupInvalidAdapters(): int
    {
        $adapterRecords = $this->scenarioAdapter->reset()
            ->select()
            ->fetch();

        $cleanedCount = 0;

        if ($adapterRecords && is_iterable($adapterRecords)) {
            foreach ($adapterRecords as $record) {
                if (!is_object($record)) {
                    continue;
                }
                
                $className = $record->getData(AiScenarioAdapter::schema_fields_CLASS_NAME);
                
                // 检查类是否存在
                if (!class_exists($className)) {
                    $record->setData(AiScenarioAdapter::schema_fields_IS_ACTIVE, 0);
                    $record->save();
                    $cleanedCount++;
                    continue;
                }

                // 检查是否实现了接口
                try {
                    $instance = new $className();
                    if (!$instance instanceof ScenarioAdapterInterface) {
                        $record->setData(AiScenarioAdapter::schema_fields_IS_ACTIVE, 0);
                        $record->save();
                        $cleanedCount++;
                    }
                } catch (\Exception $e) {
                    // 如果实例化失败，也标记为无效
                    $record->setData(AiScenarioAdapter::schema_fields_IS_ACTIVE, 0);
                    $record->save();
                    $cleanedCount++;
                }
            }
        }

        return $cleanedCount;
    }
}
