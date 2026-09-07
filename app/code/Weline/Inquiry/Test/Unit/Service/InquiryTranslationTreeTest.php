<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inquiry\Service\InquiryTranslationTree;

final class InquiryTranslationTreeTest extends TestCase
{
    public function testFlattenNestedLeaves(): void
    {
        $tree = new InquiryTranslationTree();
        $leaves = $tree->flatten([
            'title' => '供应商申请',
            'fields' => [
                'company' => ['label' => '公司名称'],
                'supply_type' => ['options' => ['manufacturer' => '制造商']],
            ],
        ]);
        self::assertSame('供应商申请', $leaves['title']);
        self::assertSame('公司名称', $leaves['fields.company.label']);
        self::assertSame('制造商', $leaves['fields.supply_type.options.manufacturer']);
    }

    public function testMergeLeavesSkipsExistingUnlessOverwrite(): void
    {
        $tree = new InquiryTranslationTree();
        $base = ['title' => 'Existing', 'fields' => ['company' => ['label' => '']]];
        $merged = $tree->mergeLeaves($base, [
            'title' => 'New Title',
            'fields.company.label' => 'Company',
        ], false);
        self::assertSame('Existing', $merged['title']);
        self::assertSame('Company', $merged['fields']['company']['label']);

        $forced = $tree->mergeLeaves($merged, ['title' => 'Forced'], true);
        self::assertSame('Forced', $forced['title']);
    }
}
