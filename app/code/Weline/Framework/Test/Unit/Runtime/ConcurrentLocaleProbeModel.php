<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use Weline\Framework\Database\AbstractModel;

/**
 * 并发多语言用例的最小模型替身：不建表、不连库，只验证实例与数据隔离。
 */
final class ConcurrentLocaleProbeModel extends AbstractModel
{
    public string $table = 'concurrent_locale_probe';
    public string $_primary_key = 'id';

    public function fillFromRow(array $row): static
    {
        $this->setData($row);

        return $this;
    }
}
