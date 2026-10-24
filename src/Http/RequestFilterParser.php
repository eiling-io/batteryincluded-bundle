<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the filters of a search request, `f[<field>][]=<value>` and `f[<field>][from|till]=<number>`.
 */
class RequestFilterParser
{
    /**
     * Empty values and incomplete ranges are removed.
     *
     * @return array<string, list<string>|array{from: string, till: string}>
     */
    public function parse(Request $request, string $parameter = 'f'): array
    {
        $filters = [];
        foreach ($request->query->all($parameter) as $field => $values) {
            if (!is_array($values)) {
                continue;
            }

            if (array_key_exists('from', $values) || array_key_exists('till', $values)) {
                $from = mb_trim((string) ($values['from'] ?? ''));
                $till = mb_trim((string) ($values['till'] ?? ''));
                if (is_numeric($from) || is_numeric($till)) {
                    $filters[(string) $field] = [
                        'from' => is_numeric($from) ? $from : '0',
                        'till' => is_numeric($till) ? $till : (string) PHP_INT_MAX,
                    ];
                }
                continue;
            }

            $values = array_values(array_unique(array_filter(array_map('strval', array_filter($values, 'is_scalar')), static fn ($value) => $value !== '')));
            if ($values !== []) {
                $filters[(string) $field] = $values;
            }
        }

        return $filters;
    }
}
