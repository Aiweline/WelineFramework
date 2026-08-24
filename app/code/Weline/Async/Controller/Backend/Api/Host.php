<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Controller\Backend\Api;

use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Async\Model\SyncHost;
use Weline\Async\Service\SyncService;

class Host extends BackendRestController
{
    private SyncHost $syncHost;
    private SyncService $syncService;

    public function __construct(ObjectManager $objectManager)
    {
        parent::__construct();
        $this->syncHost = $objectManager->getInstance(SyncHost::class);
        $this->syncService = $objectManager->getInstance(SyncService::class);
    }

    /**
     * 获取主机列表
     */
    public function getList()
    {
        $hosts = $this->syncHost->clear()
            ->order(SyncHost::schema_fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        $data = [];
        foreach ($hosts as $host) {
            $data[] = [
                'host_id' => $host->getId(),
                'name' => $host->getData(SyncHost::schema_fields_NAME),
                'host' => $host->getData(SyncHost::schema_fields_HOST),
                'port' => $host->getData(SyncHost::schema_fields_PORT),
                'user' => $host->getData(SyncHost::schema_fields_USER),
                'description' => $host->getData(SyncHost::schema_fields_DESCRIPTION),
                'created_at' => $host->getData(SyncHost::schema_fields_CREATED_AT),
            ];
        }

        return $this->success('获取成功', $data);
    }

    /**
     * 获取主机详情
     */
    public function get()
    {
        $id = $this->request->getParam('id');
        if (empty($id)) {
            return $this->error('主机ID不能为空');
        }

        $host = $this->syncHost->clear()->load($id);
        if (!$host->getId()) {
            return $this->error('主机不存在');
        }

        $data = [
            'host_id' => $host->getId(),
            'name' => $host->getData(SyncHost::schema_fields_NAME),
            'host' => $host->getData(SyncHost::schema_fields_HOST),
            'port' => $host->getData(SyncHost::schema_fields_PORT),
            'user' => $host->getData(SyncHost::schema_fields_USER),
            'key_path' => $host->getData(SyncHost::schema_fields_KEY_PATH), // 已废弃，使用 key_content
            'has_key' => !empty($host->getData(SyncHost::schema_fields_KEY_CONTENT)),
            'description' => $host->getData(SyncHost::schema_fields_DESCRIPTION),
            'created_at' => $host->getData(SyncHost::schema_fields_CREATED_AT),
        ];

        return $this->success('获取成功', $data);
    }

    /**
     * 保存主机
     */
    public function save()
    {
        try {
            $id = $this->request->getParam('id');
            $name = trim($this->request->getParam('name', ''));
            $host = trim($this->request->getParam('host', ''));
            $port = (int)($this->request->getParam('port') ?: 22);
            $user = trim($this->request->getParam('user', ''));
            $password = $this->request->getParam('password', '');
            $keyPath = trim($this->request->getParam('key_path', '')); // 临时文件路径，用于读取
            $keyContent = trim($this->request->getParam('key_content', '')); // 直接输入的密钥内容
            $description = trim($this->request->getParam('description', ''));

            // 验证必填字段
            if (empty($name)) {
                return $this->error('主机名称不能为空');
            }
            if (empty($host)) {
                return $this->error('主机地址不能为空');
            }
            if (empty($user)) {
                return $this->error('SSH用户名不能为空');
            }
            
            // 处理密钥内容：优先使用直接输入的内容，其次从文件路径读取
            $finalKeyContent = '';
            if (!empty($keyContent)) {
                $finalKeyContent = $keyContent;
            } elseif (!empty($keyPath) && file_exists($keyPath) && is_readable($keyPath)) {
                $finalKeyContent = file_get_contents($keyPath);
                if ($finalKeyContent === false) {
                    return $this->error('无法读取密钥文件');
                }
            }
            
            if (empty($password) && empty($finalKeyContent)) {
                // 编辑模式下，如果已有密码或密钥，可以不提供
                if ($id) {
                    $existingHost = $this->syncHost->clear()->load($id);
                    if ($existingHost->getId()) {
                        $existingPassword = $existingHost->getData(SyncHost::schema_fields_PASSWORD);
                        $existingKeyContent = $existingHost->getData(SyncHost::schema_fields_KEY_CONTENT);
                        if (empty($existingPassword) && empty($existingKeyContent)) {
                            return $this->error('SSH密码或密钥至少需要提供一个');
                        }
                    } else {
                        return $this->error('SSH密码或密钥至少需要提供一个');
                    }
                } else {
                    return $this->error('SSH密码或密钥至少需要提供一个');
                }
            }

            $hostModel = $this->syncHost->clear();
            
            if ($id) {
                $hostModel->load($id);
                if (!$hostModel->getId()) {
                    return $this->error('主机不存在');
                }
            }

            $hostModel->setData(SyncHost::schema_fields_NAME, $name);
            $hostModel->setData(SyncHost::schema_fields_HOST, $host);
            $hostModel->setData(SyncHost::schema_fields_PORT, $port);
            $hostModel->setData(SyncHost::schema_fields_USER, $user);
            
            if (!empty($password)) {
                $hostModel->setData(SyncHost::schema_fields_PASSWORD, $password);
            }
            
            // 保存密钥内容（如果提供了）
            if (!empty($finalKeyContent)) {
                $hostModel->setData(SyncHost::schema_fields_KEY_CONTENT, $finalKeyContent);
            }
            
            // 临时保存密钥路径（用于读取，保存后会被清除）
            if (!empty($keyPath)) {
                $hostModel->setData(SyncHost::schema_fields_KEY_PATH, $keyPath);
            }
            
            if (!empty($description)) {
                $hostModel->setData(SyncHost::schema_fields_DESCRIPTION, $description);
            }

            $hostModel->save();

            return $this->success('保存成功', ['host_id' => $hostModel->getId()]);
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除主机
     */
    public function delete()
    {
        try {
            $id = $this->request->getParam('id');
            if (empty($id)) {
                return $this->error('主机ID不能为空');
            }

            $host = $this->syncHost->clear()->load($id);
            if (!$host->getId()) {
                return $this->error('主机不存在');
            }

            // 检查是否有映射使用该主机
            $mappings = $host->getMappings();
            if (!empty($mappings)) {
                return $this->error('该主机下还有目录映射，请先删除映射');
            }

            $host->delete();
            return $this->success('删除成功');
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 测试SSH连接
     */
    public function testConnection()
    {
        try {
            $id = $this->request->getParam('id');
            if (empty($id)) {
                return $this->error('主机ID不能为空');
            }

            $host = $this->syncHost->clear()->load($id);
            if (!$host->getId()) {
                return $this->error('主机不存在');
            }

            $result = $this->syncService->testSshConnection($host);
            
            if ($result['success']) {
                return $this->success($result['message'], $result);
            } else {
                return $this->error($result['message'], $result);
            }
            
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
