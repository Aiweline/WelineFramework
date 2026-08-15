<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Inquiry\Api\InquiryFormCatalogInterface;
use Weline\Inquiry\Model\Form;

final class InquiryFormCatalog implements InquiryFormCatalogInterface
{
    public function __construct(private readonly Form $form) {}
    public function published(string $search = ''): array
    {
        $query = $this->form->reset()->where(Form::schema_fields_STATUS, Form::STATUS_PUBLISHED);
        if (($search = trim($search)) !== '') {
            $query->where(Form::schema_fields_CODE, '%' . $search . '%', 'like');
        }
        $rows = $query->order(Form::schema_fields_NAME, 'ASC')->select()->fetchArray();
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string)($row[Form::schema_fields_CODE] ?? '')) === '') { continue; }
            $result[] = ['code' => (string)$row[Form::schema_fields_CODE], 'name' => (string)($row[Form::schema_fields_NAME] ?? ''), 'default_locale' => (string)($row[Form::schema_fields_DEFAULT_LOCALE] ?? 'en_US')];
        }
        return $result;
    }
}
