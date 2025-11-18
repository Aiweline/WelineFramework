<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Controller\Backend;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Api\Helper\Config as ApiConfig;

/**
 * API模块配置控制器
 */
class Config extends \Weline\Admin\Controller\BaseController
{
    /**
     * @var ApiConfig
     */
    private ApiConfig $apiConfig;

    function __construct(ApiConfig $apiConfig)
    {
        $this->apiConfig = $apiConfig;
    }

    /**
     * 显示配置页面
     * @return string
     */
    public function index(): string
    {
        $config = $this->apiConfig->getAll();
        $this->assign('config', $config);
        return $this->fetch();
    }

    /**
     * 保存配置
     * @return string
     */
    public function save(): string
    {
        if (!$this->request->isPost()) {
            $this->getMessageManager()->addError(__('仅支持POST请求'));
            return $this->redirect($this->getBackendUrl('*/backend/config'));
        }

        try {
            $data = $this->request->getPost();
            
            // 验证和保存配置
            $configData = [];
            if (isset($data['api_token_refresh_period'])) {
                $configData[ApiConfig::API_TOKEN_REFRESH_PERIOD] = (string)(int)$data['api_token_refresh_period'];
            }
            if (isset($data['api_token_refresh_before_expire'])) {
                $configData[ApiConfig::API_TOKEN_REFRESH_BEFORE_EXPIRE] = (string)(int)$data['api_token_refresh_before_expire'];
            }
            if (isset($data['api_token_default_expires_in'])) {
                $configData[ApiConfig::API_TOKEN_DEFAULT_EXPIRES_IN] = (string)(int)$data['api_token_default_expires_in'];
            }
            if (isset($data['api_refresh_token_default_expires_in'])) {
                $configData[ApiConfig::API_REFRESH_TOKEN_DEFAULT_EXPIRES_IN] = (string)(int)$data['api_refresh_token_default_expires_in'];
            }

            if (!empty($configData)) {
                $this->apiConfig->set($configData);
                $this->getMessageManager()->addSuccess(__('配置保存成功！'));
            } else {
                $this->getMessageManager()->addWarning(__('没有需要保存的配置'));
            }
        } catch (Exception $e) {
            $this->getMessageManager()->addError(__('保存失败：%{1}', [$e->getMessage()]));
        }

        return $this->redirect($this->getBackendUrl('*/backend/config'));
    }
}

