<?php

declare(strict_types=1);

namespace Weline\Geo\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Geo\Model\WebsiteProtocolConfig;
use Weline\Geo\Service\Protocol\IndustryProtocolDocuments;

/**
 * Adds RSL License and Content-Signal lines to Weline_Seo robots.txt.
 */
class RobotsProtocolContributor implements ObserverInterface
{
    public function __construct(
        private readonly WebsiteProtocolConfig $protocolConfig
    ) {
    }

    public function execute(Event &$event): void
    {
        $bag = $event->getData('data');
        if (!$bag instanceof DataObject) {
            return;
        }

        $websiteId = (int) $bag->getData('website_id');
        $robotsEnabled = (bool) $bag->getData('robots_enabled');
        $aiInputAllowed = $this->aiInputAllowed($websiteId);
        $contribution = IndustryProtocolDocuments::robotsContribution(
            (string) $bag->getData('base_url'),
            $robotsEnabled,
            $aiInputAllowed
        );

        $global = $this->stringLines($bag->getData('global_lines'));
        $star = $this->stringLines($bag->getData('star_lines'));
        $bag->setData('global_lines', array_merge($global, $contribution['global_lines']));
        $bag->setData('star_lines', array_merge($star, $contribution['star_lines']));
    }

    private function aiInputAllowed(int $websiteId): bool
    {
        if ($websiteId < 0) {
            return true;
        }

        return $this->protocolConfig->loadByWebsiteId($websiteId)->isLlmsEnabled();
    }

    /**
     * @return list<string>
     */
    private function stringLines(mixed $lines): array
    {
        if (!is_array($lines)) {
            return [];
        }

        $clean = [];
        foreach ($lines as $line) {
            if (is_string($line) && $line !== '') {
                $clean[] = $line;
            }
        }

        return $clean;
    }
}
