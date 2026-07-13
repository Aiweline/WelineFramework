<?php

declare(strict_types=1);

namespace Weline\Smtp\Api;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Helper\SmtpSender;

final class MailSenderInterfaceFactory implements FactoryObjectInterface
{
    public function create(): MailSenderInterface
    {
        return ObjectManager::getInstance(SmtpSender::class);
    }
}
