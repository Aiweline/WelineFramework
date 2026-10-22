<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Model;

use Weline\Framework\Test\TestCore;
use Weline\Seo\Model\SeoKeyword;

class SeoKeywordStreamTest extends TestCore
{
    protected function setUp(): void
    {
        parent::setUp();
        (new SeoKeyword())->getConnection()->query(
            'CREATE TABLE IF NOT EXISTS w_weline_seo_keyword (
                keyword_id INTEGER PRIMARY KEY AUTOINCREMENT,
                subject_id INTEGER NOT NULL,
                keyword VARCHAR(255) NOT NULL,
                priority INTEGER NOT NULL DEFAULT 0,
                source VARCHAR(50) NOT NULL DEFAULT \'manual\',
                status INTEGER NOT NULL DEFAULT 1,
                created_at TEXT,
                updated_at TEXT
            )'
        )->fetch();
    }

    public function testStreamEnabledBySubjectYieldsPriorityThenIdAndSkipsDisabled(): void
    {
        $subjectId = random_int(800000, 900000);
        $this->insertKeyword($subjectId, 'low', 0, SeoKeyword::STATUS_ENABLED);
        $this->insertKeyword($subjectId, 'tie-early', 5, SeoKeyword::STATUS_ENABLED);
        $this->insertKeyword($subjectId, 'tie-late', 5, SeoKeyword::STATUS_ENABLED);
        $this->insertKeyword($subjectId, 'top', 9, SeoKeyword::STATUS_ENABLED);
        $this->insertKeyword($subjectId, 'hidden', 9, SeoKeyword::STATUS_DISABLED);

        $rows = [];
        foreach ((new SeoKeyword())->streamEnabledBySubject($subjectId, 2) as $row) {
            $rows[] = $row['keyword'];
        }

        self::assertSame(['top', 'tie-late', 'tie-early', 'low'], $rows);
    }

    private function insertKeyword(int $subjectId, string $keyword, int $priority, int $status): void
    {
        $model = new SeoKeyword();
        $model->setSubjectId($subjectId)
            ->setKeyword($keyword)
            ->setPriority($priority)
            ->setStatus($status)
            ->save();
    }
}
