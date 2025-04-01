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

use Weline\Framework\App\Exception;

class Data extends \Weline\Backend\Model\Config
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

    private array $smtp = [];

    function get(string $key = '', string $module = 'Weline_Smtp'): string|array
    {
        if (isset($this->smtp[$module])) {
            if ($key) {
                return $this->smtp[$module][$key] ?? '';
            } else {
                return $this->smtp[$module];
            }
        }
        $items = $this->systemConfig->where('module', $module, '=', 'and')->where('key', self::keys, '=', 'or')->select()->fetch()->getItems();
        foreach ($items as $item) {
            $this->smtp[$module][$item->getKey()] = $item->getData('v');
        }

        if ($key) {
            return $this->smtp[$module][$key] ?? '';
        }
        foreach (self::keys as $key) {
            if (!isset($this->smtp[$module][$key])) {
                $this->smtp[$module][$key] = '';
            }
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
                    throw new \Weline\Framework\App\Exception(__('配置项不齐全%1', $key));
                }
            }
            return $this;
        }
        $this->setConfig($key, $data, $module);
        return $this;
    }
}