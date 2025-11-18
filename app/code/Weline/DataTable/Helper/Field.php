<?php

namespace Weline\DataTable\Helper;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class Field
{
    static function sort()
    {
        /** @var Request $req */
        $req = ObjectManager::getInstance(Request::class);
        $sorts = $req->getGetByPre('sort.', true);
        $sort_sql = '';
        foreach ($sorts as $key => $sort) {
            $sort_sql .=  $key . ' ' . $sort.',';
        }
        return rtrim($sort_sql, ',');
    }
}