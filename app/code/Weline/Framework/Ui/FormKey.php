<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Ui;

use Weline\Framework\Session\Session;
use Weline\Framework\System\Text;

class FormKey
{
    private Session $_session;
    private string $_key = '';
    private array $_key_paths = [];

    public const key_name       = 'form_key';
    public const form_key_paths = 'form_key_paths';

    public function __construct(
        Session $session
    ) {
        $this->_session = $session;
    }

    public function setKey(string $name = ''): static
    {
        if($name){
            if($this->_session->getData(self::key_name.'_'.$name)){
                return $this;
            }else{
                $this->_session->setData(self::key_name.'_'.$name, Text::rand_str());
                return $this;
            }
        }
        if($this->_key || $this->_session->getData(self::key_name)) {
            return $this;
        }
        $this->_key = Text::rand_str();
        $this->_session->setData(self::key_name, $this->_key);
        return $this;
    }

    public function __sleep()
    {
        return [];
    }

    public function getKey(string $path, string $name = ''): string
    {
        $this->setKey($name);
        $this->_key_paths[] = $path;
        if($name) {
            $this->_key_paths[] = $name;
        }
        $this->_session->setData(self::form_key_paths, implode(',', $this->_key_paths));
        if($name) {
            return $this->_session->getData(self::key_name.'_'.$name);
        }
        return $this->_session->getData(self::key_name);
    }

    public function getHtml(string $path, string $name = ''): string
    {
        return '<input type="hidden" name="form_key"'.($name?' alias="'.$name.'"':'').' value="' . $this->getKey($path, $name) . '"/>';
    }
}
