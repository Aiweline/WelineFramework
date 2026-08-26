<?php

declare(strict_types=1);

namespace Weline\Mail\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Mail\Service\MailDnsRecordFactory;

if (!defined('BP')) {
    require dirname(__DIR__, 7) . '/app/bootstrap.php';
}

final class MailDnsRecordFactoryTest extends TestCase
{
    public function testBuildUsesActualDkimAndDnsOnlyOrigin(): void
    {
        $factory = new MailDnsRecordFactory();
        $result = $factory->build(
            'example.com',
            'mail.example.com',
            '8.8.8.8',
            'stalwart',
            str_repeat('Q', 64),
        );

        self::assertSame(['mail.example.com'], $result['dns_only_hosts']);
        $address = array_values(array_filter(
            $result['desired_records'],
            static fn(array $record): bool => $record['type'] === 'A',
        ))[0];
        self::assertFalse($address['proxied']);

        $dkim = array_values(array_filter(
            $result['desired_records'],
            static fn(array $record): bool => str_contains($record['name'], '._domainkey.'),
        ))[0];
        self::assertSame('stalwart._domainkey.example.com', $dkim['name']);
        self::assertStringContainsString('p=' . str_repeat('Q', 64), $dkim['content']);
    }

    public function testPrivateKeyIsRejected(): void
    {
        $factory = new MailDnsRecordFactory();

        $this->expectException(\DomainException::class);
        $factory->build(
            'example.com',
            'mail.example.com',
            '',
            'default',
            '-----BEGIN PRIVATE KEY-----',
        );
    }
}
