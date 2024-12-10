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

class Data extends \Weline\Backend\Model\Config
{
    const smtp_host         = 'smtp_host';
    const smtp_auth         = 'smtp_auth';
    const smtp_port         = 'smtp_port';
    const smtp_username     = 'smtp_username';
    const smtp_password     = 'smtp_password';
    const smtp_secure       = 'smtp_secure';
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

    private array $smtp = [];

    function get(string $key = '', string $module = 'Weline_Smtp'): string|array
    {
        if ($this->smtp) {
            if ($key) {
                return $this->smtp[$key] ?? '';
            } else {
                return $this->smtp;
            }
        }
        $items = $this->systemConfig->where('module', $module, '=', 'and')->where('key', self::keys, '=', 'or')->select()->fetch()->getItems();
        foreach ($items as $item) {
            $this->smtp[$item->getKey()] = $item->getData('v');
        }

        if ($key) {
            return $this->smtp[$key] ?? '';
        }
        foreach (self::keys as $key) {
            if (!isset($this->smtp[$key])) {
                $this->smtp[$key] = '';
            }
        }
        return $this->smtp;
    }

    /**
     * @throws \Weline\Framework\App\Exception
     */
    function set(string $key, string $data, string $module = 'Weline_Smtp'): static
    {
        $this->setConfig($key, $data, $module);
        return $this;
    }
}