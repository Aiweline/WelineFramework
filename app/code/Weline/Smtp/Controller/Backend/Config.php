<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/1 21:09:58
 */

namespace Weline\Smtp\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Helper\SmtpSender;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_Smtp::system_smtp_config', 'SMTP 配置', 'mdi-email-send-outline', 'SMTP 邮件服务配置', 'Weline_Smtp::system_smtp')]
class Config extends BackendController
{
    /**
     * @var \Weline\Smtp\Helper\Data
     */
    private Data $data;

    function __construct(Data $data)
    {
        $this->data = $data;
    }

    #[Acl('Weline_Smtp::smtp_config_index', '配置页', 'mdi-cog', '查看 SMTP 配置', 'Weline_Smtp::system_smtp_config')]
    public function index(): string
    {
        $smtp = $this->data->get();
        $this->assign($smtp);
        return $this->fetch('Weline_Smtp::Backend/Config');
    }

    /** @deprecated 兼容旧路由，重定向到 index */
    public function get(): string
    {
        return $this->index();
    }

    #[Acl('Weline_Smtp::smtp_config_save', '保存配置', 'mdi-content-save', '保存 SMTP 配置', 'Weline_Smtp::system_smtp_config')]
    public function post(): string
    {
        $smtp_configs                = array_intersect_key($this->request->getPost(), array_flip(Data::keys));
        $smtp_configs['smtp_secure'] = '1';
        $smtp_configs['smtp_auth']   = '1';
        $has_error                   = '';
        foreach ($smtp_configs as $key => $config) {
            try {
                $this->data->set($key, $config);
            } catch (Exception $e) {
                $has_error .= $e->getMessage();
            }
        }
        if (empty($has_error)) {
            $this->getMessageManager()->addSuccess(__('Smtp配置成功！为了保证Smtp邮件服务正常工作，请测试确认。'));
        } else {
            $this->getMessageManager()->addError($has_error);
        }
        $this->redirect($this->getBackendUrl('smtp/backend/config'));
    }

    #[Acl('Weline_Smtp::smtp_config_test', '测试发送', 'mdi-send', '测试 SMTP 发送', 'Weline_Smtp::system_smtp_config')]
    public function postTest(): string
    {
        $test_email = $this->request->getPost('smtp_test_address');
        try {
            $this->data->set('smtp_test_address', $test_email);
        } catch (Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect($this->getBackendUrl('smtp/backend/config'));
        }
        /** @var SmtpSender $smtpSender */
        $smtpSender = ObjectManager::getInstance(SmtpSender::class);
        try {
            $smtpSender->sender(
                ['email' => $this->data->get($this->data::smtp_username), 'name' => '发送者'],
                ['email' => $test_email, 'name' => '接收者'],
                'WelineFramework 框架Smtp测试！',
                'WelineFramework 框架Smtp测试！这只是一个测试邮件。'
            );
            $this->getMessageManager()->addSuccess(__('邮件发送成功！'));
        } catch (\PHPMailer\PHPMailer\Exception|Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        $this->redirect($this->getBackendUrl('smtp/backend/config'));
    }
}