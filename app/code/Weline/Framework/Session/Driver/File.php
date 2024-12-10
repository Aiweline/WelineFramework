<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Session\Driver;

use Weline\Framework\DataObject\DataObject;

class File extends DataObject implements SessionDriverHandlerInterface
{
    private function clone()
    {
    }

    private string $sessionPath;
    private array $config;

    /**
     * File 初始函数...
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->config = $config;
        $this->sessionPath = isset($this->config['path']) ? BP . str_replace('/', DS, $this->config['path']) : BP . 'var' . DS . 'session' . DS;
        if (!is_dir($this->sessionPath)) {
            mkdir($this->sessionPath, 0700);
        }
        session_save_path($this->sessionPath);
        ini_set('session.save_handler', 'files');
        ini_set('session.auto_start', '0');
    }

    public function set($name, $value): bool
    {
        $_SESSION[$name] = $value;
        if ($_SESSION[$name]) {
            return true;
        }
        return false;
    }

    public function get($name = null): mixed
    {
        if ($name) {
            return $_SESSION[$name] ?? null;
        }
        return $_SESSION;
    }

    public function delete($name): bool
    {
        unset($_SESSION[$name]);
        return true;
    }


    public function getSessionId(): string
    {
        return session_id();
    }

    /**------------SessionHandleInterface-------------------*/

    public function destroy(string $session_id): bool
    {
        if (file_exists($this->sessionPath . $session_id)) {
            unlink($this->sessionPath . $session_id);
        }
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $now = time();
        foreach (glob($this->sessionPath . '*') as $file) {
            if (filemtime($file) + $max_lifetime < $now) {
                unlink($file);
            }
        }
        return true;
    }

    public function open(string $save_path, string $name): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        if (file_exists($this->sessionPath . $id)) {
            return file_get_contents($this->sessionPath . $id);
        } else {
            $this->write($id, '');
        }
        return false;
    }

    public function write(string $id, string $session_data): bool
    {
        file_put_contents($this->sessionPath . $id, $session_data, 0);
        return true;
    }
}
