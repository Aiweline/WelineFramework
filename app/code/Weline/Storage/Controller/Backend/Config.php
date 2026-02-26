<?php

declare(strict_types=1);

namespace Weline\Storage\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Storage\Model\StorageConfig;
use Weline\Storage\Service\StorageManager;

/**
 * @DESC | 后台存储配置控制器
 */
class Config extends BackendController
{
    private ?StorageConfig $storageConfig = null;
    private ?StorageManager $storageManager = null;
    
    private function getStorageConfig(): StorageConfig
    {
        if ($this->storageConfig === null) {
            $this->storageConfig = ObjectManager::getInstance(StorageConfig::class);
        }
        return $this->storageConfig;
    }
    
    private function getStorageManager(): StorageManager
    {
        if ($this->storageManager === null) {
            $this->storageManager = ObjectManager::getInstance(StorageManager::class);
        }
        return $this->storageManager;
    }
    
    /**
     * 存储配置列表页
     */
    public function index()
    {
        $configs = $this->getStorageConfig()->reset()
            ->order(StorageConfig::fields_IS_DEFAULT, 'DESC')
            ->order(StorageConfig::fields_CONFIG_ID, 'ASC')
            ->select()
            ->fetchArray();
        
        $this->assign('configs', $configs);
        $this->assign('drivers', StorageConfig::getDriverOptions());
        $this->assign('statusOptions', StorageConfig::getStatusOptions());
        $this->assign('title', __('存储配置'));
        
        return $this->fetch();
    }
    
    /**
     * 新增配置页面
     */
    public function getAdd()
    {
        $this->assign('config', null);
        $this->assign('drivers', StorageConfig::getDriverOptions());
        $this->assign('statusOptions', StorageConfig::getStatusOptions());
        $this->assign('title', __('新增存储配置'));
        
        return $this->fetch('form');
    }
    
    /**
     * 编辑配置页面
     */
    public function getEdit()
    {
        $id = (int) $this->request->getGet('id');
        
        $config = $this->getStorageConfig()->reset()
            ->where(StorageConfig::fields_CONFIG_ID, $id)
            ->find()
            ->fetch();
        
        if (!$config) {
            return $this->fetchJson(['code' => 404, 'msg' => __('配置不存在')]);
        }
        
        $configArray = $this->getStorageConfig()->getConfigArray();
        
        $this->assign('config', $this->getStorageConfig()->getData());
        $this->assign('configArray', $configArray);
        $this->assign('drivers', StorageConfig::getDriverOptions());
        $this->assign('statusOptions', StorageConfig::getStatusOptions());
        $this->assign('title', __('编辑存储配置'));
        
        return $this->fetch('form');
    }
    
    /**
     * 保存配置
     */
    public function postSave()
    {
        try {
            $data = $this->request->getPost();
            $id = (int) ($data['config_id'] ?? 0);
            
            if ($id > 0) {
                $this->getStorageConfig()->reset()
                    ->where(StorageConfig::fields_CONFIG_ID, $id)
                    ->find()
                    ->fetch();
                
                if (!$this->getStorageConfig()->getId()) {
                    return $this->fetchJson(['code' => 404, 'msg' => __('配置不存在')]);
                }
            } else {
                $this->getStorageConfig()->reset();
            }
            
            $name = \trim($data['name'] ?? '');
            if (!$name) {
                return $this->fetchJson(['code' => 400, 'msg' => __('存储标识不能为空')]);
            }
            
            if (!\preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name)) {
                return $this->fetchJson(['code' => 400, 'msg' => __('存储标识只能包含字母、数字和下划线，且以字母开头')]);
            }
            
            $existConfig = ObjectManager::getInstance(StorageConfig::class)
                ->reset()
                ->where(StorageConfig::fields_NAME, $name);
            
            if ($id > 0) {
                $existConfig->where(StorageConfig::fields_CONFIG_ID, $id, '!=');
            }
            
            $existConfig->find()->fetch();
            
            if ($existConfig->getId()) {
                return $this->fetchJson(['code' => 400, 'msg' => __('存储标识已存在')]);
            }
            
            $this->getStorageConfig()->setData(StorageConfig::fields_NAME, $name);
            $this->getStorageConfig()->setData(StorageConfig::fields_DISPLAY_NAME, $data['display_name'] ?? $name);
            $this->getStorageConfig()->setData(StorageConfig::fields_DRIVER, $data['driver'] ?? 'local');
            $this->getStorageConfig()->setData(StorageConfig::fields_STATUS, (int) ($data['status'] ?? 1));
            
            $driverConfig = [];
            $driver = $data['driver'] ?? 'local';
            
            switch ($driver) {
                case 'local':
                    $driverConfig = [
                        'root_path' => $data['config_root_path'] ?? (PUB . 'media'),
                        'base_url' => $data['config_base_url'] ?? '/pub/media',
                    ];
                    break;
                    
                case 's3':
                    $driverConfig = [
                        'key' => $data['config_key'] ?? '',
                        'secret' => $data['config_secret'] ?? '',
                        'region' => $data['config_region'] ?? 'us-east-1',
                        'bucket' => $data['config_bucket'] ?? '',
                        'prefix' => $data['config_prefix'] ?? '',
                        'endpoint' => $data['config_endpoint'] ?? '',
                        'use_path_style_endpoint' => (bool) ($data['config_use_path_style_endpoint'] ?? false),
                    ];
                    break;
                    
                case 'oss':
                    $driverConfig = [
                        'access_key_id' => $data['config_access_key_id'] ?? '',
                        'access_key_secret' => $data['config_access_key_secret'] ?? '',
                        'endpoint' => $data['config_endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com',
                        'bucket' => $data['config_bucket'] ?? '',
                        'prefix' => $data['config_prefix'] ?? '',
                        'use_ssl' => (bool) ($data['config_use_ssl'] ?? true),
                    ];
                    break;
            }
            
            $this->getStorageConfig()->setConfigArray($driverConfig);
            $this->getStorageConfig()->setData(StorageConfig::fields_UPDATED_AT, \date('Y-m-d H:i:s'));
            
            $this->getStorageConfig()->save(true);
            
            if (!empty($data['is_default'])) {
                $this->getStorageConfig()->setAsDefault();
            }
            
            $this->getStorageManager()->reload();
            
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功'),
                'data' => ['id' => $this->getStorageConfig()->getId()],
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('保存失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
    
    /**
     * 删除配置
     */
    public function postDelete()
    {
        try {
            $id = (int) $this->request->getPost('id');
            
            $this->getStorageConfig()->reset()
                ->where(StorageConfig::fields_CONFIG_ID, $id)
                ->find()
                ->fetch();
            
            if (!$this->getStorageConfig()->getId()) {
                return $this->fetchJson(['code' => 404, 'msg' => __('配置不存在')]);
            }
            
            $this->getStorageConfig()->delete();
            $this->getStorageManager()->reload();
            
            return $this->fetchJson(['code' => 200, 'msg' => __('删除成功')]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('删除失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
    
    /**
     * 测试连接
     */
    public function postTest()
    {
        try {
            $data = $this->request->getPost();
            $driver = $data['driver'] ?? 'local';
            
            $config = [];
            switch ($driver) {
                case 'local':
                    $config = [
                        'root_path' => $data['config_root_path'] ?? (PUB . 'media'),
                        'base_url' => $data['config_base_url'] ?? '/pub/media',
                    ];
                    break;
                    
                case 's3':
                    $config = [
                        'key' => $data['config_key'] ?? '',
                        'secret' => $data['config_secret'] ?? '',
                        'region' => $data['config_region'] ?? 'us-east-1',
                        'bucket' => $data['config_bucket'] ?? '',
                        'endpoint' => $data['config_endpoint'] ?? '',
                    ];
                    break;
                    
                case 'oss':
                    $config = [
                        'access_key_id' => $data['config_access_key_id'] ?? '',
                        'access_key_secret' => $data['config_access_key_secret'] ?? '',
                        'endpoint' => $data['config_endpoint'] ?? 'oss-cn-hangzhou.aliyuncs.com',
                        'bucket' => $data['config_bucket'] ?? '',
                    ];
                    break;
            }
            
            $success = $this->getStorageManager()->testConfig($driver, $config);
            
            if ($success) {
                return $this->fetchJson(['code' => 200, 'msg' => __('连接测试成功')]);
            } else {
                return $this->fetchJson(['code' => 400, 'msg' => __('连接测试失败，请检查配置')]);
            }
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('测试失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
    
    /**
     * 设为默认
     */
    public function postSetDefault()
    {
        try {
            $id = (int) $this->request->getPost('id');
            
            $this->getStorageConfig()->reset()
                ->where(StorageConfig::fields_CONFIG_ID, $id)
                ->find()
                ->fetch();
            
            if (!$this->getStorageConfig()->getId()) {
                return $this->fetchJson(['code' => 404, 'msg' => __('配置不存在')]);
            }
            
            $this->getStorageConfig()->setAsDefault();
            $this->getStorageManager()->reload();
            
            return $this->fetchJson(['code' => 200, 'msg' => __('设置成功')]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => __('设置失败：%{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
