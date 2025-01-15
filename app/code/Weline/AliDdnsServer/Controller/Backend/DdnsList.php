<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Weline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/5/10 23:02:49
 */

namespace Weline\AliDdnsServer\Controller\Backend;

use Weline\AliDdnsServer\Model\DdnsDomains;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;

#[Acl('Weline_AliDdnsServer::manager', '阿里云DDns', '', '阿里云动态域名解析管理')]
class DdnsList extends \Weline\Framework\App\Controller\BackendController
{
    /**
     * @var \Weline\AliDdnsServer\Model\DdnsDomains
     */
    private DdnsDomains $ddnsDomains;

    function __construct(DdnsDomains $ddnsDomains)
    {
        $this->ddnsDomains = $ddnsDomains;
    }
    #[Acl('Weline_AliDdnsServer::ddnslist', '动态域名列表', '', '动态域名解析列表','Weline_AliDdnsServer::manager')]
    function index()
    {
        if($search= $this->request->getGet('search')){
            $this->ddnsDomains->where('concat (host_name,domain) like "%'.$search.'%"');
        }
        $items = $this->ddnsDomains->pagination()->select()->fetchArray();
        $this->assign('items',$items);
        $this->assign('pagination',$this->ddnsDomains->getPagination());
        return $this->fetch();
    }
    #[Acl('Weline_AliDdnsServer::ddnslist_add', '添加', '', '添加动态域名解析','Weline_AliDdnsServer::ddnslist')]
    function add()
    {
        // get请求返回表单
        if($this->request->isGet()){
            if($data = $this->session->getData('aliddns_item')){
                $this->assign('ddns',$data);
            }
            return $this->fetch('form');
        }
        $data = $this->request->getPost();
        try {
            $this->ddnsDomains->setData($data)->save(true);
            $this->getMessageManager()->addSuccess(__('添加成功！'));
            $this->session->delete('aliddns_item');
        }catch (\Exception $exception){
            $this->session->setData('aliddns_item', $data);
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/ddnslist/edit',['id'=>$this->ddnsDomains->getId()]);
    }
    #[Acl('Weline_AliDdnsServer::ddnslist_edit', '编辑', '', '编辑动态域名解析','Weline_AliDdnsServer::ddnslist')]
    function edit()
    {

        if($this->request->isGet()){
            $id = $this->request->getGet('id');
            if(empty($id)){
                $this->getMessageManager()->addError(__('你编辑的资源不存在！'));
                $this->redirect('*/backend/ddnslist');
            }
            if($data = $this->session->getData('aliddns_item')){
                $this->assign('ddns',$data);
            }else{
                $this->assign('ddns',$this->ddnsDomains->load($id));
            }
            return $this->fetch('form');
        }

        // 编辑
        $data = $this->request->getPost();
        try {
            unset($data['id']);
            $this->ddnsDomains->setData($data)->save(true);
            $this->getMessageManager()->addSuccess(__('编辑成功！'));
            $this->session->delete('aliddns_item');
        }catch (\Exception $exception){
            $this->session->setData('aliddns_item', $this->request->getPost());
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect('*/backend/ddnslist');
    }

    #[Acl('Weline_AliDdnsServer::ddnslist_delete', '删除', '', '删除动态域名解析','Weline_AliDdnsServer::ddnslist')]
    function getDelete()
    {
        $id = $this->request->getGet('id');
        if(empty($id)){
            $this->getMessageManager()->addError(__('你删除的资源不存在！'));
            $this->redirect('*/backend/ddnslist');
        }
        // 删除
        try {
            $this->ddnsDomains->load($id)->delete();
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } catch (\ReflectionException|Core|Exception $e) {
            $this->getMessageManager()->addException($e);
        }
        $this->redirect('*/backend/ddnslist');
    }
}