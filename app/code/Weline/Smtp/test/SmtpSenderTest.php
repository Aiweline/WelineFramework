<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/11/5 01:00:35
 */

namespace Weline\Smtp\test;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Helper\SmtpSender;

class SmtpSenderTest extends \Weline\Framework\UnitTest\TestCore
{
    private Data $data;

    function setUp(): void
    {
        parent::setUp();
        $this->data = ObjectManager::getInstance(Data::class);
    }

    function testSmtpSender()
    {
        /**@var SmtpSender $smtpSender */
        $smtpSender = ObjectManager::getInstance(SmtpSender::class);
        $condition = $smtpSender->sender(
            ['email' => 'Aiweline@qq.com', 'name' => '发送者'],
            ['email' => 'Aiweline@qq.com', 'name' => '接收者'],
            'WelineFramework 框架Smtp测试！',
            'WelineFramework 框架Smtp测试！这只是一个测试邮件。'
        );
        self::assertTrue($condition, '邮件发送');
    }
}