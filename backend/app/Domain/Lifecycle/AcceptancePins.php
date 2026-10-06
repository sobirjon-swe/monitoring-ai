<?php

namespace App\Domain\Lifecycle;

class AcceptancePins
{
    public static function json(array $pins): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            } if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($pins), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function matches(object $decision, array $pins): bool
    {
        $json = self::json($pins);

        return hash_equals($decision->pins_hash, hash('sha256', $json)) && self::json(json_decode($decision->pins, true, 16, JSON_THROW_ON_ERROR)) === $json;
    }
}
