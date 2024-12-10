<?php

namespace Weline\ModuleManager\Observer;

use Weline\Framework\App\Exception;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Register\Register;
use Weline\Framework\Setup\Data\Context as SetupContext;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\ModuleManager\Model\Module;
use Weline\ModuleManager\Model\Module\Table;

class ModuleUpdateBefore implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        /**@var Table $model */
        $model = ObjectManager::getInstance(Table::class);
        $modelSetup = ObjectManager::getInstance(ModelSetup::class);
        $modelSetup->putModel($model);
        $module = $this->getThisModuleInfo();
        $setup_context = ObjectManager::make(SetupContext::class, [
            'module_name' => $module->getName(),
            'module_version' => $module->getVersion(),
            'module_description' => $module->getDescription()
        ], '__construct');
        $model->install($modelSetup, $setup_context);
    }

    /**
     * @throws Exception
     */
    private function getThisModuleInfo(): Module
    {
        $register = __DIR__ . '/../register.php';
        $registerArgs = Register::parserRegisterFunctionParams($register);
        $module = trim($registerArgs['module_name'], '\'\"');
        $vendorArr = explode('_', $module);
        $vendor = array_shift($vendorArr);
        $base_path = str_replace(Register::register_file, '', $register);
        $env_file = $base_path . 'etc' . DS . 'env.php';
        $env = [];
        if (file_exists($env_file)) {
            $env = (array)include $env_file;
        }
        $dependencies = $registerArgs['dependencies'] ?? [];
        foreach ($dependencies as &$dependency) {
            $dependency = trim($dependency, '\'"');
        }
        $dependencies = array_merge($dependencies, ($env['dependencies'] ?? []));
        $pathArr = explode(DS, $base_path);
        $path = array_pop($pathArr);
        if (empty($path)) {
            $path = array_pop($pathArr);
        }
        $path = array_pop($pathArr) . DS . $path;
        return new Module(array_merge($registerArgs, [
            'vendor' => $vendor,
            'name' => $module,
            'path' => $path,
            'register' => $register,
            'id' => $module,
            'dependencies' => $dependencies,
            'env_file' => $env_file,
            'base_path' => $base_path,
            'env' => $env
        ]));
    }
}
