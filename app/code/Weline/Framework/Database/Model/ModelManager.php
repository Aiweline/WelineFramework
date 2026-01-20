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
        $model_files_data = array_reverse($this->moduleReader->readClass($module, 'Model'));
        foreach ($model_files_data as $key => $model_class) {
            $this->printing->note($model_class, __('Model升级'));
            if (class_exists($model_class)) {
                // 跳过抽象类、trait、接口和静态类
                $reflection = new \ReflectionClass($model_class);
                if ($reflection->isAbstract() || $reflection->isTrait() || $reflection->isInterface()) {
                    continue;
                }
                if (ObjectManager::isStaticClass($model_class)) {
                    continue;
                }
                $model = ObjectManager::getInstance($model_class);
                if ($model instanceof AbstractModel) {
                    $data = new DataObject(['model' => $model, 'type' => $type, 'object' => $this, 'module' => $module]);
                    $this->getEvenManager()->dispatch('Weline_Framework_Database::model_update_before', $data);
                    if (PROD) {
                        $this->printing->printing($model::class);
                    }
                    $this->setupModel($model, $type, $context);
                    $this->getEvenManager()->dispatch('Weline_Framework_Database::model_update_after', $data);
                }
            } else {
                $this->printing->error($model_class, __('Model升级'));
            }
        }
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
        
        // 如果是 upgrade 或 setup，先检查表是否存在
        // 如果表不存在，先执行 install 安装表
        if (in_array($type, ['upgrade', 'setup'])) {
            if (!$modelSetup->tableExist()) {
                // 表不存在，先执行 install
                if (method_exists($model, 'install')) {
                    $this->printing->note(__('表 %{1} 不存在，先执行 install 安装表...', [$model->getTable()]));
                    $model->install($modelSetup, $context);
                }
            }
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
                // 其他错误，重新抛出
                throw $e;
            }
        }
    }
}
