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

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Helper\SmtpSender;

class Config extends \Weline\Admin\Controller\BaseController
{
    /**
     * @var \Weline\Smtp\Helper\Data
     */
    private Data $data;

    function __construct(Data $data)
    {
        $this->data = $data;
    }

    function get()
    {
        $smtp = $this->data->get();
        $this->assign($smtp);
        return $this->fetch();
    }

    function post()
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
        $this->redirect($this->_url->getBackendUrl('*/backend/config'));
    }

    function postTest()
    {
        $test_email = $this->request->getPost('smtp_test_address');
        try {
            $this->data->set('smtp_test_address', $test_email);
        } catch (Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect($this->_url->getBackendUrl('*/backend/config'));
        }
        /**@var SmtpSender $smtpSender */
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
        $this->redirect($this->_url->getBackendUrl('*/backend/config'));
    }
}