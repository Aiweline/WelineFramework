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
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/backend/system/config/set?key=' . \Weline\Backend\Config\KeysInterface::key_start_page_path . '&value=1';
        $_SERVER['REQUEST_SCHEME'] = 'http';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $_SERVER['WELINE_USER_LANG'] = 'zh_Hans_CN';


        $res = $url->getBackendUrl('backend/system/config/set?key=' . \Weline\Backend\Config\KeysInterface::key_start_page_path . '&value=1');
        $this->assertEquals('http://localhost/' . Env::get('admin') . '/zh_Hans_CN/backend/system/config/set?key=' . \Weline\Backend\Config\KeysInterface::key_start_page_path . '&value=1', $res);
    }
}
