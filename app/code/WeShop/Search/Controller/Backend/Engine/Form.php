<?php

declare(strict_types=1);

namespace WeShop\Search\Controller\Backend\Engine;

use WeShop\Search\Model\SearchEngineConfig;
use WeShop\Search\Service\SearchEngineFactory;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

class Form extends BackendController
{
    /**
     * 渲染新增/编辑搜索引擎配置表单
     */
    public function index(): string
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

        return (string) $this->fetch('WeShop_Search::templates/Backend/Engine/form.phtml');
    }
}

