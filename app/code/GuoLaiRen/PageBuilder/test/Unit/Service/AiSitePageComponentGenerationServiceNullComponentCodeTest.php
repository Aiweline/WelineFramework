<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;

final class AiSitePageComponentGenerationServiceNullComponentCodeTest extends TestCase
{
    public function testStructuralDetectionAcceptsMissingComponentCodeWithoutTypeError(): void
    {
        $service = new AiSitePageComponentGenerationService();

        $reason = (function (): ?string {
            return $this->detectStructuralGeneratedSectionHtmlReason(
                '<section class="pb-about-story"><div class="pb-about-story-card"><h2>Our story</h2><p>Built for riders who need proven gear, clear support, and dependable delivery.</p></div></section>',
                null
            );
        })->call($service);

        self::assertNull($reason);
    }
}
