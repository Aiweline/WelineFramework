<?php

namespace Weline\DataTable\Api\Rest\V1;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Database\Model;
use Weline\Taglib\Model\UserScope;

class DataTable extends BackendRestController
{
    /**
     * 获取模型所有字段（不返回模板固定字段，固定字段由前端解析）
     * GET /datatable/rest/v1/data-table/fields?model=xxx
     */
   function postFields()
   {
        $model = $this->request->getBodyParam('model');
        $scope = $this->request->getBodyParam('scope');

        $model = str_replace('%5C', '\\', $model);

        $model = ObjectManager::getInstance($model);
        $columns = $model->columns();

        // 获取用户自定义字段配置
        $userScope = ObjectManager::getInstance(UserScope::class);
        $userId = $this->session->getLoginUserID();
        $userConfig = $userScope->getScopeData($userId, 'table_config::'.$scope);
        $userConfig = json_decode($userConfig['data']??'{}', true);
        $displayFields = $userConfig['display_fields'] ?? [];
        $filterFields = $userConfig['filter_fields'] ?? [];
        return $this->success('ok', [
            'all_fields' => $columns,
            'display_fields' => $displayFields,
            'filter_fields' => $filterFields,
        ]);
   }


    /**
     * 保存用户自定义字段配置
     * POST /datatable/rest/v1/data-table/saveConfig
     */
    public function postSaveConfig()
    {
        $scope = $this->request->getBodyParam('scope');
        
        if (empty($scope)) {
            return $this->error(__('缺少scope参数'));
        }
        $config = $this->request->getBodyParam('config', []);
        $displayFields = $config['display_fields'] ?? [];
        $filterFields = $config['filter_fields'] ?? [];
        $userId = $this->session->getLoginUserID();
        $userScope = ObjectManager::getInstance(UserScope::class);
        $userScope->setScopeData($userId, 'table_config::'.$scope, [
            'display_fields' => $displayFields,
            'filter_fields' => $filterFields,
        ]);
        return $this->success(__('保存成功'), [
            'display_fields' => $displayFields,
            'filter_fields' => $filterFields,
        ]);
    }

    /**
     * 字段类型辅助
     */
    private function getFieldType($dbType)
    {
        $type = strtolower($dbType);
        if (strpos($type, 'int') !== false) return 'number';
        if (strpos($type, 'decimal') !== false || strpos($type, 'float') !== false) return 'number';
        if (strpos($type, 'date') !== false) return 'date';
        if (strpos($type, 'time') !== false) return 'date';
        if (strpos($type, 'text') !== false) return 'text';
        if (strpos($type, 'char') !== false) return 'text';
        return 'text';
    }
} 