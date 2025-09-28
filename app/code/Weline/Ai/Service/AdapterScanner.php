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

use Weline\Ai\Api\ScenarioAdapterInterface;
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Framework\System\File\Scan;
use Weline\Framework\App\Exception;

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
        $adapterDir = BP . DIRECTORY_SEPARATOR . self::ADAPTER_DIR;
        
        if (!is_dir($adapterDir)) {
            throw new Exception("适配器目录不存在: {$adapterDir}");
        }

        $adapterFiles = $this->fileScanner->globFile($adapterDir . '/*' . self::ADAPTER_SUFFIX);
        $scannedAdapters = [];

        foreach ($adapterFiles as $adapterFile) {
            try {
                $adapter = $this->loadAdapter($adapterFile);
                if ($adapter) {
                    $this->registerAdapter($adapter);
                    $scannedAdapters[] = $adapter;
                }
            } catch (\Exception $e) {
                // 记录错误但继续处理其他适配器
                error_log("加载适配器失败: {$adapterFile}, 错误: " . $e->getMessage());
            }
        }

        return $scannedAdapters;
    }

    /**
     * 加载适配器
     * 
     * @param string $adapterFile
     * @return ScenarioAdapterInterface|null
     */
    private function loadAdapter(string $adapterFile): ?ScenarioAdapterInterface
    {
        // 从文件路径推断类名
        $className = $this->getClassNameFromFile($adapterFile);
        
        if (!$className) {
            return null;
        }

        // 检查类是否存在
        if (!class_exists($className)) {
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
     * @return string|null
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $fileName = basename($filePath, '.php');
        
        // 验证文件名格式
        if (!str_ends_with($fileName, 'Adapter')) {
            return null;
        }

        return "\\Weline\\Ai\\Adapter\\{$fileName}";
    }

    /**
     * 注册适配器
     * 
     * @param ScenarioAdapterInterface $adapter
     * @return bool
     */
    private function registerAdapter(ScenarioAdapterInterface $adapter): bool
    {
        $code = $adapter->getCode();
        
        // 检查是否已存在
        $existingAdapter = $this->scenarioAdapter->reset()
            ->where(AiScenarioAdapter::fields_CODE, $code)
            ->find()
            ->fetch();

        if ($existingAdapter->getId()) {
            // 更新现有适配器
            return $this->updateExistingAdapter($existingAdapter, $adapter);
        } else {
            // 创建新适配器
            return $this->createNewAdapter($adapter);
        }
    }

    /**
     * 创建新适配器
     * 
     * @param ScenarioAdapterInterface $adapter
     * @return bool
     */
    private function createNewAdapter(ScenarioAdapterInterface $adapter): bool
    {
        $data = [
            AiScenarioAdapter::fields_CODE => $adapter->getCode(),
            AiScenarioAdapter::fields_NAME => $adapter->getName(),
            AiScenarioAdapter::fields_DESCRIPTION => $adapter->getDescription(),
            AiScenarioAdapter::fields_VERSION => $adapter->getVersion(),
            AiScenarioAdapter::fields_CLASS_NAME => get_class($adapter),
            AiScenarioAdapter::fields_SUPPORTED_MODELS => json_encode($adapter->getSupportedModelTypes()),
            AiScenarioAdapter::fields_PARAM_TEMPLATE => json_encode($adapter->getParamTemplate()),
            AiScenarioAdapter::fields_EXAMPLES => json_encode($adapter->getExamples()),
            AiScenarioAdapter::fields_IS_ACTIVE => 1,
            AiScenarioAdapter::fields_CREATED_TIME => time(),
            AiScenarioAdapter::fields_UPDATED_TIME => time()
        ];

        $newAdapter = new AiScenarioAdapter();
        $newAdapter->setData($data)->save();
        
        return true;
    }

    /**
     * 更新现有适配器
     * 
     * @param AiScenarioAdapter $existingAdapter
     * @param ScenarioAdapterInterface $adapter
     * @return bool
     */
    private function updateExistingAdapter(AiScenarioAdapter $existingAdapter, ScenarioAdapterInterface $adapter): bool
    {
        // 只更新允许更新的字段
        $updateData = [
            AiScenarioAdapter::fields_CODE => $adapter->getCode(),
            AiScenarioAdapter::fields_NAME => $adapter->getName(),
            AiScenarioAdapter::fields_DESCRIPTION => $adapter->getDescription(),
            AiScenarioAdapter::fields_VERSION => $adapter->getVersion(),
            AiScenarioAdapter::fields_CLASS_NAME => get_class($adapter),
            AiScenarioAdapter::fields_SUPPORTED_MODELS => json_encode($adapter->getSupportedModelTypes()),
            AiScenarioAdapter::fields_PARAM_TEMPLATE => json_encode($adapter->getParamTemplate()),
            AiScenarioAdapter::fields_EXAMPLES => json_encode($adapter->getExamples()),
            AiScenarioAdapter::fields_UPDATED_TIME => time()
        ];

        foreach ($updateData as $field => $value) {
            $existingAdapter->setData($field, $value);
        }

        $existingAdapter->save();
        
        return true;
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
            ->where(AiScenarioAdapter::fields_CODE, $code)
            ->where(AiScenarioAdapter::fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        if (!$adapterRecord) {
            return null;
        }

        $className = $adapterRecord->getData(AiScenarioAdapter::fields_CLASS_NAME);
        
        if (!class_exists($className)) {
            return null;
        }

        $adapter = new $className();
        
        if (!$adapter instanceof ScenarioAdapterInterface) {
            return null;
        }

        // 缓存适配器实例
        $this->registeredAdapters[$code] = $adapter;
        
        return $adapter;
    }

    /**
     * 获取所有活跃的适配器
     * 
     * @return array
     */
    public function getAllActiveAdapters(): array
    {
        $adapterRecords = $this->scenarioAdapter->reset()
            ->where(AiScenarioAdapter::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        $adapters = [];
        
        foreach ($adapterRecords as $record) {
            $code = $record->getData(AiScenarioAdapter::fields_CODE);
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
            ->where(AiScenarioAdapter::fields_IS_ACTIVE, 1)
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
                
                $className = $record->getData(AiScenarioAdapter::fields_CLASS_NAME);
                
                // 检查类是否存在
                if (!class_exists($className)) {
                    $record->setData(AiScenarioAdapter::fields_IS_ACTIVE, 0);
                    $record->save();
                    $cleanedCount++;
                    continue;
                }

                // 检查是否实现了接口
                try {
                    $instance = new $className();
                    if (!$instance instanceof ScenarioAdapterInterface) {
                        $record->setData(AiScenarioAdapter::fields_IS_ACTIVE, 0);
                        $record->save();
                        $cleanedCount++;
                    }
                } catch (\Exception $e) {
                    // 如果实例化失败，也标记为无效
                    $record->setData(AiScenarioAdapter::fields_IS_ACTIVE, 0);
                    $record->save();
                    $cleanedCount++;
                }
            }
        }

        return $cleanedCount;
    }
}
