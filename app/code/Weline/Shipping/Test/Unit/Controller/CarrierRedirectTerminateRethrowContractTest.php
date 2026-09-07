<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * redirect() throws ResponseTerminateException; bare catch (\Throwable) turns it into
 * MessageManager flash "Response terminate with status 302".
 */
final class CarrierRedirectTerminateRethrowContractTest extends TestCase
{
    public function testSaveDeleteToggleRethrowResponseTerminateException(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/Carrier.php');
        self::assertStringContainsString('use Weline\\Framework\\Http\\ResponseTerminateException;', $src);
        self::assertGreaterThanOrEqual(3, substr_count($src, 'catch (ResponseTerminateException $terminate)'));
        self::assertStringContainsString('throw $terminate;', $src);
        self::assertStringNotContainsString(
            "return \$this->redirect('shipping/backend/carrier');\n\n        } catch (\\Throwable",
            $src
        );
    }
}
