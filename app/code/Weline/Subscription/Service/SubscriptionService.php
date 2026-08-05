<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Subscription\Api\SubscriptionFacadeInterface;
use Weline\Subscription\Model\SubscriptionState;

/**
 * Subscription facade: Provider, durable identity/period, ownership and cancel CAS.
 *
 * Scheduler / Order / Payment orchestration belongs to P4B-002.
 */
final class SubscriptionService implements SubscriptionFacadeInterface
{
    public const CAPABILITY = 'subscription';
    public const ERROR_MODE_OFF = 'subscription_mode_off_blocks_mutation';

    public function __construct(
        private readonly SubscriptionProviderRegistry $providers,
        private readonly SubscriptionStore $store,
        private readonly SubscriptionPeriodStore $periods,
        private readonly SubscriptionOwnershipService $ownership,
        private readonly SubscriptionCancelCasService $cancelCas,
        private readonly SubscriptionRolloutGate $rollout,
        private readonly ?DatabaseTransactionRunnerInterface $transactions = null,
    ) {
    }

    public static function forTesting(?SubscriptionRolloutGate $rollout = null): self
    {
        $providers = SubscriptionProviderRegistry::forTesting();
        $store = SubscriptionStore::forTesting();
        $periods = SubscriptionPeriodStore::forTesting();
        $ownership = new SubscriptionOwnershipService($store);
        $cancelCas = new SubscriptionCancelCasService($store, $ownership);
        $gate = $rollout ?? SubscriptionRolloutGate::forTestingConfiguration();
        $gate->setMode(self::CAPABILITY, SubscriptionRolloutGate::MODE_OFF);

        return new self($providers, $store, $periods, $ownership, $cancelCas, $gate);
    }

    public function providers(): SubscriptionProviderRegistry
    {
        return $this->providers;
    }

    public function store(): SubscriptionStore
    {
        return $this->store;
    }

    public function periods(): SubscriptionPeriodStore
    {
        return $this->periods;
    }

    public function ownership(): SubscriptionOwnershipService
    {
        return $this->ownership;
    }

    public function cancelCas(): SubscriptionCancelCasService
    {
        return $this->cancelCas;
    }

    public function rollout(): SubscriptionRolloutGate
    {
        return $this->rollout;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $websiteId = (int) ($input['website_id'] ?? -1);
        SubscriptionState::assertWebsiteId($websiteId);
        $this->assertMutable($websiteId);
        $provider = $this->providers->get((string) ($input['provider_code'] ?? ''));
        $input['provider_code'] = $provider->getCode();

        if ($this->store->isMemory()) {
            return $this->createOnce($input, $provider);
        }

        return $this->transactionRunner()->run(
            $this->store->connection(),
            fn (): array => $this->createOnce($input, $provider),
        );
    }

    /** @return array<string, mixed> */
    public function cancel(string $subscriptionId, string $customerId, int $expectedVersion): array
    {
        $row = $this->store->get($subscriptionId);
        $this->assertMutable((int) $row['website_id']);
        return $this->cancelCas->cancel($subscriptionId, $customerId, $expectedVersion);
    }

    /** @return array<string, mixed> */
    public function get(string $subscriptionId): array
    {
        return $this->store->get($subscriptionId);
    }

    /** @return array<string, mixed> */
    public function assertOwner(string $subscriptionId, string $customerId): array
    {
        return $this->ownership->assertOwner($subscriptionId, $customerId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function createOnce(
        array $input,
        \Weline\Subscription\Api\SubscriptionProviderInterface $provider,
    ): array {
        $created = $this->store->create($input);
        $subscriptionId = (string) $created['subscription_id'];
        if (!empty($created['replayed'])) {
            $periodIndex = max(1, (int) $created['current_period_index']);
            $period = $this->periods->getByKey($provider->periodKey($subscriptionId, $periodIndex));
            return $created + ['period' => $period];
        }

        $periodIndex = 1;
        $period = $this->periods->openPeriod([
            'subscription_id' => $subscriptionId,
            'period_index' => $periodIndex,
            'period_key' => $provider->periodKey($subscriptionId, $periodIndex),
            'website_id' => (int) $created['website_id'],
        ]);
        $created = $this->store->replaceWithVersionBump(
            $subscriptionId,
            (int) $created['version'],
            ['current_period_index' => $periodIndex],
        );
        return $created + ['period' => $period];
    }

    private function assertMutable(int $websiteId): void
    {
        SubscriptionState::assertWebsiteId($websiteId);
        $subject = 'website:' . $websiteId;
        if ($this->rollout->mode(self::CAPABILITY) === SubscriptionRolloutGate::MODE_OFF) {
            throw new SubscriptionConflictException(
                self::ERROR_MODE_OFF,
                __('Subscription capability mode off，禁止写路径'),
                ['capability' => self::CAPABILITY, 'subject' => $subject],
            );
        }
        $this->rollout->assertMutable(self::CAPABILITY, $subject);
    }

    private function transactionRunner(): DatabaseTransactionRunnerInterface
    {
        $runner = $this->transactions
            ?? ObjectManager::getInstance(DatabaseTransactionRunnerInterface::class);
        if (!$runner instanceof DatabaseTransactionRunnerInterface) {
            throw new \LogicException('DatabaseTransactionRunnerInterface is unavailable');
        }
        return $runner;
    }
}
