<?php

declare(strict_types=1);

namespace Weline\Smtp\test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Exception;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Helper\SmtpSender;

final class SmtpSenderTest extends TestCase
{
    public function testSendWithConfigRejectsIncompleteSenderConfiguration(): void
    {
        $data = $this->createMock(Data::class);
        $sender = new SmtpSender($data);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('host');

        $sender->sendWithConfig(
            ['email' => 'from@example.com', 'name' => 'Sender'],
            ['email' => 'to@example.com', 'name' => 'Receiver'],
            'Subject',
            'Body',
            config: [
                'smtp_host' => '',
                'smtp_username' => '',
            ]
        );
    }
}
