<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale\Dictionary;
use Weline\I18n\Service\AiTranslationPublisher;

final class AiTranslationPublisherTransactionTest extends TestCase
{
    public function testLanguageFileIsNotReadOrPublishedInsideUncommittedTransaction(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, 'instances');
        $original = $property->getValue();
        try {
            $connection = $this->createMock(ConnectionFactory::class);
            $dictionary = $this->getMockBuilder(Dictionary::class)->disableOriginalConstructor()
                ->onlyMethods(['getConnection', 'clear'])->getMock();
            $dictionary->method('getConnection')->willReturn($connection);
            $dictionary->expects(self::never())->method('clear');
            $transactions = $this->createMock(TransactionCoordinatorInterface::class);
            $transactions->method('isActive')->with($connection)->willReturn(true);
            $transactions->expects(self::once())->method('afterCommit')
                ->with($connection, 'i18n.publish_locale.en_US', self::isType('callable'));
            ObjectManager::setInstance(TransactionCoordinatorInterface::class, $transactions);

            self::assertTrue((new AiTranslationPublisher($dictionary))->publishLocale('en-US'));
        } finally {
            $property->setValue(null, $original);
        }
    }
}
