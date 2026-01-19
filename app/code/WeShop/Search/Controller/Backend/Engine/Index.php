<?php

declare(strict_types=1);

namespace WeShop\Search\Controller\Backend\Engine;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Search\Model\SearchEngineConfig;
use WeShop\Search\Service\SearchEngineFactory;
use WeShop\Search\Api\SearchEngineInterface;

/**
 * 搜索引擎配置管理控制器
 */
class Index extends BackendController
{
    /**
     * 搜索引擎配置列表
     */
    public function index(): string
    {
        $scope = $this->request->getParam('scope', 'default');
        
        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
        
        // 获取所有scope
        $scopes = $configModel->getAllScopes();
        if (empty($scopes)) {
            $scopes = ['default'];
        }
        
        // 获取当前scope的配置
        $configs = $configModel->getConfigByScope($scope, false);
        
        // 获取所有可用的搜索引擎
        $availableEngines = SearchEngineFactory::getAvailableEngines();
        
        $this->assign('scope', $scope);
        $this->assign('scopes', $scopes);
        $this->assign('configs', $configs);
        $this->assign('availableEngines', $availableEngines);
        $this->assign('title', __('搜索引擎配置管理'));
        
        return $this->fetch();
    }
    
    /**
     * 保存搜索引擎配置
     */
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请使用POST方法提交'),
            ]);
        }
        
        $engineType = $this->request->getPost('engine_type');
        $scope = $this->request->getPost('scope', 'default');
        $isActive = (bool)$this->request->getPost('is_active', 0);
        $priority = (int)$this->request->getPost('priority', 0);
        
        // 获取引擎特定的配置
        $configData = $this->getEngineConfigData($engineType);
        
        if ($configData === null) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的搜索引擎类型'),
            ]);
        }
        
        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
        
        $result = $configModel->saveConfig($engineType, $scope, $configData, $isActive, $priority);
        
        if ($result) {
            $this->getMessageManager()->addSuccess(__('配置保存成功'));
            return $this->fetchJson([
                'success' => true,
                'message' => __('配置保存成功'),
            ]);
        } else {
            return $this->fetchJson([
                'success' => false,
                'message' => __('配置保存失败'),
            ]);
        }
    }
    
    /**
     * 测试搜索引擎连接
     */
    public function test(): string
    {
        $engineType = $this->request->getParam('engine_type');
        $scope = $this->request->getParam('scope', 'default');
        
        if (empty($engineType)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请指定搜索引擎类型'),
            ]);
        }
        
        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
        $config = $configModel->getActiveEngineConfig($scope);
        
        if (!$config || $config[SearchEngineConfig::fields_ENGINE_TYPE] !== $engineType) {
            // 如果没有配置，尝试从POST获取配置数据
            $configData = $this->getEngineConfigData($engineType);
            if ($configData === null) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('请先配置搜索引擎参数'),
                ]);
            }
        } else {
            $configData = $configModel->setData($config)->getConfigData();
        }
        
        // 创建引擎实例并测试
        $engine = SearchEngineFactory::create($scope);
        if (!$engine) {
            $engine = SearchEngineFactory::createEngineByType($engineType);
            if ($engine) {
                $engine->initConfig($configData);
            }
        }
        
        if (!$engine) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无法创建搜索引擎实例'),
            ]);
        }
        
        $testResult = $engine->testConnection();
        
        return $this->fetchJson([
            'success' => $testResult,
            'message' => $testResult ? __('连接测试成功') : __('连接测试失败，请检查配置'),
        ]);
    }
    
    /**
     * 删除配置
     */
    public function delete(): string
    {
        $configId = (int)$this->request->getParam('id');
        
        if ($configId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的配置ID'),
            ]);
        }
        
        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
        $config = $configModel->load($configId);
        
        if (!$config->getId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('配置不存在'),
            ]);
        }
        
        $config->delete();
        
        $this->getMessageManager()->addSuccess(__('配置删除成功'));
        return $this->fetchJson([
            'success' => true,
            'message' => __('配置删除成功'),
        ]);
    }
    
    /**
     * 获取引擎配置数据
     * 
     * @param string $engineType
     * @return array|null
     */
    private function getEngineConfigData(string $engineType): ?array
    {
        $configData = [];
        
        switch ($engineType) {
            case SearchEngineConfig::ENGINE_MYSQL:
                // MySQL不需要额外配置
                $configData = [];
                break;
                
            case SearchEngineConfig::ENGINE_ELASTICSEARCH:
                $configData = [
                    'host' => $this->request->getPost('host', 'localhost'),
                    'port' => (int)$this->request->getPost('port', 9200),
                    'index' => $this->request->getPost('index', 'products'),
                    'username' => $this->request->getPost('username', ''),
                    'password' => $this->request->getPost('password', ''),
                ];
                break;
                
            case SearchEngineConfig::ENGINE_ALGOLIA:
                $configData = [
                    'application_id' => $this->request->getPost('application_id', ''),
                    'api_key' => $this->request->getPost('api_key', ''),
                    'index_name' => $this->request->getPost('index_name', 'products'),
                ];
                break;
                
            default:
                return null;
        }
        
        return $configData;
    }
    
    /**
     * 获取配置表单数据
     */
    public function form(): string
    {
        $configId = (int)$this->request->getParam('id');
        $scope = $this->request->getParam('scope', 'default');
        
        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
        
        $config = null;
        if ($configId > 0) {
            $config = $configModel->load($configId)->getData();
        }
        
        // 获取所有可用的搜索引擎
        $availableEngines = SearchEngineFactory::getAvailableEngines();
        
        // 获取所有scope
        $scopes = $configModel->getAllScopes();
        if (empty($scopes)) {
            $scopes = ['default'];
        }
        
        $this->assign('config', $config);
        $this->assign('scope', $scope);
        $this->assign('scopes', $scopes);
        $this->assign('availableEngines', $availableEngines);
        $this->assign('title', $config ? __('编辑搜索引擎配置') : __('新增搜索引擎配置'));
        
        return $this->fetch();
    }
}
