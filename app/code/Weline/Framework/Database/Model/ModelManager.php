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
            throw new Exception(__('$type允许的值不在：%1 中', "'setup','upgrade','install'"));
        }
        $model_files_data = array_reverse($this->moduleReader->readClass($module, 'Model'));
        foreach ($model_files_data as $key => $model_class) {
            $this->printing->note($model_class, __('Model升级'));
            if (class_exists($model_class)) {
                $model = ObjectManager::getInstance($model_class);
                if ($model instanceof AbstractModel) {
                    $data = new DataObject(['model' => $model]);
                    $this->getEvenManager()->dispatch('Framework_Database::model_update_before', ['data' => $data, 'type' => $type, 'object' => $this, 'module' => $module]);
                    if (PROD) {
                        $this->printing->printing($model::class);
                    }
                    $modelSetup = ObjectManager::make(ModelSetup::class);
                    $modelSetup->putModel($model);
                    # 执行模型升级
                    $model->$type($modelSetup, $context);
                    $this->getEvenManager()->dispatch('Framework_Database::model_update_after', ['data' => $data, 'type' => $type, 'object' => $this, 'module' => $module]);
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
}
