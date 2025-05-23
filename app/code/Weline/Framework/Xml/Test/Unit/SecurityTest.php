<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Xml\Test\Unit;

use Weline\Framework\Xml\Security;
use PHPUnit\Framework\TestCase;

/**
 * test for class \Weline\Framework\Xml\Security
 */
class SecurityTest extends TestCase
{
    /**
     * @var Security
     */
    protected $security;

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->security = new Security();
    }

    /**
     * Run test scan method
     *
     * @param string $xmlContent
     * @param bool $expectedResult
     *
     * @dataProvider dataProviderTestScan
     */
    public function testScan($xmlContent, $expectedResult)
    {
        if (empty($xmlContent) || empty($expectedResult)) {
            $this->assertTrue(true, '空数据');
            return;
        }
        $this->assertEquals($expectedResult, $this->security->scan($xmlContent));
    }

    /**
     * Data provider for testScan
     *
     * @return array
     */
    public function dataProviderTestScan()
    {
        return [
            [
                'xmlContent' => '<?xml version="1.0"?><test></test>',
                'expectedResult' => true,
            ],
            [
                'xmlContent' => '<!DOCTYPE note SYSTEM "Note.dtd"><?xml version="1.0"?><test></test>',
                'expectedResult' => false,
            ],
            [
                'xmlContent' => '<?xml version="1.0"?>
            <!DOCTYPE test [
              <!ENTITY value "value">
              <!ENTITY value1 "&value;&value;&value;&value;&value;&value;&value;&value;&value;&value;">
              <!ENTITY value2 "&value1;&value1;&value1;&value1;&value1;&value1;&value1;&value1;&value1;&value1;">
            ]>
            <test>&value2;</test>',
                'expectedResult' => false,
            ],
            [
                'xmlContent' => '<!DOCTYPE html><?xml version="1.0"?><test></test>',
                'expectedResult' => false,
            ],
            [
                'xmlContent' => '',
                'expectedResult' => false,
            ],
        ];
    }
}
