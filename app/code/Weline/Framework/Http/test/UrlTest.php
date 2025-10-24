<?php

namespace Weline\Framework\Http\Test;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Url;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;

class UrlTest extends TestCore
{
    public function testUrl()
    {
        /**@var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        # 创造一个j基本$_SERVER
        $key_start_page_path =  \Weline\Backend\Config\KeysInterface::key_start_page_path;
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/backend/system/config/set?key=' . $key_start_page_path . '&value=1';
        $_SERVER['REQUEST_SCHEME'] = 'http';
         $_SERVER['SERVER_PORT'] = '80';
        $res = $url->getBackendUrl('backend/system/config/set?key=' .$key_start_page_path . '&value=1');
        $this->assertEquals('http://localhost/' . Env::get('admin') . '/backend/system/config/set?key=' .$key_start_page_path . '&value=1', $res);

        $_SERVER['SERVER_PORT'] = '8080';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $_SERVER['WELINE_USER_LANG'] = 'zh_Hans_CN';
        $res = $url->getBackendUrl('backend/system/config/set?key=' . $key_start_page_path . '&value=1');
        $this->assertEquals('http://localhost:8080/' . Env::get('admin') . '/zh_Hans_CN/backend/system/config/set?key=' . \Weline\Backend\Config\KeysInterface::key_start_page_path . '&value=1', $res);
        $_SERVER['WELINE_USER_CURRENCY'] = 'CNY';

        $_SERVER['SERVER_PORT'] = '80';
        $res = $url->getBackendUrl('backend/system/config/set?key=' . $key_start_page_path . '&value=1');
        $this->assertEquals('http://localhost/' . Env::get('admin') . '/CNY/zh_Hans_CN/backend/system/config/set?key=' . \Weline\Backend\Config\KeysInterface::key_start_page_path . '&value=1', $res);
        
        $_SERVER['WELINE_WEBSITE_URL'] = 'https://www.aiweline.com';
        $res = $url->getBackendUrl('backend/system/config/set?key=' . $key_start_page_path . '&value=1');
        $this->assertEquals('https://www.aiweline.com/' . Env::get('admin') . '/CNY/zh_Hans_CN/backend/system/config/set?key=' . \Weline\Backend\Config\KeysInterface::key_start_page_path . '&value=1', $res);
    }
}
