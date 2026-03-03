<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Service;

use Weline\Ai\Interface\AgentInterface;
use Weline\Ai\Model\AiAgent;
use Weline\Framework\System\File\Scan;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;

/**
 * 智能体扫描器服务
 * 
 * 功能：
 * - 自动扫描 Agent 目录（内置 + 扩展模块）
 * - 注册/更新智能体到数据库
 * - 按场景码查询可用智能体
 * - 复用 AdapterScanner 的扫描模式
 */
class AgentScanner
{
    /**
     * 内置智能体目录
     */
    private const AGENT_DIR = 'app/code/Weline/Ai/Agent/';

    /**
     * 智能体文件后缀
     */
    private const AGENT_SUFFIX = 'Agent.php';

    private AiAgent $agentModel;
    private Scan $fileScanner;

    /**
     * 已注册的智能体实例缓存
     */
    private array $registeredAgents = [];

    public function __construct(
        AiAgent $agentModel,
        Scan $fileScanner
    ) {
        $this->agentModel = $agentModel;
        $this->fileScanner = $fileScanner;
    }

    /**
     * 扫描所有智能体
     * 
     * @return AgentInterface[]
     */
    public function scanAllAgents(): array
    {
        $scannedAgents = [];

        // 1. 扫描 Weline_Ai 模块内置智能体
        $agentDir = BP . DIRECTORY_SEPARATOR . self::AGENT_DIR;
        if (is_dir($agentDir)) {
            $agentFiles = $this->fileScanner->globFile($agentDir . '/*' . self::AGENT_SUFFIX);
            foreach ($agentFiles as $agentFile) {
                // 排除 AgentResult.php 等非智能体文件
                $basename = basename($agentFile, '.php');
                if ($basename === 'AgentResult') {
                    continue;
                }
                try {
                    $agent = $this->loadAgent($agentFile);
                    if ($agent) {
                        $this->registerAgent($agent, $agentFile);
                        $scannedAgents[] = $agent;
                    }
                } catch (\Exception $e) {
                    w_log_error("[AgentScanner] 加载内置智能体失败: {$agentFile}, 错误: " . $e->getMessage());
                }
            }
        }

        // 2. 扫描其他模块的 extends/module/Weline_Ai/Agent/ 目录
        $otherModulesAgents = $this->scanOtherModulesAgents();
        foreach ($otherModulesAgents as $agentInfo) {
            try {
                $agent = $agentInfo['agent'];
                $agentFile = $agentInfo['file'];
                $moduleName = $agentInfo['module'] ?? '';
                $this->registerAgent($agent, $agentFile, $moduleName);
                $scannedAgents[] = $agent;
            } catch (\Exception $e) {
                w_log_error("[AgentScanner] 注册扩展智能体失败: " . $e->getMessage());
            }
        }

        return $scannedAgents;
    }

    /**
     * 扫描其他模块的智能体
     */
    private function scanOtherModulesAgents(): array
    {
        $agents = [];

        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Ai');
            if (empty($extendedBy)) {
                return $agents;
            }

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

                $agentDir = rtrim($moduleBasePath, '/\\') . DIRECTORY_SEPARATOR
                    . 'extends' . DIRECTORY_SEPARATOR
                    . 'module' . DIRECTORY_SEPARATOR
                    . 'Weline_Ai' . DIRECTORY_SEPARATOR
                    . 'Agent';

                if (!is_dir($agentDir)) {
                    continue;
                }

                $agentFiles = $this->fileScanner->globFile($agentDir . DIRECTORY_SEPARATOR . '*' . self::AGENT_SUFFIX);

                foreach ($agentFiles as $agentFile) {
                    try {
                        $agent = $this->loadAgent($agentFile, $sourceModule);
                        if ($agent) {
                            $agents[] = [
                                'agent' => $agent,
                                'file' => $agentFile,
                                'module' => $sourceModule
                            ];
                        }
                    } catch (\Exception $e) {
                        w_log_error("[AgentScanner] 加载扩展智能体失败: {$agentFile}, 错误: " . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            w_log_error("[AgentScanner] 扫描扩展智能体失败: " . $e->getMessage());
        }

        return $agents;
    }

    /**
     * 加载智能体实例
     */
    private function loadAgent(string $agentFile, ?string $moduleName = null): ?AgentInterface
    {
        if (!file_exists($agentFile)) {
            return null;
        }

        require_once $agentFile;

        $className = $this->getClassNameFromFile($agentFile, $moduleName);
        if (!$className) {
            return null;
        }

        if (!class_exists($className, false)) {
            return null;
        }

        $instance = new $className();

        if (!$instance instanceof AgentInterface) {
            throw new Exception(__('智能体类 %{1} 必须实现 AgentInterface 接口', [$className]));
        }

        return $instance;
    }

    /**
     * 从文件解析类名
     */
    private function getClassNameFromFile(string $filePath, ?string $moduleName = null): ?string
    {
        // extends 目录下的文件：从文件内容解析命名空间
        if (str_contains($filePath, DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR)
            || str_contains($filePath, '/extends/')) {
            $content = file_get_contents($filePath);
            if ($content === false) {
                return null;
            }

            if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
                $namespace = trim($nsMatch[1]);
                if (preg_match('/class\s+(\w+)/', $content, $clsMatch)) {
                    return "\\{$namespace}\\{$clsMatch[1]}";
                }
            }
            return null;
        }

        // Weline_Ai 内置智能体
        $fileName = basename($filePath, '.php');
        return "\\Weline\\Ai\\Agent\\{$fileName}";
    }

    /**
     * 注册智能体到数据库
     */
    private function registerAgent(AgentInterface $agent, string $agentFile, string $moduleName = ''): bool
    {
        $code = $agent->getCode();
        $relativePath = $this->getRelativePath($agentFile);

        $existing = $this->agentModel->reset()
            ->where(AiAgent::fields_CODE, $code)
            ->find()
            ->fetch();

        $currentTime = time();
        $data = [
            AiAgent::fields_CODE => $code,
            AiAgent::fields_NAME => $agent->getName(),
            AiAgent::fields_DESCRIPTION => $agent->getDescription(),
            AiAgent::fields_VERSION => $agent->getVersion(),
            AiAgent::fields_CLASS_NAME => get_class($agent),
            AiAgent::fields_FILE_PATH => $relativePath,
            AiAgent::fields_SCENARIOS => json_encode($agent->getScenarios(), JSON_UNESCAPED_UNICODE),
            AiAgent::fields_TOOLS_COUNT => count($agent->getTools()),
            AiAgent::fields_MAX_ITERATIONS => $agent->getMaxIterations(),
            AiAgent::fields_MODULE => $moduleName,
            AiAgent::fields_IS_ACTIVE => 1,
            AiAgent::fields_UPDATED_TIME => $currentTime,
        ];

        if ($existing->getId()) {
            // 更新
            foreach ($data as $field => $value) {
                $existing->setData($field, $value);
            }
            $existing->save();
        } else {
            // 新增
            $data[AiAgent::fields_CREATED_TIME] = $currentTime;
            $newAgent = new AiAgent();
            $newAgent->setData($data)->save();
        }

        return true;
    }

    /**
     * 获取智能体实例
     */
    public function getAgent(string $code): ?AgentInterface
    {
        if (isset($this->registeredAgents[$code])) {
            return $this->registeredAgents[$code];
        }

        $record = $this->agentModel->reset()
            ->where(AiAgent::fields_CODE, $code)
            ->where(AiAgent::fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();

        if (!$record || !$record->getId()) {
            return $this->loadAgentFromCode($code);
        }

        $className = $record->getData(AiAgent::fields_CLASS_NAME);
        if (empty($className)) {
            return $this->loadAgentFromCode($code);
        }

        // 加载文件
        $relativePath = $record->getData(AiAgent::fields_FILE_PATH);
        if (!empty($relativePath)) {
            $agentFile = BP . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
            if (file_exists($agentFile)) {
                require_once $agentFile;
            } else {
                return $this->loadAgentFromCode($code);
            }
        }

        if (!class_exists($className, false)) {
            return $this->loadAgentFromCode($code);
        }

        try {
            $agent = new $className();
        } catch (\Exception $e) {
            w_log_error("[AgentScanner] 实例化智能体失败: {$className}, 错误: " . $e->getMessage());
            return $this->loadAgentFromCode($code);
        }

        if (!$agent instanceof AgentInterface) {
            return null;
        }

        if ($agent->getCode() !== $code) {
            return null;
        }

        $this->registeredAgents[$code] = $agent;
        return $agent;
    }

    /**
     * 根据场景码获取可用智能体列表
     */
    public function getAgentsForScenario(string $scenarioCode): array
    {
        $records = $this->agentModel->reset()
            ->where(AiAgent::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        $agents = [];

        if (!$records) {
            return $agents;
        }

        // Model::fetch() 返回 Model 对象，用 getItems() 获取记录数组
        $items = is_object($records) && method_exists($records, 'getItems')
            ? $records->getItems()
            : (is_array($records) ? $records : []);

        foreach ($items as $record) {
            if (!is_object($record)) {
                continue;
            }

            $scenarios = $record->getData(AiAgent::fields_SCENARIOS);
            if (is_string($scenarios)) {
                $scenarioList = json_decode($scenarios, true) ?: [];
            } else {
                $scenarioList = is_array($scenarios) ? $scenarios : [];
            }

            if (in_array($scenarioCode, $scenarioList, true)) {
                $code = $record->getData(AiAgent::fields_CODE);
                $agent = $this->getAgent($code);
                if ($agent) {
                    $agents[$code] = $agent;
                }
            }
        }

        return $agents;
    }

    /**
     * 获取所有活跃的智能体
     */
    public function getAllActiveAgents(): array
    {
        $records = $this->agentModel->reset()
            ->where(AiAgent::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        $agents = [];

        if (!$records) {
            return $agents;
        }

        // Model::fetch() 返回 Model 对象，用 getItems() 获取记录数组
        $items = is_object($records) && method_exists($records, 'getItems')
            ? $records->getItems()
            : (is_array($records) ? $records : []);

        foreach ($items as $record) {
            if (!is_object($record)) {
                continue;
            }
            $code = $record->getData(AiAgent::fields_CODE);
            $agent = $this->getAgent($code);
            if ($agent) {
                $agents[$code] = $agent;
            }
        }

        return $agents;
    }

    /**
     * 根据代码从文件系统加载智能体（备用方案）
     * 
     * 仅扫描 Weline_Ai 内置目录和 ExtendsData 中已登记的衍生模块，
     * 避免遍历所有模块带来的性能开销。
     */
    private function loadAgentFromCode(string $code): ?AgentInterface
    {
        try {
            // 1. 扫描内置智能体
            $agentDir = BP . DIRECTORY_SEPARATOR . self::AGENT_DIR;
            if (is_dir($agentDir)) {
                $agentFiles = $this->fileScanner->globFile($agentDir . '/*' . self::AGENT_SUFFIX);
                foreach ($agentFiles as $agentFile) {
                    $basename = basename($agentFile, '.php');
                    if ($basename === 'AgentResult') {
                        continue;
                    }
                    try {
                        $agent = $this->loadAgent($agentFile);
                        if ($agent && $agent->getCode() === $code) {
                            $this->registerAgent($agent, $agentFile);
                            $this->registeredAgents[$code] = $agent;
                            return $agent;
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

                    $agentDir = rtrim($moduleBasePath, '/\\') . DIRECTORY_SEPARATOR
                        . 'extends' . DIRECTORY_SEPARATOR
                        . 'module' . DIRECTORY_SEPARATOR
                        . 'Weline_Ai' . DIRECTORY_SEPARATOR
                        . 'Agent';

                    if (!is_dir($agentDir)) {
                        continue;
                    }

                    $agentFiles = $this->fileScanner->globFile($agentDir . DIRECTORY_SEPARATOR . '*' . self::AGENT_SUFFIX);
                    foreach ($agentFiles as $agentFile) {
                        try {
                            $agent = $this->loadAgent($agentFile, $sourceModule);
                            if ($agent && $agent->getCode() === $code) {
                                $this->registerAgent($agent, $agentFile, $sourceModule);
                                $this->registeredAgents[$code] = $agent;
                                return $agent;
                            }
                        } catch (\Exception $e) {
                            // 继续查找
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            w_log_error("[AgentScanner] 从代码加载智能体失败: {$code}, 错误: " . $e->getMessage());
        }

        return null;
    }

    /**
     * 将绝对路径转换为相对根目录的路径
     */
    private function getRelativePath(string $absolutePath): string
    {
        $basePath = BP;
        $absolutePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath);
        $basePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath);

        if (str_starts_with($absolutePath, $basePath)) {
            return ltrim(substr($absolutePath, strlen($basePath)), DIRECTORY_SEPARATOR);
        }

        return $absolutePath;
    }
}
