<?php
declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class SyncSecurityRulesToCdn implements ObserverInterface
{
    public function __construct(
        private readonly Domain $domainModel,
        private readonly RuleManager $ruleManager
    ) {
    }

    public function execute(Event &$event): void
    {
        $mergedRules = (array)$event->getData('merged_rules');
        $pathRateRules = (array)($mergedRules['path_rate_limits']['rules'] ?? []);
        if ($pathRateRules === []) {
            return;
        }

        $domains = $this->domainModel
            ->reset()
            ->where(Domain::fields_ENABLED, 1)
            ->select()
            ->fetch();
        foreach ($domains as $domain) {
            if (!$domain instanceof Domain) {
                continue;
            }
            try {
                $this->ruleManager->pushRules($domain);
            } catch (\Throwable) {
                // 同步失败不影响主流程
            }
        }
    }
}

