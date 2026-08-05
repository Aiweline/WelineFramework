<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\EavModelInterface;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Type;
use Weline\Eav\Service\AttributeDependenceResolver;

final class AttributeDependenceResolverTest extends TestCase
{
    protected function setUp(): void
    {
        DependenceModelFixture::$result = [];
        DependenceModelFixture::$params = [];
        DependenceModelFixture::$throw = null;
        AttributeStub::$queryCalls = [];
    }

    public function testResolveUsesEntityAndAttributeAndNormalizesOptions(): void
    {
        DependenceModelFixture::$result = [
            1 => 'One',
            'enabled' => true,
            'label' => new StringableLabel('Label'),
        ];
        $attribute = $this->attribute(8, DependenceModelFixture::class);
        $service = new AttributeDependenceResolver($attribute);

        $result = $service->resolve([
            'eav_entity_id' => '12',
            'dependence_attribute' => ' country ',
            'dependence_value' => '0',
            'attribute' => ' city ',
            'attribute_value' => 9,
        ]);

        self::assertSame([
            [EavAttribute::schema_fields_eav_entity_id, 12],
            [EavAttribute::schema_fields_code, 'city'],
            [EavAttribute::schema_fields_eav_entity_id, 12],
            [EavAttribute::schema_fields_code, 'country'],
        ], AttributeStub::$queryCalls);
        self::assertSame([
            'dependenceAttribute' => 'country',
            'dependenceAttributeValue' => '0',
            'attribute' => 'city',
            'attributeValue' => 9,
        ], DependenceModelFixture::$params);
        self::assertSame([1 => 'One', 'enabled' => true, 'label' => 'Label'], $result);
    }

    public function testResolveRejectsMissingRequiredInputBeforeLoadingAttribute(): void
    {
        $attribute = $this->attribute(8, DependenceModelFixture::class);
        $service = new AttributeDependenceResolver($attribute);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->resolve([
                'eav_entity_id' => 12,
                'dependence_attribute' => 'country',
                'attribute' => 'city',
            ]);
        } finally {
            self::assertSame([], AttributeStub::$queryCalls);
        }
    }

    public function testResolveRejectsAttributeOutsideEntity(): void
    {
        $service = new AttributeDependenceResolver(
            $this->attribute(0, DependenceModelFixture::class),
        );

        $this->expectException(\DomainException::class);
        $service->resolve($this->validParams());
    }

    public function testResolveRejectsTypeModelOutsidePublicContract(): void
    {
        $service = new AttributeDependenceResolver(
            $this->attribute(8, NonEavDependenceModelFixture::class),
        );

        $this->expectException(\DomainException::class);
        $service->resolve($this->validParams());
    }

    public function testResolveRejectsDependenceAttributeThatExistsOnlyOnAnotherEntity(): void
    {
        $type = new Type();
        $type->setData(Type::schema_fields_type_id, 3);
        $type->setModelClass(DependenceModelFixture::class);
        $attribute = new AttributeStub([
            12 => ['city' => 8],
            13 => ['country' => 9],
        ], $type);
        $service = new AttributeDependenceResolver($attribute);

        $this->expectException(\DomainException::class);
        try {
            $service->resolve($this->validParams());
        } finally {
            self::assertSame([
                [EavAttribute::schema_fields_eav_entity_id, 12],
                [EavAttribute::schema_fields_code, 'city'],
                [EavAttribute::schema_fields_eav_entity_id, 12],
                [EavAttribute::schema_fields_code, 'country'],
            ], AttributeStub::$queryCalls);
        }
    }

    public function testResolveRejectsAttributeCodeLongerThanTwoHundredFiftyFiveBytes(): void
    {
        $service = new AttributeDependenceResolver(
            $this->attribute(8, DependenceModelFixture::class),
        );
        $params = $this->validParams();
        $params['attribute'] = str_repeat('a', 256);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->resolve($params);
        } finally {
            self::assertSame([], AttributeStub::$queryCalls);
        }
    }

    public function testResolveRejectsControlCharactersInAttributeCodes(): void
    {
        $service = new AttributeDependenceResolver(
            $this->attribute(8, DependenceModelFixture::class),
        );
        $params = $this->validParams();
        $params['dependence_attribute'] = "country\n";

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->resolve($params);
        } finally {
            self::assertSame([], AttributeStub::$queryCalls);
        }
    }

    public function testResolveRejectsMoreThanFiveHundredOptions(): void
    {
        DependenceModelFixture::$result = array_fill(0, 501, 'option');
        $service = new AttributeDependenceResolver(
            $this->attribute(8, DependenceModelFixture::class),
        );

        $this->expectException(\DomainException::class);
        $service->resolve($this->validParams());
    }

    public function testResolveRejectsNonScalarOptionValue(): void
    {
        DependenceModelFixture::$result = ['invalid' => ['nested']];
        $service = new AttributeDependenceResolver(
            $this->attribute(8, DependenceModelFixture::class),
        );

        $this->expectException(\DomainException::class);
        $service->resolve($this->validParams());
    }

    public function testResolveNormalizesLegacyEmptyResult(): void
    {
        DependenceModelFixture::$result = '';
        $service = new AttributeDependenceResolver(
            $this->attribute(8, DependenceModelFixture::class),
        );

        self::assertSame([], $service->resolve($this->validParams()));
    }

    public function testResolveDoesNotExposeDownstreamExceptionDetails(): void
    {
        DependenceModelFixture::$throw = new \RuntimeException('sql=/private/path secret-value');
        $service = new AttributeDependenceResolver(
            $this->attribute(8, DependenceModelFixture::class),
        );

        try {
            $service->resolve($this->validParams());
            self::fail('Expected downstream failure.');
        } catch (\DomainException $exception) {
            self::assertSame('属性依赖选项解析失败。', $exception->getMessage());
            self::assertStringNotContainsString('secret-value', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    public function testResolveRejectsOversizedInputValues(): void
    {
        $service = new AttributeDependenceResolver(
            $this->attribute(8, DependenceModelFixture::class),
        );
        $params = $this->validParams();
        $params['dependence_value'] = array_fill(0, 501, 'x');

        $this->expectException(\InvalidArgumentException::class);
        $service->resolve($params);
    }

    private function attribute(int $attributeId, string $modelClass): AttributeStub
    {
        $type = new Type();
        $type->setData(Type::schema_fields_type_id, 3);
        $type->setModelClass($modelClass);

        return new AttributeStub([
            12 => [
                'city' => $attributeId,
                'country' => 9,
            ],
        ], $type);
    }

    /** @return array<string, mixed> */
    private function validParams(): array
    {
        return [
            'eav_entity_id' => 12,
            'dependence_attribute' => 'country',
            'dependence_value' => 1,
            'attribute' => 'city',
        ];
    }
}

final class AttributeStub extends EavAttribute
{
    /** @var list<array{string, mixed}> */
    public static array $queryCalls = [];

    private ?int $selectedEntityId = null;
    private ?string $selectedAttributeCode = null;

    public function __construct(
        /** @var array<int, array<string, int>> */
        private readonly array $attributeIds,
        private readonly Type $attributeType,
    ) {
    }

    public function reset(): static
    {
        $this->selectedEntityId = null;
        $this->selectedAttributeCode = null;

        return $this;
    }

    public function clearData(bool $withQuery = true): static
    {
        $this->selectedEntityId = null;
        $this->selectedAttributeCode = null;

        return $this;
    }

    public function where(
        array|string $field,
        mixed $value = null,
        string $condition = '=',
        string $whereLogic = 'AND',
        string $arrayWhereLogicType = 'AND',
    ): static {
        if (is_string($field)) {
            self::$queryCalls[] = [$field, $value];
            if ($field === EavAttribute::schema_fields_eav_entity_id) {
                $this->selectedEntityId = (int)$value;
            }
            if ($field === EavAttribute::schema_fields_code) {
                $this->selectedAttributeCode = (string)$value;
            }
        }

        return $this;
    }

    public function find(string $findFields = ''): static
    {
        return $this;
    }

    public function fetch(string $modelClass = ''): static
    {
        return $this;
    }

    public function getAttributeId(): int
    {
        if ($this->selectedEntityId === null || $this->selectedAttributeCode === null) {
            return 0;
        }

        return $this->attributeIds[$this->selectedEntityId][$this->selectedAttributeCode] ?? 0;
    }

    public function getType(string $typeCode = ''): Type
    {
        return $this->attributeType;
    }
}

final class DependenceModelFixture implements EavModelInterface
{
    public static mixed $result = [];
    public static ?\Throwable $throw = null;
    /** @var array<string, mixed> */
    public static array $params = [];

    public function getHtml(
        EavAttribute &$attribute,
        mixed $value,
        string &$label_class,
        array &$attrs,
        array &$option_items = [],
        bool $only_custom_options = true,
    ): string {
        return '';
    }

    public function getModelData(): mixed
    {
        return [];
    }

    public static function dependenceProcess(array $dependenceValue = []): mixed
    {
        if (self::$throw instanceof \Throwable) {
            throw self::$throw;
        }
        self::$params = $dependenceValue;

        return self::$result;
    }
}

final class NonEavDependenceModelFixture
{
    public static function dependenceProcess(array $dependenceValue = []): array
    {
        return [];
    }
}

final class StringableLabel implements \Stringable
{
    public function __construct(private readonly string $label)
    {
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
