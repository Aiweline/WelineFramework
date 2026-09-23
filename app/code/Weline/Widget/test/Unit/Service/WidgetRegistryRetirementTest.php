<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\AbstractModel;
use Weline\Widget\Model\WidgetRegistryEntry;
use Weline\Widget\Service\WidgetRegistryRecordService;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

final class WidgetRegistryRetirementTest extends TestCase
{
    public function testRetirementInvalidatesPlansAlreadyReadByTheSameProcess(): void
    {
        $state = (object)['rows' => [1 => $this->row(1, 'Weline_Theme', 'old')]];
        $model = new RegistryRetirementRows($state);
        $plans = new DefaultInjectionPlanRepository($model);
        self::assertCount(1, $plans->listDeclarations());
        (new WidgetRegistryRecordService($model, $plans))->sync([], ['scan_coverage' => [
            'complete' => true, 'modules' => ['Weline_Theme'], 'identities' => [],
        ]]);
        self::assertSame([], $plans->listDeclarations());
    }
    public function testCompleteScanRetiresOnlyAbsentOrdinaryDefinitionsInCoveredModules(): void
    {
        $state = (object)['rows' => [
            1 => $this->row(1, 'Weline_Theme', 'old'),
            2 => $this->row(2, 'Weline_Blog', 'old'),
            3 => $this->row(3, 'Weline_Theme', 'account'),
            4 => $this->row(4, 'Weline_Theme', 'ai', ['is_ai_generated' => true]),
            5 => $this->row(5, 'Weline_Theme', 'old', ['area' => 'adminhtml']),
        ]];
        $service = new WidgetRegistryRecordService(new RegistryRetirementRows($state));
        $report = $service->sync([], ['scan_coverage' => [
            'complete' => true,
            'modules' => ['Weline_Theme'],
            'identities' => [
                ['area' => 'frontend', 'module' => 'Weline_Theme', 'type' => 'footer', 'code' => 'account'],
                ['area' => 'adminhtml', 'module' => 'Weline_Theme', 'type' => 'footer', 'code' => 'old'],
            ],
        ]]);
        self::assertSame(0, $state->rows[1]['is_active'], 'Removed file definitions must stop injecting defaults.');
        foreach ([2, 3, 4, 5] as $id) { self::assertSame(1, $state->rows[$id]['is_active']); }
        self::assertSame(1, $report['retired_count']);
        self::assertSame('Weline_Theme', $report['injection_structure_changes'][0]['widget_identity']['module']);
        self::assertSame([], $report['injection_structure_changes'][0]['after']);
        self::assertTrue($report['injection_structure_changes'][0]['definition_retired'] ?? false);
        self::assertSame(0, $service->sync([], ['scan_coverage' => ['complete' => true, 'modules' => ['Weline_Theme'], 'identities' => [
            ['area' => 'frontend', 'module' => 'Weline_Theme', 'type' => 'footer', 'code' => 'account'],
            ['area' => 'adminhtml', 'module' => 'Weline_Theme', 'type' => 'footer', 'code' => 'old'],
        ]]])['retired_count']);
    }

    public function testPartialOrUnverifiedScanNeverRetiresDefinitions(): void
    {
        foreach ([[], ['scan_coverage' => ['complete' => false, 'modules' => ['Weline_Theme'], 'identities' => []]]] as $context) {
            $state = (object)['rows' => [1 => $this->row(1, 'Weline_Theme', 'old')]];
            (new WidgetRegistryRecordService(new RegistryRetirementRows($state)))->sync([], $context);
            self::assertSame(1, $state->rows[1]['is_active']);
        }
    }

    private function row(int $id, string $module, string $code, array $extra = []): array
    {
        $widget = array_replace(['area' => 'frontend', 'module' => $module, 'type' => 'footer', 'code' => $code,
            'default_injections' => [['slot' => 'footer-help-links', 'required' => true]]], $extra);
        return ['registry_id' => $id, 'widget_area' => $widget['area'], 'widget_module' => $module,
            'widget_type' => 'footer', 'widget_code' => $code, 'is_active' => 1, 'has_default_injections' => 1, 'registry_json' => json_encode($widget)];
    }
}

final class RegistryRetirementRows extends WidgetRegistryEntry
{
    private array $filters = [];
    private array $values = [];
    public function __construct(private object $state) {}
    public function __clone() { $this->filters = []; $this->values = []; }
    public function clearData(bool $with_query = true): static { $this->values = []; return $this; }
    public function setData($key, $value = null, bool $is_unique = false): static { $this->values[$key] = $value; return $this; }
    public function load(int|string $field_or_pk_value, $value = null, bool $forceReload = false): AbstractModel
    { $this->values = $this->state->rows[$field_or_pk_value]; return $this; }
    public function save(string|array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    { $this->state->rows[$this->values['registry_id']] = $this->values; return true; }
    public function __call($name, $arguments)
    {
        if ($name === 'clearQuery') { $this->filters = []; }
        if ($name === 'where') { $this->filters[$arguments[0]] = $arguments[1]; }
        if ($name === 'fetchArray') {
            return array_values(array_filter($this->state->rows, function ($row) {
                foreach ($this->filters as $key => $value) { if (($row[$key] ?? null) != $value) { return false; } }
                return true;
            }));
        }
        return $this;
    }
}
