<?php

declare(strict_types=1);

namespace WeShop\QA\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\QA\Service\QAService;

class QAServiceTest extends TestCase
{
    public function testQuestionTargetUrlIsRelativeAndAnchoredToQuestion(): void
    {
        $service = new QAService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildQuestionTargetUrl');
        $method->setAccessible(true);

        $this->assertSame(
            '/qa/frontend/qa?product_id=672&question_id=5#qa-question-5',
            $method->invoke($service, 672, 5)
        );
    }
}
