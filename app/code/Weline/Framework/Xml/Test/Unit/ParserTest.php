<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Xml\Test\Unit;

use Weline\Framework\Xml\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    /** @var Parser */
    protected Parser $parser;

    protected function setUp(): void
    {
        if (!function_exists('libxml_set_external_entity_loader')) {
            $this->markTestSkipped('Skipped on HHVM. ');
        }
        $this->parser = new Parser();
    }

    public function testGetXml()
    {
        $this->assertEquals(
            ['data' => [
                'nodes' => [
                    'text'        => ' some text ',
                    'trim_spaces' => '',
                    'cdata'       => '  Some data here <strong>html</strong> tags are <i>allowed</i>  ',
                    'zero'        => '0',
                    'null'        => null,
                ],
            ]],
            $this->parser->load(__DIR__ . '/_files/data.xml')->xmlToArray()
        );
    }

    public function testLoadXmlInvalid()
    {
        $this->expectException(\Weline\Framework\Exception\Core::class);
        $this->expectExceptionMessage('DOMDocument::loadXML(): Opening and ending tag mismatch');
        $sampleInvalidXml = '<?xml version="1.0"?><config></onfig>';
        $this->parser->initErrorHandler();
        $this->parser->loadXML($sampleInvalidXml);
    }
}
