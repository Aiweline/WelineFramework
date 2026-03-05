<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Model;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\ModelInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Printing;
use Weline\Framework\Register\Register;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\System\File\Data\File;

class ModelManager
{
    private ModuleFileReader $moduleReader;
    private Printing $printing;
    private ?EventsManager $eventsManager = null;

    public function __construct(
        ModuleFileReader $moduleReader,
        Printing         $printing
    )
    {
        $this->moduleReader = $moduleReader;
        $this->printing = $printing;
    }


    public function update(Module $module, Context $context, string $type): void
    {
        if (!in_array($type, ['setup', 'upgrade', 'install'])) {
            throw new Exception(__('$type允许的值不在：%{1} 中', "'setup','upgrade','install'"));
        }
        // Phase 7：表结构由 SchemaDiffStage + bootstrap 负责，业务初始化由各模块 Setup/Install.php、Upgrade.php 负责；不再调用 Model 的 install/upgrade/setup。
    }

    public function getEvenManager(): EventsManager
    {
        if ($this->eventsManager) {
            return $this->eventsManager;
        }
        $this->eventsManager = ObjectManager::getInstance(EventsManager::class);
        return $this->eventsManager;
    }

    /**
     * @param AbstractModel $model
     * @param string $type
     * @param Context $context
     * @return void
     * @throws Exception
     * @throws \ReflectionException
     */
    public function setupModel(AbstractModel $model, string $type, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($model);
        $modelSetup->setContext($context); // 设置上下文，用于字段备份和恢复
        
        // 如果是 upgrade 或 setup，先检查表是否存在
        // 如果表不存在，先执行 install 安装表
        if (in_array($type, ['upgrade', 'setup'])) {
            $model->install($modelSetup, $context);
        }
        
        // 执行模型升级/安装/设置，如果遇到表不存在的错误，尝试先安装
        try {
            $model->$type($modelSetup, $context);
        } catch (\PDOException $e) {
            // 捕获表不存在的错误（MySQL: 1146, PostgreSQL: 42P01, SQLite: 1）
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            $isTableNotFound = (
                strpos($errorMessage, "doesn't exist") !== false ||
                strpos($errorMessage, "does not exist") !== false ||
                strpos($errorMessage, "Base table or view not found") !== false ||
                $errorCode == 1146 || // MySQL table not found
                $errorCode == '42P01' || // PostgreSQL table not found
                $errorCode == 1 // SQLite table not found
            );
            
            if ($isTableNotFound && in_array($type, ['upgrade', 'setup']) && method_exists($model, 'install')) {
                $this->printing->warning(__('执行 %{1} 时表不存在，自动执行 install 安装表...', [$type]));
                $model->install($modelSetup, $context);
                // 安装后再次尝试执行原操作
                $model->$type($modelSetup, $context);
            } else {
                throw $e;
            }
        } catch (\Weline\Framework\Database\Exception\DatabaseException $e) {
            $this->printing->error(__('数据库操作失败（%{1}）：%{2}', [$model::class, $e->getMessage()]));
            throw new Exception(__('数据库更新失败：%{1}。若为连接/版本问题，请查看 var/log/database_version.log。', [$e->getMessage()]), $e, 0);
        }
    }

    /**
     * @DESC          # 按照目录层级和类名长度对模型进行排序
     * 
     * 排序规则（优先级从高到低）：
     * 1. 目录层级深度：层级越浅（深度越小）的模型越先升级
     * 2. 类名长度：名字越短的模型越先升级
     * 3. 字母顺序：确保排序的稳定性
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/01/XX
     * 参数区：
     *
     * @param array $model_classes 模型类名数组
     *
     * @return array 排序后的模型类名数组
     */
    private function sortModelsByPriority(array $model_classes): array
    {
        usort($model_classes, function ($a, $b) {
            // 计算目录层级深度（通过命名空间的反斜杠数量）
            $depthA = substr_count($a, '\\');
            $depthB = substr_count($b, '\\');
            
            // 第一优先级：目录层级深度（层级越浅越先）
            if ($depthA !== $depthB) {
                return $depthA <=> $depthB;
            }
            
            // 第二优先级：类名长度（名字越短越先）
            $lengthA = strlen($a);
            $lengthB = strlen($b);
            if ($lengthA !== $lengthB) {
                return $lengthA <=> $lengthB;
            }
            
            // 第三优先级：字母顺序（确保排序的稳定性）
            return strcmp($a, $b);
        });
        
        return $model_classes;
    }
}
