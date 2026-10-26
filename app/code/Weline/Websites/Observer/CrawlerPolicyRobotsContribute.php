<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Server\Security\CrawlerBlockCatalog;
use Weline\Websites\Api\Crawler\CrawlerPolicyProviderInterface;

/**
 * Adds per-bot block groups to Weline_Seo robots.txt from the website policy.
 */
final class CrawlerPolicyRobotsContribute implements ObserverInterface
{
    public function __construct(
        private readonly CrawlerPolicyProviderInterface $policyProvider,
    ) {
    }

    public function execute(Event &$event): void
    {
        $bag = $event->getData('data');
        if (!$bag instanceof DataObject) {
            return;
        }
        $websiteId = (int)$bag->getData('website_id');
        if ($websiteId < 0 || !(bool)$bag->getData('robots_enabled')) {
            return;
        }
        if (!$this->policyProvider->isEnabled($websiteId)) {
            return;
        }

        $global = $bag->getData('global_lines');
        $global = \is_array($global) ? $global : [];
        $entries = $this->policyProvider->entriesForWebsite($websiteId);

        $allowTokens = [];
        foreach ($entries as $entry) {
            if (!$entry->enabled || $entry->action !== 'allow') {
                continue;
            }
            foreach ($entry->uaTokens as $token) {
                $allowTokens[\strtolower(\trim($token))] = true;
            }
        }
        if (isset($allowTokens['google-extended'])) {
            $bag->setData('suppress_google_extended', true);
        }

        foreach ($entries as $entry) {
            if (!$entry->enabled || $entry->action !== 'block') {
                continue;
            }
            $token = \trim($entry->robotsToken);
            if ($token === '' || \preg_match('/[\r\n"]/', $token) === 1) {
                continue;
            }
            if (isset($allowTokens[\strtolower($token)])) {
                continue;
            }
            // WLS hard floor: friendly search/social/AI bots are never blocked.
            if (CrawlerBlockCatalog::isAllowPass($token, [])) {
                continue;
            }
            $global[] = 'User-agent: ' . $token;
            $global[] = 'Disallow: /';
        }

        $bag->setData('global_lines', $global);
    }
}
