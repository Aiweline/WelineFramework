<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Type as AttributeType;
use Weline\Queue\Model\Queue\Type as QueueType;
use Weline\Queue\Service\QueueAdminService;

final class QueueAdminAttributeValidationTest extends TestCase
{
    public function testRequiredAttributeCannotBeOmitted(): void
    {
        $type = new QueueAdminTypeFixture([
            new QueueAdminAttributeFixture('required_code', true),
        ]);

        $this->expectException(\DomainException::class);
        $this->validate($type, []);
    }

    #[DataProvider('emptyRequiredValueProvider')]
    public function testRequiredAttributeRejectsEmptyValue(mixed $value): void
    {
        $type = new QueueAdminTypeFixture([
            new QueueAdminAttributeFixture('required_code', true),
        ]);

        $this->expectException(\DomainException::class);
        $this->validate($type, [['code' => 'required_code', 'value' => $value]]);
    }

    /** @return iterable<string,array{0:mixed}> */
    public static function emptyRequiredValueProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace string' => ['   '];
        yield 'empty list' => [[]];
    }

    #[DataProvider('presentFalsyValueProvider')]
    public function testRequiredAttributeAcceptsPresentFalsyValue(mixed $value, int|string $expected): void
    {
        $attribute = new QueueAdminAttributeFixture('required_code', true);
        $type = new QueueAdminTypeFixture([$attribute]);

        $result = $this->validate($type, [['code' => 'required_code', 'value' => $value]]);

        self::assertSame($expected, $result[0]['value']);
        self::assertSame($attribute, $result[0]['attribute']);
    }

    /** @return iterable<string,array{0:mixed,1:int|string}> */
    public static function presentFalsyValueProvider(): iterable
    {
        yield 'false' => [false, 0];
        yield 'integer zero' => [0, 0];
        yield 'string zero' => ['0', '0'];
    }

    public function testDependentRequiredAttributeIsRequiredOnlyWhenDependenciesAreActive(): void
    {
        $type = new QueueAdminTypeFixture([
            new QueueAdminAttributeFixture('country', false),
            new QueueAdminAttributeFixture('city', true, 'country'),
        ]);

        self::assertSame([], $this->validate($type, []));

        $this->expectException(\DomainException::class);
        $this->validate($type, [['code' => 'country', 'value' => 'CN']]);
    }

    public function testSubmittedCodeMustComeFromFixedEntityTypeAttributeMap(): void
    {
        $type = new QueueAdminTypeFixture([]);

        $this->expectException(\DomainException::class);
        $this->validate($type, [['code' => 'same_code_on_other_entity', 'value' => 'x']]);
    }

    /** @param list<array<string,mixed>> $attributes @return list<array<string,mixed>> */
    private function validate(QueueType $type, array $attributes): array
    {
        $service = (new \ReflectionClass(QueueAdminService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(QueueAdminService::class, 'validateSubmittedAttributes');

        return $method->invoke($service, $type, $attributes, 12);
    }
}

final class QueueAdminTypeFixture extends QueueType
{
    /** @param list<EavAttribute> $attributes */
    public function __construct(private readonly array $attributes)
    {
    }

    public function getAttributes(array &$options = []): array
    {
        return $this->attributes;
    }
}

final class QueueAdminAttributeFixture extends EavAttribute
{
    private readonly AttributeType $fixtureType;

    public function __construct(string $code, bool $required, string $dependence = '')
    {
        $this->setData([
            self::schema_fields_attribute_id => abs((int)crc32($code)) + 1,
            self::schema_fields_eav_entity_id => 12,
            self::schema_fields_code => $code,
            self::schema_fields_name => $code,
            self::schema_fields_dependence => $dependence,
        ]);
        $this->fixtureType = new AttributeType();
        $this->fixtureType->setData(AttributeType::schema_fields_type_id, 1);
        $this->fixtureType->setRequired($required);
    }

    public function getTypeModel(): AttributeType
    {
        return $this->fixtureType;
    }
}
