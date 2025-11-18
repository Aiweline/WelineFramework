<?php

namespace Weline\Taglib\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Taglib\Model\UserScope;

class Scope extends BackendController
{
    public function index()
    {
        if ($this->request->isPost()) {
            $scope = $this->request->getBodyParam('scope');
            $data = $this->request->getBodyParam('data');
            if (empty($scope) || empty($data)) {
                return $this->fetchJson([
                    'code' => 400,
                    'msg' => __('参数缺失'),
                ]);
            }
            $user_id = $this->session->getLoginUserID();
            // 查找或新建
            $userScope = w_obj(UserScope::class)
                ->where(UserScope::fields_USER_ID, $user_id)
                ->where(UserScope::fields_SCOPE, $scope)
                ->find()    
                ->fetch();
            if (!$userScope->getId()) {
                $userScope->setData(UserScope::fields_USER_ID, $user_id)
                    ->setData(UserScope::fields_SCOPE, $scope);
            }
            // 支持json字符串或数组 
            if (is_string($data)) {
                $json = $data;
            } else {
                $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            $userScope->setData(UserScope::fields_DATA, $json);
            $userScope->save(true);
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功'),
                'data'=>$userScope->getData(),
                'json'=>$userScope->getData(UserScope::fields_DATA)?json_decode($userScope->getData(UserScope::fields_DATA)??'{}'):[]
            ]);
        }
        // GET: 获取scope数据
        if ($this->request->isGet()) {
            $scope = $this->request->getGet('scope');
            $user_id = $this->session->getLoginUserID();
            $userScope = w_obj(UserScope::class)
                ->where(UserScope::fields_USER_ID, $user_id)
                ->where(UserScope::fields_SCOPE, $scope)
                ->find()
                ->fetch();
            $json = $userScope->getData(UserScope::fields_DATA);
            $jsonArr = $json ? json_decode($json, true) : [];
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('获取成功！'),
                'data' => [
                    'scope' => $scope,
                    'json' => $json,
                    'user_scope_id' => $userScope->getId(),
                ],
                'json' => $jsonArr,
            ]);
        }
    }
}
