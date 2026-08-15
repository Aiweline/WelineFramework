<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Controller\FrontendController;

final class FrontendControllerLifecycleContractTest extends TestCase
{
    public function testDocumentedFrontendLifecycleEventsWrapParentInitialization(): void
    {
        $source = (string)file_get_contents((string)(new \ReflectionClass(FrontendController::class))->getFileName());
        $before = strpos($source, "dispatch('Weline_Framework_FrontendController::init_before', \$this)");
        $parent = strpos($source, 'parent::__init();');
        $after = strpos($source, "dispatch('Weline_Framework_FrontendController::init_after', \$this)");

        self::assertIsInt($before);
        self::assertIsInt($parent);
        self::assertIsInt($after);
        self::assertLessThan($parent, $before);
        self::assertLessThan($after, $parent);
    }
}
