<?php

declare(strict_types=1);

/*
 * GuoLaiRen Blog Module
 * 随机美国名字（名+姓）
 */

namespace GuoLaiRen\Blog\Helper;

class RandomAuthorName
{
    private const FIRST = [
        'James', 'John', 'Robert', 'Michael', 'William', 'David', 'Richard', 'Joseph', 'Thomas', 'Charles',
        'Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth', 'Barbara', 'Susan', 'Jessica', 'Sarah', 'Karen',
    ];

    private const LAST = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
        'Wilson', 'Anderson', 'Taylor', 'Thomas', 'Moore', 'Jackson', 'Martin', 'Lee', 'Thompson', 'White',
    ];

    public static function generate(): string
    {
        $first = self::FIRST[array_rand(self::FIRST)];
        $last = self::LAST[array_rand(self::LAST)];
        return $first . ' ' . $last;
    }
}
