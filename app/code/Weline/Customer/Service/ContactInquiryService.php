<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Model\ContactInquiry;
use Weline\Customer\Model\Customer;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticableInterface;

class ContactInquiryService
{
    public function __construct(
        private readonly ContactInquiry $contactInquiryModel,
        private readonly Request $request
    ) {
    }

    /**
     * @return array{name:string,email:string,phone:string,order_number:string,category:string,subject:string,message:string}
     */
    public function normalizeFormData(array $data): array
    {
        return [
            'name' => trim((string)($data['name'] ?? '')),
            'email' => strtolower(trim((string)($data['email'] ?? ''))),
            'phone' => trim((string)($data['phone'] ?? '')),
            'order_number' => trim((string)($data['order_number'] ?? '')),
            'category' => trim((string)($data['category'] ?? ContactInquiry::CATEGORY_OTHER)),
            'subject' => trim((string)($data['subject'] ?? '')),
            'message' => trim((string)($data['message'] ?? '')),
        ];
    }

    /**
     * @param array{name:string,email:string,phone:string,order_number:string,category:string,subject:string,message:string} $data
     */
    public function submit(array $data, ?AuthenticableInterface $customer = null): ContactInquiry
    {
        if ($data['name'] === '') {
            throw new \InvalidArgumentException((string)__('请填写联系人姓名。'));
        }
        if ($data['email'] === '') {
            throw new \InvalidArgumentException((string)__('请填写联系邮箱。'));
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException((string)__('请输入有效的邮箱地址。'));
        }
        if ($data['subject'] === '') {
            throw new \InvalidArgumentException((string)__('请填写联系主题。'));
        }
        if ($data['message'] === '') {
            throw new \InvalidArgumentException((string)__('请填写问题描述。'));
        }
        if (!isset(ContactInquiry::getCategoryOptions()[$data['category']])) {
            throw new \InvalidArgumentException((string)__('请选择有效的问题分类。'));
        }

        $now = date('Y-m-d H:i:s');
        $sourceUrl = (string)($this->request->getServer('REQUEST_URI') ?: '/customer/contact');
        $userAgent = (string)($this->request->getHeader('User-Agent') ?? '');

        $inquiry = clone $this->contactInquiryModel;
        $inquiry->reset()->clearData();
        $inquiry->setData(ContactInquiry::schema_fields_customer_id, $customer instanceof Customer ? (int)$customer->getId() : null);
        $inquiry->setData(ContactInquiry::schema_fields_name, mb_substr($data['name'], 0, 100));
        $inquiry->setData(ContactInquiry::schema_fields_email, mb_substr($data['email'], 0, 100));
        $inquiry->setData(ContactInquiry::schema_fields_phone, mb_substr($data['phone'], 0, 32));
        $inquiry->setData(ContactInquiry::schema_fields_order_number, mb_substr($data['order_number'], 0, 64));
        $inquiry->setData(ContactInquiry::schema_fields_category, $data['category']);
        $inquiry->setData(ContactInquiry::schema_fields_subject, mb_substr($data['subject'], 0, 150));
        $inquiry->setData(ContactInquiry::schema_fields_message, $data['message']);
        $inquiry->setData(ContactInquiry::schema_fields_status, ContactInquiry::STATUS_NEW);
        $inquiry->setData(ContactInquiry::schema_fields_source_url, mb_substr($sourceUrl, 0, 255));
        $inquiry->setData(ContactInquiry::schema_fields_ip, mb_substr((string)$this->request->clientIP(), 0, 45));
        $inquiry->setData(ContactInquiry::schema_fields_user_agent, mb_substr($userAgent, 0, 255));
        $inquiry->setData(ContactInquiry::schema_fields_created_at, $now);
        $inquiry->setData(ContactInquiry::schema_fields_updated_at, $now);
        $inquiry->save();

        return $inquiry;
    }
}
