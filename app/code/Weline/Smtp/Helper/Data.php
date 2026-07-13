<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/1 21:52:30
 */

namespace Weline\Smtp\Helper;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Framework\App\Exception;

class Data extends BackendConfigStore
{
    const smtp_host = 'smtp_host';
    const smtp_auth = 'smtp_auth';
    const smtp_port = 'smtp_port';
    const smtp_username = 'smtp_username';
    const smtp_password = 'smtp_password';
    const smtp_secure = 'smtp_secure';
    const smtp_test_address = 'smtp_test_address';

    const keys = [
        self::smtp_host,
        self::smtp_auth,
        self::smtp_port,
        self::smtp_username,
        self::smtp_password,
        self::smtp_secure,
        self::smtp_test_address,
    ];

    /** 多发件人配置存储 key，值为 JSON 数组 [{ code, name, smtp_host, ... }] */
    const key_smtp_senders = 'smtp_senders';
    /** 发件人 code 对应的默认联系人（收件邮箱）存储 key，值为 JSON 对象 { "code": "to_email" } */
    const key_smtp_sender_contacts = 'smtp_sender_contacts';

    private array $smtp = [];

    function get(string $key = '', string $module = 'Weline_Smtp'): string|array
    {
        if (!isset($this->smtp[$module])) {
            foreach (self::keys as $k) {
                $val = $this->getConfig($k, $module);
                $this->smtp[$module][$k] = $val !== null && $val !== '' ? (string) $val : '';
            }
        }
        if ($key !== '') {
            return $this->smtp[$module][$key] ?? '';
        }
        return $this->smtp[$module];
    }

    /**
     * @throws \Weline\Framework\App\Exception
     */
    function set(string|array $key, string $data = '', string $module = 'Weline_Smtp'): static
    {
        if (is_array($key)) {
            $keys = self::keys;
            $keysOks = [];
            $key['smtp_auth'] = $key['smtp_auth'] ? '1' : '0';
            $key['smtp_secure'] = $key['smtp_secure'] ? '1' : '0';
            $key['smtp_test_address'] = $key['smtp_test_address'] ?? '';
            foreach ($keys as $k) {
                if (isset($key[$k])) {
                    try {
                        $this->set($k, $key[$k], $module);
                        $keysOks[] = $k;
                    } catch (Exception $e) {
                        throw $e;
                    }
                }
            }
            // 比较配置项是否齐全 检测哪个配置项不齐全，报错异常
            foreach ($keys as $key) {
                if (!in_array($key, $keysOks)) {
                    throw new \Weline\Framework\App\Exception(__('配置项不齐全%{1}', $key));
                }
            }
            return $this;
        }
        $this->setConfig($key, $data, $module);
        return $this;
    }

    /**
     * 获取所有发件人配置（含从旧版单配置迁移的 default）
     */
    public function getSenders(string $module = 'Weline_Smtp'): array
    {
        $raw = $this->getConfig(self::key_smtp_senders, $module);
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $legacy = $this->get('', $module);
        if (!empty($legacy['smtp_host']) && !empty($legacy['smtp_username'])) {
            return [
                [
                    'code' => 'default',
                    'name' => __('默认发件人'),
                    'source_type' => 'external',
                    'smtp_host' => $legacy['smtp_host'] ?? '',
                    'smtp_port' => $legacy['smtp_port'] ?? '465',
                    'smtp_username' => $legacy['smtp_username'] ?? '',
                    'smtp_password' => $legacy['smtp_password'] ?? '',
                    'smtp_secure' => $legacy['smtp_secure'] ?? '1',
                    'smtp_auth' => $legacy['smtp_auth'] ?? '1',
                    'smtp_test_address' => $legacy['smtp_test_address'] ?? '',
                ],
            ];
        }
        return [];
    }

    /**
     * 按 code 获取发件人配置
     */
    public function getSenderByCode(string $code, string $module = 'Weline_Smtp'): ?array
    {
        foreach ($this->getSenders($module) as $sender) {
            if (($sender['code'] ?? '') === $code) {
                return $sender;
            }
        }
        return null;
    }

    /**
     * 保存发件人列表（完整覆盖）
     */
    public function setSenders(array $senders, string $module = 'Weline_Smtp'): bool
    {
        $normalized = [];
        foreach ($senders as $sender) {
            if (!is_array($sender)) {
                continue;
            }
            $sender['source_type'] = in_array((string)($sender['source_type'] ?? 'external'), ['external', 'mail_account'], true)
                ? (string)$sender['source_type']
                : 'external';
            $normalized[] = $sender;
        }

        $this->setConfig(self::key_smtp_senders, json_encode($normalized, JSON_UNESCAPED_UNICODE), $module);
        $this->smtp[$module] = [];
        return true;
    }

    /**
     * 获取某发件人 code 的默认联系人（收件邮箱）
     */
    public function getSenderContact(string $senderCode, string $module = 'Weline_Smtp'): string
    {
        $raw = $this->getConfig(self::key_smtp_sender_contacts, $module);
        if ($raw === null || $raw === '') {
            return '';
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        return trim((string) ($decoded[$senderCode] ?? ''));
    }

    /**
     * 设置某发件人 code 的默认联系人（收件邮箱），供 w_query 等调用
     */
    public function setSenderContact(string $senderCode, string $toEmail, string $module = 'Weline_Smtp'): bool
    {
        $raw = $this->getConfig(self::key_smtp_sender_contacts, $module);
        $contacts = ($raw !== null && $raw !== '') && is_array(json_decode((string) $raw, true)) ? json_decode((string) $raw, true) : [];
        $contacts[$senderCode] = $toEmail;
        return (bool) $this->setConfig(self::key_smtp_sender_contacts, json_encode($contacts, JSON_UNESCAPED_UNICODE), $module);
    }
}
