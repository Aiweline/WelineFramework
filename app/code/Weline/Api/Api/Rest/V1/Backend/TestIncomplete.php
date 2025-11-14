<?php

declare(strict_types=1);

namespace Weline\Api\Api\Rest\V1\Backend;

use Weline\Framework\App\Controller\BackendRestController;

/**
 * 注释不完整的测试API控制器
 * 
 * 用于测试API规范验证功能
 */
class TestIncomplete extends BackendRestController
{
    /**
     * 测试接口 - 缺少参数注释
     * 
     * 这个接口缺少@param注释，应该被验证器拦截
     * 
     * @return array 返回数据格式：{"code": 200, "msg": "success", "data": {}}
     * @Document(summary='测试接口-缺少参数注释', description='这个接口缺少@param注释，应该被验证器拦截', tags=['测试'], category='测试接口')
     * @example
     * Method: POST
     * Path: /{api_admin}/rest/v1/weline_api/backend/test-incomplete/test
     * Body:
     * {
     *   "username": "test",
     *   "password": "test123"
     * }
     * Response:
     * {
     *   "code": 200,
     *   "msg": "success",
     *   "data": {}
     * }
     * @example-end
     */
    public function postTest($username, $password)
    {
        return $this->success(__('测试成功'), [
            'username' => $username,
            'password' => $password
        ]);
    }
    
    /**
     * 测试接口 - 缺少@example注释
     * 
     * @param string $name 名称（必填，通过POST参数获取）
     * @return array 返回数据格式：{"code": 200, "msg": "success", "data": {}}
     * @Document(summary='测试接口', description='缺少@example注释的测试接口', tags=['测试'], category='测试接口')
     */
    public function postTestNoExample($name)
    {
        return $this->success(__('测试成功'), [
            'name' => $name
        ]);
    }
    
    /**
     * 测试接口 - 缺少@Document注释
     * 
     * @param string $id ID（必填，通过POST参数获取）
     * @return array 返回数据格式：{"code": 200, "msg": "success", "data": {}}
     * @example
     * Method: POST
     * Path: /{api_admin}/rest/v1/weline_api/backend/test-incomplete/test-no-document
     * Body:
     * {
     *   "id": "123"
     * }
     * Response:
     * {
     *   "code": 200,
     *   "msg": "success",
     *   "data": {}
     * }
     * @example-end
     */
    public function postTestNoDocument($id)
    {
        return $this->success(__('测试成功'), [
            'id' => $id
        ]);
    }
}

