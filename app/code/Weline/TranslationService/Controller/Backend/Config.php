<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\TranslationService\Controller\Backend;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\TranslationService\Model\TranslationProvider;
use Weline\TranslationService\Service\ProviderFactory;

/**
 * 翻译服务配置控制器
 */
class Config extends \Weline\Framework\App\Controller\BackendPageController
{
    /**
     * @var TranslationProvider
     */
    private TranslationProvider $providerModel;

    /**
     * @var ProviderFactory
     */
    private ProviderFactory $providerFactory;

    /**
     * 构造函数
     */
    public function __construct(
        TranslationProvider $providerModel,
        ProviderFactory $providerFactory
    ) {
        $this->providerModel = $providerModel;
        $this->providerFactory = $providerFactory;
    }

    /**
     * 显示配置页面
     */
    public function index(): string
    {
        // 获取所有渠道配置
        $providers = $this->providerModel->clear()
            ->order('priority', 'DESC')
            ->select()
            ->fetch();

        $this->assign('providers', $providers);
        return $this->fetch();
    }

    /**
     * 编辑渠道配置
     */
    public function edit(): string
    {
        $providerId = (int)$this->request->getParam('id');
        
        if ($providerId) {
            $provider = $this->providerModel->clear()->load($providerId);
            if (!$provider->getId()) {
                $this->getMessageManager()->addError(__('渠道不存在'));
                return $this->redirect($this->getBackendUrl('*/backend/config'));
            }
        } else {
            $provider = $this->providerModel->clear();
        }

        $this->assign('provider', $provider);
        return $this->fetch();
    }

    /**
     * 保存渠道配置
     */
    public function save(): string
    {
        if (!$this->request->isPost()) {
            $this->getMessageManager()->addError(__('仅支持POST请求'));
            return $this->redirect($this->getBackendUrl('*/backend/config'));
        }

        try {
            $data = $this->request->getPost();
            $providerId = isset($data['provider_id']) ? (int)$data['provider_id'] : 0;

            if ($providerId) {
                $provider = $this->providerModel->clear()->load($providerId);
                if (!$provider->getId()) {
                    throw new Exception(__('渠道不存在'));
                }
            } else {
                $provider = $this->providerModel->clear();
                // 新建渠道时，需要设置provider_code
                if (isset($data['provider_code']) && !empty($data['provider_code'])) {
                    $provider->setData(TranslationProvider::schema_fields_PROVIDER_CODE, $data['provider_code']);
                } else {
                    // 如果没有提供，从渠道名称生成
                    $providerCode = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $data['provider_name'] ?? ''));
                    $provider->setData(TranslationProvider::schema_fields_PROVIDER_CODE, $providerCode);
                }
                $provider->setData(TranslationProvider::schema_fields_CREATED_AT, date('Y-m-d H:i:s'));
            }

            // 保存数据
            $provider->setData(TranslationProvider::schema_fields_PROVIDER_NAME, $data['provider_name'] ?? '');
            $provider->setData(TranslationProvider::schema_fields_API_KEY, $data['api_key'] ?? '');
            $provider->setData(TranslationProvider::schema_fields_API_SECRET, $data['api_secret'] ?? '');
            $provider->setData(TranslationProvider::schema_fields_API_ENDPOINT, $data['api_endpoint'] ?? '');
            $provider->setData(TranslationProvider::schema_fields_IS_ENABLED, isset($data['is_enabled']) ? 1 : 0);
            $provider->setData(TranslationProvider::schema_fields_IS_DEFAULT, isset($data['is_default']) ? 1 : 0);
            $provider->setData(TranslationProvider::schema_fields_PRIORITY, isset($data['priority']) ? (int)$data['priority'] : 0);
            $provider->setData(TranslationProvider::schema_fields_RATE_LIMIT, isset($data['rate_limit']) ? (int)$data['rate_limit'] : null);
            $provider->setData(TranslationProvider::schema_fields_DAILY_LIMIT, isset($data['daily_limit']) ? (int)$data['daily_limit'] : null);
            $provider->setData(TranslationProvider::schema_fields_COST_PER_CHARACTER, isset($data['cost_per_character']) ? (float)$data['cost_per_character'] : 0);
            $provider->setData(TranslationProvider::schema_fields_DESCRIPTION, $data['description'] ?? '');
            $provider->setData(TranslationProvider::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));

            // 处理支持的语言列表
            if (isset($data['supported_languages']) && is_array($data['supported_languages'])) {
                $provider->setSupportedLanguages($data['supported_languages']);
            }

            // 处理额外配置
            if (isset($data['config']) && is_array($data['config'])) {
                $provider->setConfig($data['config']);
            }

            // 如果设置为默认渠道，取消其他渠道的默认状态
            if (isset($data['is_default']) && $data['is_default']) {
                $this->providerModel->clear()
                    ->where(TranslationProvider::schema_fields_IS_DEFAULT, 1)
                    ->setData(TranslationProvider::schema_fields_IS_DEFAULT, 0)
                    ->save();
            }

            $provider->save();

            $this->getMessageManager()->addSuccess(__('配置保存成功！'));
        } catch (Exception $e) {
            $this->getMessageManager()->addError(__('保存失败：%{1}', [$e->getMessage()]));
        }

        return $this->redirect($this->getBackendUrl('*/backend/config'));
    }

    /**
     * 测试连接
     */
    public function test(): string
    {
        $providerId = (int)$this->request->getParam('id');
        
        if (!$providerId) {
            return $this->json(['success' => false, 'message' => __('渠道ID不能为空')]);
        }

        $provider = $this->providerModel->clear()->load($providerId);
        if (!$provider->getId()) {
            return $this->json(['success' => false, 'message' => __('渠道不存在')]);
        }

        try {
            $adapter = $this->providerFactory->create($provider->getData(TranslationProvider::schema_fields_PROVIDER_CODE));
            if (!$adapter) {
                return $this->json(['success' => false, 'message' => __('未找到渠道适配器')]);
            }

            $result = $adapter->testConnection($provider);
            if ($result) {
                return $this->json(['success' => true, 'message' => __('连接测试成功')]);
            } else {
                return $this->json(['success' => false, 'message' => __('连接测试失败')]);
            }
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => __('测试失败：%{1}', [$e->getMessage()])]);
        }
    }

    /**
     * 删除渠道配置
     */
    public function delete(): string
    {
        $providerId = (int)$this->request->getParam('id');
        
        if (!$providerId) {
            $this->getMessageManager()->addError(__('渠道ID不能为空'));
            return $this->redirect($this->getBackendUrl('*/backend/config'));
        }

        try {
            $provider = $this->providerModel->clear()->load($providerId);
            if (!$provider->getId()) {
                throw new Exception(__('渠道不存在'));
            }

            $provider->delete();
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } catch (Exception $e) {
            $this->getMessageManager()->addError(__('删除失败：%{1}', [$e->getMessage()]));
        }

        return $this->redirect($this->getBackendUrl('*/backend/config'));
    }
}
