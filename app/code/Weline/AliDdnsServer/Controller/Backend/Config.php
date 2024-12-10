<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/5/10 23:05:09
 */

namespace Aiweline\AliDdnsServer\Controller\Backend;

class Config extends \Weline\Framework\App\Controller\BackendController
{
    private \Aiweline\AliDdnsServer\Model\Config $config;

    function __construct(\Aiweline\AliDdnsServer\Model\Config $config)
    {
        $this->config = $config;
    }

    function index()
    {
        if ($this->request->isGet()) {
            $this->assign('accessKeyId', $this->config->get('accessKeyId'));
            $this->assign('accessSecret', $this->config->get('accessSecret'));
            return $this->fetch();
        }
        $accessKeyId  = $this->request->getPost('accessKeyId');
        $accessSecret = $this->request->getPost('accessSecret');
        $this->config->set('accessKeyId', $accessKeyId);
        $this->config->set('accessSecret', $accessSecret);
        $this->getMessageManager()->addSuccess('配置成功！');
        return $this->redirect('*/backend/ddnslist');
    }
}