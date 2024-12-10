<?php

namespace Weline\Backend\Controller;

use Weline\Backend\Model\BackendUserData;
use Weline\Framework\App\Controller\BackendController;

class UserData extends BackendController
{
    /**
     * @var \Weline\Backend\Model\BackendUserData
     */
    private BackendUserData $backendUserData;

    public function __construct(
        BackendUserData $backendUserData
    ) {
        $this->backendUserData = $backendUserData;
    }

    public function index(): string
    {
        if ($this->request->isGet()) {
            $scope = $this->request->getGet('scope');
            $user_id = $this->session->getLoginUserID();
            # 读取数据
            $backendUserData = $this->backendUserData
                ->where(BackendUserData::fields_scope, $scope)
                ->where(BackendUserData::fields_BACKEND_USER_ID, $user_id)
                ->find()
                ->fetch();
            $data = json_decode($backendUserData->getData(BackendUserData::fields_JSON) ?? '') ?? [];
            return $this->fetchJson([
                'code' => 200,
                'msg' => '获取成功',
                'data' => $data]);
        }
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'code' => 403,
                'msg' => '保存失败',
                'data' => []]);
        }
        $scope = $this->request->getPost('scope');
        $name = $this->request->getPost('name');
        $value = $this->request->getPost('value');
        $user_id = $this->session->getLoginUserID();
        # 读取数据
        $backendUserData = $this->backendUserData
            ->where(BackendUserData::fields_scope, $scope)
            ->where(BackendUserData::fields_BACKEND_USER_ID, $user_id)
            ->find()
            ->fetch();
        # 如果数据库没有数据，则创建
        if (!$backendUserData->getId()) {
            $backendUserData->setData(BackendUserData::fields_scope, $scope)
                ->setData(BackendUserData::fields_BACKEND_USER_ID, $user_id);
        }
        # 数据库中的数据
        $json = json_decode($backendUserData->getData(BackendUserData::fields_JSON) ?? '', true) ?? [];
        # 设置数据
        $json[$name] = $value;
        $backendUserData->setData(BackendUserData::fields_JSON, json_encode($json));
        $backendUserData->save(true);
        $backendUserData->unsetData(BackendUserData::fields_BACKEND_USER_ID);
        $data = $backendUserData->getData();
        $json_format = json_decode($data['json']);
        return $this->fetchJson([
            'code' => 200,
            'msg' => '保存成功',
            'data' => $data,
            'json' => $json_format
        ]);
    }
}
