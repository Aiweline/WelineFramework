<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Ops\Model\OpsWebsiteProgram;

final class OpsProgramService
{
    public function __construct(
        private readonly OpsWebsiteProgram $program,
    ) {
    }

    public function getOrCreate(int $websiteId): OpsWebsiteProgram
    {
        $websiteId = max(0, $websiteId);
        $row = $this->program->clear()
            ->where(OpsWebsiteProgram::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();
        if ($row->getId()) {
            return $row;
        }

        // 产品硬默认：新建程序必须关闭；仅 Desk「开启 AI运营」可打开（建站助手只引导、不代开）。
        $row->setData(OpsWebsiteProgram::schema_fields_WEBSITE_ID, $websiteId);
        $row->setData(OpsWebsiteProgram::schema_fields_ENABLED, 0);
        $row->setData(OpsWebsiteProgram::schema_fields_REQUIRE_APPROVAL, 1);
        $row->setData(OpsWebsiteProgram::schema_fields_DAILY_TIME, '09:00');
        $row->save();

        return $row;
    }

    public function isEnabled(int $websiteId): bool
    {
        $row = $this->getOrCreate($websiteId);

        return (int)$row->getData(OpsWebsiteProgram::schema_fields_ENABLED) === 1;
    }

    public function setEnabled(int $websiteId, bool $enabled): OpsWebsiteProgram
    {
        $row = $this->getOrCreate($websiteId);
        $row->setData(OpsWebsiteProgram::schema_fields_ENABLED, $enabled ? 1 : 0);
        $row->save();

        return $row;
    }

    /**
     * @return list<array{website_id:int,program_id:int,daily_time:string}>
     */
    public function listEnabled(): array
    {
        $items = $this->program->clear()
            ->where(OpsWebsiteProgram::schema_fields_ENABLED, 1)
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof OpsWebsiteProgram) {
                continue;
            }
            $out[] = [
                'website_id' => (int)$item->getData(OpsWebsiteProgram::schema_fields_WEBSITE_ID),
                'program_id' => (int)$item->getId(),
                'daily_time' => (string)$item->getData(OpsWebsiteProgram::schema_fields_DAILY_TIME),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function toView(OpsWebsiteProgram $row): array
    {
        return [
            'program_id' => (int)$row->getId(),
            'website_id' => (int)$row->getData(OpsWebsiteProgram::schema_fields_WEBSITE_ID),
            'enabled' => (int)$row->getData(OpsWebsiteProgram::schema_fields_ENABLED) === 1,
            'require_approval' => (int)$row->getData(OpsWebsiteProgram::schema_fields_REQUIRE_APPROVAL) === 1,
            'daily_time' => (string)$row->getData(OpsWebsiteProgram::schema_fields_DAILY_TIME),
        ];
    }
}
