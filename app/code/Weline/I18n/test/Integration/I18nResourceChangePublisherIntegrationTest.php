<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Event\Outbox;
use Weline\I18n\Service\I18nResourceChangePublisher;

final class I18nResourceChangePublisherIntegrationTest extends TestCase
{
    public function testDictionaryPayloadStoresHashesInsteadOfText(): void
    {
        $publisher = ObjectManager::getInstance(I18nResourceChangePublisher::class);
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        $word = 'private-word-' . bin2hex(random_bytes(6));
        $translation = 'private-translation-' . bin2hex(random_bytes(6));
        $previousArea = WelineEnv::getArea();
        WelineEnv::setArea('rest_backend');

        try {
            $transactions->run($publisher->connection(), function () use ($publisher, $word, $translation): void {
                $publisher->publishAction('dictionary-quick-save', [
                    'word' => $word,
                    'locale_code' => 'en_US',
                    'translate' => $translation,
                ]);
                $outbox = ObjectManager::getInstance(Outbox::class, [], false);
                $row = $outbox->clearData()->clearQuery()
                    ->order(Outbox::schema_fields_ID, 'DESC')
                    ->find()->fetch()->getData();
                $payloadJson = (string)($row[Outbox::schema_fields_PAYLOAD_JSON] ?? '');
                self::assertStringNotContainsString($word, $payloadJson);
                self::assertStringNotContainsString($translation, $payloadJson);
                $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('i18n_dictionary', $payload['resource']['type'] ?? null);
                self::assertSame('backend', $payload['context']['area'] ?? null);
                self::assertSame(hash('sha256', $word), $payload['after']['word_sha256'] ?? null);
                self::assertNotContains('translate', $payload['after']['payload_keys'] ?? []);

                throw new I18nResourceChangeRollbackProbe();
            });
        } catch (I18nResourceChangeRollbackProbe) {
            self::addToAssertionCount(1);
        } finally {
            WelineEnv::setArea($previousArea);
        }
    }
}

final class I18nResourceChangeRollbackProbe extends \RuntimeException
{
}
