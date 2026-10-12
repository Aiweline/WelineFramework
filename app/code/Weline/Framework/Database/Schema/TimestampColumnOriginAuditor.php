<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema;

use ReflectionClass;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Schema\Attribute\Col;

/**
 * Minimal audit hook for R-C C2 dual-track timestamp origins.
 *
 * Outputs contract marker strings {@see TimestampColumnOriginMarkers::FRAMEWORK_BASE}
 * and {@see TimestampColumnOriginMarkers::LEGACY_IMPLICIT} — never mislabels the former
 * as undeclared pollution.
 */
final class TimestampColumnOriginAuditor
{
    /**
     * Classify a known timestamp column on a Model: AbstractModel #[Col] inheritance
     * without a subclass/own redeclare → framework_base_timestamps.
     */
    public function classifyInheritedTimestamp(string $modelClass, string $columnName): ?string
    {
        if (!self::isKnownTimestampColumn($columnName)) {
            return null;
        }
        if (!\class_exists($modelClass) || !\is_subclass_of($modelClass, AbstractModel::class)) {
            return null;
        }

        $ref = new ReflectionClass($modelClass);
        $fromAbstract = false;
        $redeclaredBelowAbstract = false;

        foreach ($this->classChainIncludingAbstract($ref) as $class) {
            foreach ($class->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                if (!\str_starts_with($constant->getName(), 'schema_fields_') || !$constant->isPublic()) {
                    continue;
                }
                if ($constant->getAttributes(Col::class) === []) {
                    continue;
                }
                if ($constant->getValue() !== $columnName) {
                    continue;
                }
                if ($class->getName() === AbstractModel::class) {
                    $fromAbstract = true;
                } else {
                    $redeclaredBelowAbstract = true;
                }
            }
        }

        if ($redeclaredBelowAbstract) {
            return null;
        }
        if ($fromAbstract) {
            return TimestampColumnOriginMarkers::FRAMEWORK_BASE;
        }

        return null;
    }

    /**
     * Marker for legacy Create::create() / create(true) injection path.
     * create(false) (declarative) yields null — no legacy_implicit_timestamps.
     */
    public function markerForCreateCall(bool $withImplicitTimestampColumns): ?string
    {
        return $withImplicitTimestampColumns
            ? TimestampColumnOriginMarkers::LEGACY_IMPLICIT
            : null;
    }

    /**
     * Audit report rows for inherited AbstractModel timestamp columns.
     *
     * @return list<array{column:string, marker:string}>
     */
    public function auditModel(string $modelClass): array
    {
        $rows = [];
        foreach ([TimestampColumnOriginMarkers::CREATE_TIME, TimestampColumnOriginMarkers::UPDATE_TIME] as $column) {
            $marker = $this->classifyInheritedTimestamp($modelClass, $column);
            if ($marker === null) {
                continue;
            }
            $rows[] = [
                'column' => $column,
                'marker' => $marker,
            ];
        }

        return $rows;
    }

    /**
     * Report / classifier: documented dual-track markers are never undeclared pollution.
     */
    public function isUndeclaredPollution(string $marker): bool
    {
        if (TimestampColumnOriginMarkers::isDocumentedOrigin($marker)) {
            return false;
        }

        return $marker === TimestampColumnOriginMarkers::UNDECLARED_POLLUTION;
    }

    public static function isKnownTimestampColumn(string $columnName): bool
    {
        return $columnName === TimestampColumnOriginMarkers::CREATE_TIME
            || $columnName === TimestampColumnOriginMarkers::UPDATE_TIME;
    }

    /**
     * @return list<ReflectionClass>
     */
    private function classChainIncludingAbstract(ReflectionClass $ref): array
    {
        $chain = [];
        $current = $ref;
        while ($current !== false) {
            $chain[] = $current;
            if ($current->getName() === AbstractModel::class) {
                break;
            }
            $current = $current->getParentClass();
        }

        return $chain;
    }
}
