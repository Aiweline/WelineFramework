<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Backend;

use Weline\Customer\Model\ContactInquiry;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Customer::contact', '客户联系表单', 'mdi-card-account-mail-outline', '客户联系表单', 'Weline_Backend::customer_group')]
class Contact extends BackendController
{
    #[Acl('Weline_Customer::contact_index', '查看客户联系表单', 'mdi-format-list-bulleted', '查看客户联系表单')]
    public function index(): string
    {
        try {
            $page = max(1, (int)($this->request->getParam('page') ?? 1));
            $limit = (int)($this->request->getParam('limit') ?? 20);
            $limit = $limit > 0 ? min($limit, 100) : 20;
            $name = trim((string)($this->request->getParam('name') ?? ''));
            $email = trim((string)($this->request->getParam('email') ?? ''));
            $orderNumber = trim((string)($this->request->getParam('order_number') ?? ''));
            $status = trim((string)($this->request->getParam('status') ?? ''));

            /** @var ContactInquiry $model */
            $model = ObjectManager::getInstance(ContactInquiry::class, [], false);
            $query = $model->reset();

            if ($name !== '') {
                $query->where(ContactInquiry::schema_fields_name, '%' . $name . '%', 'LIKE');
            }
            if ($email !== '') {
                $query->where(ContactInquiry::schema_fields_email, '%' . $email . '%', 'LIKE');
            }
            if ($orderNumber !== '') {
                $query->where(ContactInquiry::schema_fields_order_number, '%' . $orderNumber . '%', 'LIKE');
            }
            if ($status !== '' && isset(ContactInquiry::getStatusOptions()[$status])) {
                $query->where(ContactInquiry::schema_fields_status, $status);
            }

            $query->order(ContactInquiry::schema_fields_ID, 'DESC');
            $collection = $query->pagination($page, $limit);
            $items = $collection->select()->fetch()->getItems();
            $total = (int)$collection->getTotal();

            $inquiries = [];
            foreach ($items as $item) {
                $data = is_object($item) ? $item->getData() : (array)$item;
                $inquiries[] = [
                    'inquiry_id' => (int)($data[ContactInquiry::schema_fields_ID] ?? 0),
                    'customer_id' => (int)($data[ContactInquiry::schema_fields_customer_id] ?? 0),
                    'name' => (string)($data[ContactInquiry::schema_fields_name] ?? ''),
                    'email' => (string)($data[ContactInquiry::schema_fields_email] ?? ''),
                    'phone' => (string)($data[ContactInquiry::schema_fields_phone] ?? ''),
                    'order_number' => (string)($data[ContactInquiry::schema_fields_order_number] ?? ''),
                    'category' => (string)($data[ContactInquiry::schema_fields_category] ?? ContactInquiry::CATEGORY_OTHER),
                    'subject' => (string)($data[ContactInquiry::schema_fields_subject] ?? ''),
                    'message' => (string)($data[ContactInquiry::schema_fields_message] ?? ''),
                    'status' => (string)($data[ContactInquiry::schema_fields_status] ?? ContactInquiry::STATUS_NEW),
                    'source_url' => (string)($data[ContactInquiry::schema_fields_source_url] ?? ''),
                    'ip_address' => (string)($data[ContactInquiry::schema_fields_ip] ?? ''),
                    'created_at' => (string)($data[ContactInquiry::schema_fields_created_at] ?? ''),
                    'updated_at' => (string)($data[ContactInquiry::schema_fields_updated_at] ?? ''),
                ];
            }

            $this->assign('inquiries', $inquiries);
            $this->assign('status_options', ContactInquiry::getStatusOptions());
            $this->assign('category_options', ContactInquiry::getCategoryOptions());
            $this->assign('filters', [
                'name' => $name,
                'email' => $email,
                'order_number' => $orderNumber,
                'status' => $status,
            ]);
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('limit', $limit);
            $this->assign('total_pages', (int)ceil($total / $limit));

            return $this->fetch();
        } catch (\Throwable $exception) {
            Message::error(__('加载客户联系表单失败：%{1}', [$exception->getMessage()]));
            $this->assign('inquiries', []);
            $this->assign('status_options', ContactInquiry::getStatusOptions());
            $this->assign('category_options', ContactInquiry::getCategoryOptions());
            $this->assign('filters', [
                'name' => '',
                'email' => '',
                'order_number' => '',
                'status' => '',
            ]);
            $this->assign('total', 0);
            $this->assign('page', 1);
            $this->assign('limit', 20);
            $this->assign('total_pages', 0);

            return $this->fetch();
        }
    }

    #[Acl('Weline_Customer::contact_update_status', '更新客户联系单状态', 'mdi-progress-check', '更新客户联系单状态')]
    public function postUpdateStatus(): string
    {
        $inquiryId = (int)($this->request->getPost('inquiry_id') ?? 0);
        $status = trim((string)($this->request->getPost('status') ?? ''));

        if ($inquiryId <= 0) {
            Message::error(__('无效的联系单ID'));
            return (string)$this->redirect('customer/backend/contact/index');
        }
        if (!isset(ContactInquiry::getStatusOptions()[$status])) {
            Message::error(__('无效的处理状态'));
            return (string)$this->redirect('customer/backend/contact/index');
        }

        /** @var ContactInquiry $model */
        $model = ObjectManager::getInstance(ContactInquiry::class, [], false);
        $model->load($inquiryId);
        if (!$model->getId()) {
            Message::error(__('联系单不存在'));
            return (string)$this->redirect('customer/backend/contact/index');
        }

        $model->setData(ContactInquiry::schema_fields_status, $status);
        $model->setData(ContactInquiry::schema_fields_updated_at, date('Y-m-d H:i:s'));
        $model->save();
        Message::success(__('联系单状态已更新'));

        return (string)$this->redirect('customer/backend/contact/index');
    }

    #[Acl('Weline_Customer::contact_delete', '删除客户联系单', 'mdi-delete', '删除客户联系单')]
    public function postDelete(): string
    {
        $inquiryId = (int)($this->request->getPost('inquiry_id') ?? 0);
        if ($inquiryId <= 0) {
            Message::error(__('无效的联系单ID'));
            return (string)$this->redirect('customer/backend/contact/index');
        }

        /** @var ContactInquiry $model */
        $model = ObjectManager::getInstance(ContactInquiry::class, [], false);
        $model->load($inquiryId);
        if (!$model->getId()) {
            Message::error(__('联系单不存在'));
            return (string)$this->redirect('customer/backend/contact/index');
        }

        $model->delete()->fetch();
        Message::success(__('联系单已删除'));

        return (string)$this->redirect('customer/backend/contact/index');
    }
}
