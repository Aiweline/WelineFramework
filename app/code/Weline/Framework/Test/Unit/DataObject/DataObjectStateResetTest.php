<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\DataObject;

use PHPUnit\Framework\TestCase;
use Weline\Framework\DataObject\DataObject;

final class DataObjectStateResetTest extends TestCase
{
    public function testClearDataObjectClearsChangedState(): void
    {
        $object = new DataObject(['subject_id' => 582]);
        $object->setData('subject_type', 'page');

        self::assertSame(['subject_type' => 'page'], $object->getChangedData());

        $object->clearDataObject();

        self::assertSame([], $object->getData());
        self::assertSame([], $object->getChangedData());
    }

    public function testSetObjectDataTreatsLoadedDataAsCleanBaseline(): void
    {
        $object = new DataObject();
        $object->setData('subject_id', 582);

        $object->setObjectData([
            'subject_id' => 664,
            'subject_type' => 'category',
        ]);

        self::assertSame([], $object->getChangedData());

        $object->setData('url', 'https://example.test/category');

        self::assertSame(['url' => 'https://example.test/category'], $object->getChangedData());
    }
}
