<?php

namespace App\Receipt;

/** Statecan canonical JSON v1: recursively byte-sort object keys, preserve lists,
 * UTF-8/unescaped slashes, no whitespace, integers/strings/bools/null only.
 */
final class CanonicalJson
{
    public function encode(array $value): string
    {
        return json_encode($this->sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_float($value) || is_object($value)) {
                throw new \InvalidArgumentException('Canonical evidence does not support floats or objects.');
            }

            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->sort(...), $value);
    }
}
