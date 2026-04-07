<?php
declare(strict_types=1);

namespace Weline\Smtp\test;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use ReflectionClass;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Helper\SmtpSender;

class SmtpSenderConfigTest extends \Weline\Framework\UnitTest\TestCore
{
    public function testCreateMailerReturnsFreshInstanceWithDebugOff(): void
    {
        $sender = new SmtpSender($this->createMock(Data::class));
        $reflection = new ReflectionClass($sender);
        $createMailer = $reflection->getMethod('createMailer');
        $createMailer->setAccessible(true);

        /** @var PHPMailer $mailA */
        $mailA = $createMailer->invoke($sender);
        /** @var PHPMailer $mailB */
        $mailB = $createMailer->invoke($sender);

        self::assertNotSame($mailA, $mailB);
        self::assertSame(SMTP::DEBUG_OFF, $mailA->SMTPDebug);
        self::assertSame('smtp', $mailA->Mailer);
    }

    public function testConfigureTransportRespectsAuthAndEncryption(): void
    {
        $sender = new SmtpSender($this->createMock(Data::class));
        $reflection = new ReflectionClass($sender);
        $createMailer = $reflection->getMethod('createMailer');
        $createMailer->setAccessible(true);
        $configureTransport = $reflection->getMethod('configureTransport');
        $configureTransport->setAccessible(true);

        /** @var PHPMailer $mail */
        $mail = $createMailer->invoke($sender);
        $configureTransport->invoke($sender, $mail, 'smtp.example.com', 'sender@example.com', 'secret', 587, '0', 'tls');

        self::assertSame('smtp.example.com', $mail->Host);
        self::assertSame('sender@example.com', $mail->Username);
        self::assertSame('secret', $mail->Password);
        self::assertSame(587, $mail->Port);
        self::assertFalse($mail->SMTPAuth);
        self::assertSame(PHPMailer::ENCRYPTION_STARTTLS, $mail->SMTPSecure);
    }

    public function testResolveSmtpSecureSupportsExplicitNone(): void
    {
        $sender = new SmtpSender($this->createMock(Data::class));
        $reflection = new ReflectionClass($sender);
        $resolve = $reflection->getMethod('resolveSmtpSecure');
        $resolve->setAccessible(true);

        self::assertSame('', $resolve->invoke($sender, 'none', 25));
        self::assertSame(PHPMailer::ENCRYPTION_SMTPS, $resolve->invoke($sender, 'ssl', 465));
        self::assertSame(PHPMailer::ENCRYPTION_STARTTLS, $resolve->invoke($sender, '0', 587));
    }
}
