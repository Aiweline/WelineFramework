<?php

namespace Weline\SessionManager\Session\Driver;

use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Driver\AbstractSessionDriverHandler;
use Weline\SessionManager\Model\Session;

class Mysql extends AbstractSessionDriverHandler
{
    private Session $session;

    public function __construct(array $config)
    {
        $this->session = ObjectManager::getInstance(Session::class);
        parent::__construct($config);
    }

    public function close(): bool
    {
        return true;
    }

    public function destroy(string $id): bool
    {
        try {
            $this->session->reset()->load($id)->delete();
            return true;
        } catch (\ReflectionException|Core|Exception $e) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        // 删除过期的Session
        try {
            $this->session->reset()->where("TIMESTAMPDIFF(SECOND, " . $this->session::fields_UPDATE_TIME . ", NOW()) > $max_lifetime")->delete();
            return true;
        } catch (\ReflectionException|Core|Exception $e) {
            return false;
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return $this->session->reset()->load($id)[$this->session::fields_SESSION_DATA] ?? '';
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->session->reset()->insert([
                $this->session::fields_ID => $id,
                $this->session::fields_SESSION_DATA => $data,
            ], $this->session::fields_ID)->fetch();
            return true;
        } catch (\ReflectionException|Core|Exception $e) {
            return false;
        }
    }
}
