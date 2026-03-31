<?php

declare(strict_types=1);

namespace WeShop\Search\Controller\Backend\Engine;

use WeShop\Search\Model\SearchEngineConfig;
use WeShop\Search\Service\SearchEngineFactory;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

class Index extends BackendController
{
    public function index(): string
    {
        $scope = $this->request->getParam('scope', 'default');

        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);

        $scopes = $configModel->getAllScopes();
        if ($scopes === []) {
            $scopes = ['default'];
        }

        $configs = $configModel->getConfigByScope($scope, false);
        $availableEngines = SearchEngineFactory::getAvailableEngines();

        $this->assign('scope', $scope);
        $this->assign('scopes', $scopes);
        $this->assign('configs', $configs);
        $this->assign('availableEngines', $availableEngines);
        $this->assign('title', __('搜索引擎配置管理'));

        // 显式指定真实模板路径：view/templates/Backend/Engine/index.phtml
        return (string) $this->fetch('WeShop_Search::templates/Backend/Engine/index.phtml');
    }

    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请使用 POST 方法提交'),
            ]);
        }

        $engineType = (string) $this->request->getPost('engine_type');
        $scope = (string) $this->request->getPost('scope', 'default');
        $isActive = (bool) $this->request->getPost('is_active', 0);
        $priority = (int) $this->request->getPost('priority', 0);
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

        if (!$result) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('配置保存失败'),
            ]);
        }

        $this->getMessageManager()->addSuccess(__('配置保存成功'));

        return $this->fetchJson([
            'success' => true,
            'message' => __('配置保存成功'),
        ]);
    }

    public function test(): string
    {
        $engineType = (string) $this->request->getParam('engine_type');
        $scope = (string) $this->request->getParam('scope', 'default');

        if ($engineType === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请指定搜索引擎类型'),
            ]);
        }

        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);
        $config = $configModel->getActiveEngineConfig($scope);

        if ($config && ($config[SearchEngineConfig::schema_fields_ENGINE_TYPE] ?? '') === $engineType) {
            $configData = $configModel->setData($config)->getConfigData();
        } else {
            $configData = $this->getEngineConfigData($engineType);
        }

        if ($configData === null) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先配置搜索引擎参数'),
            ]);
        }

        $engine = SearchEngineFactory::createEngineByType($engineType);
        if (!$engine) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无法创建搜索引擎实例'),
            ]);
        }

        $engine->initConfig($configData);
        $testResult = $engine->testConnection();

        return $this->fetchJson([
            'success' => $testResult,
            'message' => $testResult ? __('连接测试成功') : __('连接测试失败，请检查配置'),
        ]);
    }

    public function delete(): string
    {
        $configId = (int) $this->request->getParam('id');
        if ($configId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的配置 ID'),
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

    public function form(): string
    {
        $configId = (int) $this->request->getParam('id');
        $scope = (string) $this->request->getParam('scope', 'default');

        /** @var SearchEngineConfig $configModel */
        $configModel = ObjectManager::getInstance(SearchEngineConfig::class);

        $config = null;
        if ($configId > 0) {
            $config = $configModel->load($configId)->getData();
        }

        $availableEngines = SearchEngineFactory::getAvailableEngines();
        $scopes = $configModel->getAllScopes();
        if ($scopes === []) {
            $scopes = ['default'];
        }

        $this->assign('config', $config);
        $this->assign('scope', $scope);
        $this->assign('scopes', $scopes);
        $this->assign('availableEngines', $availableEngines);
        $this->assign('title', $config ? __('编辑搜索引擎配置') : __('新增搜索引擎配置'));

        // 显式指定真实模板路径：view/templates/Backend/Engine/form.phtml
        return (string) $this->fetch('WeShop_Search::templates/Backend/Engine/form.phtml');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getEngineConfigData(string $engineType): ?array
    {
        return match ($engineType) {
            SearchEngineConfig::ENGINE_MEILISEARCH => [
                'host' => (string) $this->request->getPost('host', 'http://127.0.0.1:7700'),
                'api_key' => (string) $this->request->getPost('api_key', ''),
                'index_name' => (string) $this->request->getPost('index_name', 'products'),
            ],
            SearchEngineConfig::ENGINE_MYSQL => [],
            SearchEngineConfig::ENGINE_OPENSEARCH => [
                'host' => (string) $this->request->getPost('host', 'http://127.0.0.1'),
                'port' => (int) $this->request->getPost('port', 9200),
                'index' => (string) $this->request->getPost('index', 'products'),
                'username' => (string) $this->request->getPost('username', ''),
                'password' => (string) $this->request->getPost('password', ''),
                'timeout' => (int) $this->request->getPost('timeout', 5),
                'version' => (string) $this->request->getPost('version', '3.5.0'),
                'install_dir' => (string) $this->request->getPost('install_dir', 'extend/server/opensearch'),
                'config_file' => (string) $this->request->getPost('config_file', 'extend/server/opensearch/config/opensearch.yml'),
            ],
            SearchEngineConfig::ENGINE_ELASTICSEARCH => [
                'host' => (string) $this->request->getPost('host', 'http://127.0.0.1'),
                'port' => (int) $this->request->getPost('port', 9200),
                'index' => (string) $this->request->getPost('index', 'products'),
                'username' => (string) $this->request->getPost('username', ''),
                'password' => (string) $this->request->getPost('password', ''),
                'timeout' => (int) $this->request->getPost('timeout', 5),
            ],
            SearchEngineConfig::ENGINE_ALGOLIA => [
                'application_id' => (string) $this->request->getPost('application_id', ''),
                'api_key' => (string) $this->request->getPost('api_key', ''),
                'index_name' => (string) $this->request->getPost('index_name', 'products'),
            ],
            default => null,
        };
    }
}
