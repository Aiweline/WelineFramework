<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Controller\Backend;

use Weline\CustomerService\Model\CustomerServiceConfig;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

/**
 * 客服配置管理控制器
 */
#[Acl('Weline_CustomerService::config', '客服配置', 'mdi-cog', '客服配置管理', 'Weline_CustomerService::customer_service')]
class Config extends BackendController
{
    /**
     * 配置页面
     */
    #[Acl('Weline_CustomerService::config_index', '查看客服配置', 'mdi-cog', '查看客服配置')]
    public function index(): string
    {
        try {
            /** @var CustomerServiceConfig $config */
            $config = ObjectManager::getInstance(CustomerServiceConfig::class);
            
            $configs = $config->reset()
                ->select()
                ->fetch()
                ->getItems();

            $configData = [];
            foreach ($configs as $item) {
                $configData[$item['key']] = $item['value'];
            }

            $this->assign('configs', $configData);
            $this->assign('page_title', __('客服配置'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载配置失败：%{1}', $e->getMessage()));
            $this->assign('configs', []);
            return $this->fetch();
        }
    }

    /**
     * 保存配置
     */
    #[Acl('Weline_CustomerService::config_save', '保存客服配置', 'mdi-content-save', '保存客服配置')]
    public function save(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $configs = $this->request->getPost('config', []);

            /** @var CustomerServiceConfig $configModel */
            $configModel = ObjectManager::getInstance(CustomerServiceConfig::class);

            foreach ($configs as $key => $value) {
                $config = clone $configModel;
                $config->reset()
                    ->where(CustomerServiceConfig::fields_key, $key)
                    ->find()
                    ->fetch();

                if ($config->getId()) {
                    $config->setValue($value)
                        ->setData(CustomerServiceConfig::fields_updated_at, date('Y-m-d H:i:s'))
                        ->save();
                } else {
                    $config->reset()
                        ->setKey($key)
                        ->setValue($value)
                        ->setData(CustomerServiceConfig::fields_created_at, date('Y-m-d H:i:s'))
                        ->setData(CustomerServiceConfig::fields_updated_at, date('Y-m-d H:i:s'))
                        ->save();
                }
            }

            return $this->jsonResponse(true, __('保存成功'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存失败：%{1}', $e->getMessage()));
        }
    }

    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}

