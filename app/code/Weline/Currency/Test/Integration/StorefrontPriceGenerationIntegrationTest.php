<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Integration;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Model\Currency;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;

final class StorefrontPriceGenerationIntegrationTest extends TestCase
{
    public function testCurrencySaveAdvancesPriceGenerationAndRollsBackWithBusinessWrite(): void
    {
        $currency = ObjectManager::getInstance(Currency::class, [], false);
        $code = $this->unusedCurrencyCode();
        $repository = ObjectManager::getInstance(NamespaceGenerationRepository::class);
        $path = ObjectManager::getInstance(NamespacePath::class)->global('storefront', ['price']);
        $before = $this->generation($repository, $path);
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);

        try {
            $transactions->run($currency->getConnection(), function () use (
                $currency,
                $code,
                $repository,
                $path,
                $before,
            ): void {
                $currency->setCode($code)
                    ->setName('Price probe')
                    ->setRate(1.0)
                    ->setSymbol('$')
                    ->setPosition('left')
                    ->setFormat('2,0')
                    ->setStatus(true)
                    ->setThousandSeparator(',')
                    ->setDecimalSeparator('.')
                    ->setBaseCurrency('CNY')
                    ->save();
                self::assertTrue($currency->hasData(Currency::schema_fields_ID));
                $repository->clearSnapshot();
                self::assertGreaterThan($before, $this->generation($repository, $path));
                throw new StorefrontPriceGenerationRollbackProbe();
            });
            self::fail('Rollback probe must escape the owning transaction.');
        } catch (StorefrontPriceGenerationRollbackProbe) {
        }

        $repository->clearSnapshot();
        self::assertSame($before, $this->generation($repository, $path));
        $reloaded = ObjectManager::getInstance(Currency::class, [], false)
            ->where(Currency::schema_fields_CODE, $code)
            ->find()->fetch();
        self::assertFalse($reloaded->hasData(Currency::schema_fields_ID));
    }

    public function testNormalCurrencySaveRollsBackBusinessAndGenerationWhenAfterHookFails(): void
    {
        $code = $this->unusedCurrencyCode();
        $repository = ObjectManager::getInstance(NamespaceGenerationRepository::class);
        $path = ObjectManager::getInstance(NamespacePath::class)->global('storefront', ['price']);
        $before = $this->generation($repository, $path);
        $currency = ObjectManager::getInstance(PriceAfterSaveFailureCurrency::class, [], false);

        try {
            $this->fillCurrency($currency, $code)->save();
            self::fail('The failing after hook must escape save().');
        } catch (StorefrontPriceAfterHookProbe) {
        }

        $repository->clearSnapshot();
        self::assertSame($before, $this->generation($repository, $path));
        $reloaded = ObjectManager::getInstance(Currency::class, [], false)
            ->where(Currency::schema_fields_CODE, $code)
            ->find()->fetch();
        self::assertFalse($reloaded->hasData(Currency::schema_fields_ID));
    }

    public function testNormalCurrencyDeleteFailureRollsBackOwnerTransactionWithoutPersistentGeneration(): void
    {
        $code = $this->unusedCurrencyCode();
        $repository = ObjectManager::getInstance(NamespaceGenerationRepository::class);
        $path = ObjectManager::getInstance(NamespacePath::class)->global('storefront', ['price']);
        $repository->clearSnapshot();
        $baseline = $this->generation($repository, $path);
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        $connection = ObjectManager::getInstance(Currency::class, [], false)->getConnection();
        $currencyId = 0;

        try {
            $transactions->run($connection, function () use ($code, &$currencyId): void {
                $created = $this->fillCurrency(
                    ObjectManager::getInstance(Currency::class, [], false),
                    $code,
                );
                $created->save();
                $currencyId = (int)$created->getData(Currency::schema_fields_ID);
                self::assertGreaterThan(0, $currencyId);

                $probe = ObjectManager::getInstance(PriceAfterDeleteFailureCurrency::class, [], false)
                    ->where(Currency::schema_fields_ID, $currencyId)
                    ->find()->fetch();
                $probe->delete();
            });
            self::fail('The failing after hook must escape delete().');
        } catch (StorefrontPriceAfterHookProbe) {
        }

        self::assertGreaterThan(0, $currencyId);
        $repository->clearSnapshot();
        self::assertSame($baseline, $this->generation($repository, $path));
        $rolledBack = ObjectManager::getInstance(Currency::class, [], false)
            ->where(Currency::schema_fields_ID, $currencyId)
            ->find()->fetch();
        self::assertFalse($rolledBack->hasData(Currency::schema_fields_ID));
    }

    private function generation(NamespaceGenerationRepository $repository, string $path): int
    {
        $vector = $repository->resolveVector([$path]);
        return (int)($vector['generations'][$path] ?? 0);
    }

    private function unusedCurrencyCode(): string
    {
        for ($attempt = 0; $attempt < 32; $attempt++) {
            $bytes = random_bytes(3);
            $code = '';
            for ($index = 0; $index < 3; $index++) {
                $code .= chr(65 + (ord($bytes[$index]) % 26));
            }
            $existing = ObjectManager::getInstance(Currency::class, [], false)
                ->where(Currency::schema_fields_CODE, $code)
                ->find()->fetch();
            if (!$existing->hasData(Currency::schema_fields_ID)) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not allocate an unused test currency code.');
    }

    private function fillCurrency(Currency $currency, string $code): Currency
    {
        return $currency->setCode($code)
            ->setName('Price probe')
            ->setRate(1.0)
            ->setSymbol('$')
            ->setPosition('left')
            ->setFormat('2,0')
            ->setStatus(true)
            ->setThousandSeparator(',')
            ->setDecimalSeparator('.')
            ->setBaseCurrency('CNY');
    }
}

final class StorefrontPriceGenerationRollbackProbe extends \RuntimeException
{
}

final class StorefrontPriceAfterHookProbe extends \RuntimeException
{
}

final class PriceAfterSaveFailureCurrency extends Currency
{
    public const schema_table = 'w_currency';
    public const schema_primary_key = Currency::schema_fields_ID;

    public function save_after(): void
    {
        parent::save_after();
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        if (!$transactions->isActive($this->getConnection())) {
            throw new \LogicException('Currency save_after must run inside its owner transaction.');
        }
        throw new StorefrontPriceAfterHookProbe();
    }
}

final class PriceAfterDeleteFailureCurrency extends Currency
{
    public const schema_table = 'w_currency';
    public const schema_primary_key = Currency::schema_fields_ID;

    public function delete_after(): void
    {
        parent::delete_after();
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        if (!$transactions->isActive($this->getConnection())) {
            throw new \LogicException('Currency delete_after must run inside its owner transaction.');
        }
        throw new StorefrontPriceAfterHookProbe();
    }
}
