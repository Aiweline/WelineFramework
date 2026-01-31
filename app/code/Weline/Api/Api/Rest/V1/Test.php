<?php

declare(strict_types=1);

namespace Weline\Api\Api\Rest\V1;

use Weline\Api\Model\SandboxTest;
use Weline\Framework\App\Controller\FrontendRestController;

/**
 * API测试控制器
 * 
 * 用于测试API文档导入功能
 */
class Test extends FrontendRestController
{
    /**
     * 获取测试信息
     * 
     * 这是一个测试接口，用于验证API文档导入功能是否正常工作
     * 
     * @param string $name 测试名称（可选，默认"test"，通过方法签名参数获取）
     * @param int $count 测试数量（可选，默认1，通过方法签名参数获取）
     * @return string 返回JSON字符串格式：{"code": "0", "msg": "success", "data": {"name": "test", "count": "1", "timestamp": "1234567890"}}
     * @throws \Exception 参数错误时抛出异常
     * @Document(summary='获取测试信息', description='这是一个测试接口，用于验证API文档导入功能是否正常工作。返回测试名称、数量和当前时间戳。', tags=['测试', 'API文档'], category='测试接口')
     * @example
     * Method: GET
     * Path: /api/rest/v1/weline-api/test/getInfo
     * Request Parameters:
     * - name: test (可选，默认"test")
     * - count: 1 (可选，默认1)
     * Response:
     * {
     *   "code": "0",
     *   "msg": "success",
     *   "data": {
     *     "name": "test",
     *     "count": "1",
     *     "timestamp": "1234567890"
     *   }
     * }
     * @example-end
     */
    public function getInfo(string $name = 'test', int $count = 1): string
    {
        if ($count < 0) {
            return $this->error(__('测试数量不能为负数'), '', 400);
        }
        
        return $this->success(__('API文档导入测试成功'), [
            'name' => $name,
            'count' => $count,
            'timestamp' => time()
        ]);
    }
    
    /**
     * 创建测试数据
     * 
     * 用于测试POST请求的API文档导入功能
     * 
     * @param string $title 测试标题（必填，通过POST参数或Body参数获取）
     * @param string $content 测试内容（必填，通过POST参数或Body参数获取）
     * @param array $tags 标签列表（可选，通过POST参数或Body参数获取）
     * @return string 返回JSON字符串格式：{"code": "0", "msg": "创建成功", "data": {"id": "1", "title": "测试标题", "content": "测试内容"}}
     * @throws \Exception 创建失败时抛出异常
     * @Document(summary='创建测试数据', description='用于测试POST请求的API文档导入功能。需要提供测试标题和内容，可选标签列表。', tags=['测试', 'API文档', '创建'], category='测试接口')
     * @example
     * Method: POST
     * Path: /api/rest/v1/weline-api/test/create
     * Header:
     * - Content-Type: application/json
     * - Authorization: Bearer token_here
     * Body:
     * {
     *   "title": "测试标题",
     *   "content": "测试内容",
     *   "tags": ["测试", "API"]
     * }
     * Response:
     * {
     *   "code": "0",
     *   "msg": "创建成功",
     *   "data": {
     *     "id": "1",
     *     "title": "测试标题",
     *     "content": "测试内容",
     *     "tags": ["测试", "API"]
     *   }
     * }
     * @example-end
     */
    public function postCreate(): string
    {
        // 优先从请求体获取（支持JSON），如果没有则从POST获取
        $title = trim($this->request->getBodyParam('title') ?? $this->request->getPost('title') ?? '');
        $content = trim($this->request->getBodyParam('content') ?? $this->request->getPost('content') ?? '');
        $tags = $this->request->getBodyParam('tags') ?? $this->request->getPost('tags') ?? [];
        
        if (empty($title)) {
            return $this->error(__('测试标题不能为空'), '', 400);
        }
        
        if (empty($content)) {
            return $this->error(__('测试内容不能为空'), '', 400);
        }
        
        // 确保 tags 是数组
        if (!is_array($tags)) {
            $tags = [];
        }
        
        // 模拟创建数据
        $data = [
            'id' => time(),
            'title' => $title,
            'content' => $content,
            'tags' => $tags,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->success(__('创建成功'), $data);
    }
    
    /**
     * 获取测试列表
     * 
     * 用于测试分页查询的API文档导入功能
     * 
     * @param int $page 页码（可选，默认1，通过GET参数获取）
     * @param int $pageSize 每页数量（可选，默认20，通过GET参数获取）
     * @param string $keyword 搜索关键词（可选，通过GET参数获取）
     * @return string 返回JSON字符串格式：{"code": "0", "msg": "success", "data": {"list": [], "total": "0", "page": "1", "pageSize": "20"}}
     * @throws \Exception 查询失败时抛出异常
     * @Document(summary='获取测试列表', description='用于测试分页查询的API文档导入功能。支持分页和关键词搜索。', tags=['测试', 'API文档', '列表'], category='测试接口')
     * @example
     * Method: GET
     * Path: /api/rest/v1/weline-api/test/getList
     * Request Parameters:
     * - page: 1 (可选，默认1)
     * - pageSize: 20 (可选，默认20)
     * - keyword: test (可选)
     * Response:
     * {
     *   "code": "0",
     *   "msg": "success",
     *   "data": {
     *     "list": [
     *       {
     *         "id": "1",
     *         "title": "测试标题",
     *         "content": "测试内容"
     *       }
     *     ],
     *     "total": "1",
     *     "page": "1",
     *     "pageSize": "20"
     *   }
     * }
     * @example-end
     */
    public function getList(int $page = 1, int $pageSize = 20, string $keyword = ''): string
    {
        if ($page < 1) {
            $page = 1;
        }
        
        if ($pageSize < 1 || $pageSize > 100) {
            $pageSize = 20;
        }
        
        // 模拟数据
        $list = [
            [
                'id' => 1,
                'title' => '测试标题1',
                'content' => '测试内容1'
            ],
            [
                'id' => 2,
                'title' => '测试标题2',
                'content' => '测试内容2'
            ]
        ];
        
        // 关键词过滤
        if (!empty($keyword)) {
            $list = array_filter($list, function($item) use ($keyword) {
                return str_contains($item['title'] ?? '', $keyword) || 
                       str_contains($item['content'] ?? '', $keyword);
            });
        }
        
        return $this->success('success', [
            'list' => array_values($list),
            'total' => count($list),
            'page' => $page,
            'pageSize' => $pageSize
        ]);
    }
    
    /**
     * 沙盒测试接口 - 创建测试数据
         *
         * 用于测试沙盒数据库功能，验证数据是否写入正确的数据库
         *
         * @param string $name 测试名称（必填，通过POST参数或Body参数获取）
         * @param string $content 测试内容（必填，通过POST参数或Body参数获取）
     * @return string 返回JSON字符串格式：{"code": "0", "msg": "success", "data": {"id": "1", "name": "test", "content": "test content", "environment": "sandbox"}}
     * @throws \Exception 创建失败时抛出异常
     * @Document(summary='沙盒测试-创建数据', description='用于测试沙盒数据库功能。创建测试数据并返回，可以验证数据是否写入沙盒数据库。', tags=['测试', '沙盒', '数据库'], category='测试接口')
     * @example
     * Method: POST
     * Path: /api/v1/test/sandbox/create
     * Header:
     * - Content-Type: application/json
     * - Authorization: Bearer token_here
     * Body:
     * {
     *   "name": "沙盒测试数据",
     *   "content": "这是沙盒测试数据的内容"
     * }
     * Response:
     * {
     *   "code": "0",
     *   "msg": "创建成功",
     *   "data": {
     *     "id": "1",
     *     "name": "沙盒测试数据",
     *     "content": "这是沙盒测试数据的内容",
     *     "environment": "sandbox",
     *     "created_at": "2024-01-01 12:00:00"
     *   }
     * }
     * @example-end
     */
    public function postSandboxCreate(): string
    {
        try {
            // 优先从请求体获取（支持JSON），如果没有则从POST获取
            $name = trim($this->request->getBodyParam('name') ?? $this->request->getPost('name') ?? '');
            $content = trim($this->request->getBodyParam('content') ?? $this->request->getPost('content') ?? '');
            
            if (empty($name)) {
                return $this->error(__('测试名称不能为空'), '', 400);
            }
            
            if (empty($content)) {
                return $this->error(__('测试内容不能为空'), '', 400);
            }
            
            // 检测当前环境
            // production-mode-switch 关闭状态（productionMode=false）应该使用沙盒数据库
            // 框架的 SANDBOX 常量在 App.php 中根据 sandbox 参数设置
            // 数据库连接的选择由框架根据 SANDBOX 常量自动处理
            $sandboxParam = $this->request->getParam('sandbox');
            $isSandbox = false;
            // 使用 SANDBOX 常量判断（框架已根据 sandbox 参数设置）
            if (defined('SANDBOX')) {
                $isSandbox = SANDBOX;
            } elseif ($sandboxParam !== null) {
                // 如果 SANDBOX 常量未定义，但有 sandbox 参数，也认为是沙盒模式
                $isSandbox = true;
            }
            $environment = $isSandbox ? 'sandbox' : 'production';
            
            $model = new SandboxTest();
            $model->setData([
                'name' => $name,
                'content' => $content,
                'environment' => $environment,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            $id = $model->save();
            
            if (!$id) {
                throw new \Exception(__('创建失败'));
            }
            
            $data = $model->clear()->load($id);
            
            return $this->success(__('创建成功'), [
                'id' => $data->getId(0),
                'name' => $data->getName(),
                'content' => $data->getContent(),
                'environment' => $data->getEnvironment(),
                'created_at' => $data->getCreatedAt(),
                'is_sandbox' => $isSandbox,
                'message' => $isSandbox 
                    ? __('数据已写入沙盒数据库') 
                    : __('数据已写入正式数据库')
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 沙盒测试接口 - 获取测试数据列表
         *
         * 用于测试沙盒数据库功能，验证能否正确读取数据
         *
         * @param int $page 页码（可选，默认1）
         * @param int $pageSize 每页数量（可选，默认20）
     * @return string 返回JSON字符串格式：{"code": "0", "msg": "success", "data": {"list": [], "total": "0", "page": "1", "pageSize": "20", "environment": "sandbox"}}
     * @throws \Exception 查询失败时抛出异常
     * @Document(summary='沙盒测试-获取列表', description='用于测试沙盒数据库功能。获取测试数据列表，可以验证数据是否从正确的数据库读取。', tags=['测试', '沙盒', '数据库'], category='测试接口')
     * @example
     * Method: GET
     * Path: /api/v1/test/sandbox/getList?page=1&pageSize=20
     * Response:
     * {
     *   "code": "0",
     *   "msg": "success",
     *   "data": {
     *     "list": [
     *       {"id": "1", "name": "测试数据1", "content": "内容1", "environment": "sandbox"},
     *       {"id": "2", "name": "测试数据2", "content": "内容2", "environment": "sandbox"}
     *     ],
     *     "total": "2",
     *     "page": "1",
     *     "pageSize": "20",
     *     "environment": "sandbox"
     *   }
     * }
     * @example-end
     */
    public function getSandboxList(
        int $page = 1,
        int $pageSize = 20
    ): string {
        try {
            // 检测当前环境
            // production-mode-switch 关闭状态（productionMode=false）应该使用沙盒数据库
            // 数据库连接的选择由框架根据 SANDBOX 常量自动处理
            $sandboxParam = $this->request->getParam('sandbox');
            $isSandbox = false;
            // 使用 SANDBOX 常量判断（框架已根据 sandbox 参数设置）
            if (defined('SANDBOX')) {
                $isSandbox = SANDBOX;
            } elseif ($sandboxParam !== null) {
                // 如果 SANDBOX 常量未定义，但有 sandbox 参数，也认为是沙盒模式
                $isSandbox = true;
            }
            $environment = $isSandbox ? 'sandbox' : 'production';
            
            $model = new SandboxTest();
            
            // 查询总数
            $total = $model->clear()->count();
            
            // 分页查询
            $offset = ($page - 1) * $pageSize;
            $list = $model->clear()
                ->order('id', 'DESC')
                ->limit($pageSize, $offset)
                ->select()
                ->fetchArray();
            
            $dataList = [];
            foreach ($list as $item) {
                $dataList[] = [
                    'id' => $item['id'] ?? 0,
                    'name' => $item['name'] ?? '',
                    'content' => $item['content'] ?? '',
                    'environment' => $item['environment'] ?? 'unknown',
                    'created_at' => $item['created_at'] ?? '',
                    'updated_at' => $item['updated_at'] ?? ''
                ];
            }
            
            return $this->success('success', [
                'list' => $dataList,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'environment' => $environment,
                'is_sandbox' => $isSandbox,
                'message' => $isSandbox 
                    ? __('数据来自沙盒数据库') 
                    : __('数据来自正式数据库')
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
    
    /**
     * 沙盒测试接口 - 删除测试数据
         *
         * 用于测试沙盒数据库功能，验证能否正确删除数据
         *
         * @param int $id 数据ID（必填，通过URL参数、GET参数或Body参数获取）
     * @return string 返回JSON字符串格式：{"code": "0", "msg": "删除成功", "data": {"id": "1"}}
     * @throws \Exception 删除失败时抛出异常
     * @Document(summary='沙盒测试-删除数据', description='用于测试沙盒数据库功能。删除指定的测试数据，可以验证数据是否从正确的数据库删除。', tags=['测试', '沙盒', '数据库'], category='测试接口')
     * @example
     * Method: DELETE
     * Path: /api/v1/test/sandbox/delete?id=1
     * Response:
     * {
     *   "code": "0",
     *   "msg": "删除成功",
     *   "data": {
     *     "id": "1"
     *   }
     * }
     * @example-end
     */
    public function deleteSandboxDelete(): string
    {
        try {
            // 从请求中获取ID（优先从URL参数获取，然后从GET参数，最后从Body参数）
            $id = (int)($this->request->getParam('id') ?? $this->request->getGet('id') ?? $this->request->getBodyParam('id') ?? 0);
            
            if ($id <= 0) {
                return $this->error(__('数据ID不能为空'), '', 400);
            }
            
            // 检测当前环境
            // production-mode-switch 关闭状态（productionMode=false）应该使用沙盒数据库
            // 数据库连接的选择由框架根据 SANDBOX 常量自动处理
            $sandboxParam = $this->request->getParam('sandbox');
            $isSandbox = false;
            // 使用 SANDBOX 常量判断（框架已根据 sandbox 参数设置）
            if (defined('SANDBOX')) {
                $isSandbox = SANDBOX;
            } elseif ($sandboxParam !== null) {
                // 如果 SANDBOX 常量未定义，但有 sandbox 参数，也认为是沙盒模式
                $isSandbox = true;
            }
            $environment = $isSandbox ? 'sandbox' : 'production';
            
            $model = new SandboxTest();
            $result = $model->clear()->where(SandboxTest::fields_ID, $id)->delete();
            
            if (!$result) {
                throw new \Exception(__('删除失败，数据不存在'));
            }
            
            return $this->success(__('删除成功'), [
                'id' => $id,
                'environment' => $environment,
                'is_sandbox' => $isSandbox,
                'message' => $isSandbox 
                    ? __('数据已从沙盒数据库删除') 
                    : __('数据已从正式数据库删除')
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}

