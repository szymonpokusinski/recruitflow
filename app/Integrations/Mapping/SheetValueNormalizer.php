<?php

namespace App\Integrations\Mapping;

use App\Integrations\IntegrationException;
use Carbon\CarbonImmutable;

class SheetValueNormalizer
{
    public static function boolean(mixed $value, string $field): ?bool
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }

        return match (MappingSchema::normalize((string) $value)) {
            'tak','true','yes','1' => true, 'nie','false','no','0' => false, default => throw new IntegrationException('Pole '.$field.' ma nieprawidłową wartość logiczną.', 'row_invalid')
        };
    }

    public static function date(mixed $value, string $timezone, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (is_numeric($value)) {
            $serial = (float) $value;
            if (! is_finite($serial) || $serial < 1 || $serial > 2958465) {
                throw new IntegrationException('Nieprawidłowa data numeryczna w polu '.$field.'.', 'row_invalid');
            }
            $days = (int) floor($serial);
            $seconds = (int) round(($serial - $days) * 86400);
            // Google Sheets serials describe local wall time, not elapsed UTC time across DST.
            $wall = CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays($days)->addSeconds($seconds)->format('Y-m-d H:i:s');

            return CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $wall, $timezone)->utc()->toIso8601String();
        }
        $text = trim((string) $value);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/', $text)) {
                $date = CarbonImmutable::parse($text, $timezone);
                if (! CarbonImmutable::getLastErrors()) {
                    return $date->utc()->toIso8601String();
                }
            }
            foreach (['!d.m.Y H:i:s', '!d.m.Y H:i', '!d.m.Y', '!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y'] as $format) {
                try {
                    $date = CarbonImmutable::createFromFormat($format, $text, $timezone);
                    if ($date && ! CarbonImmutable::getLastErrors()) {
                        return $date->utc()->toIso8601String();
                    }
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
        throw new IntegrationException('Pole '.$field.' wymaga daty ISO, polskiej daty lub daty numerycznej Google Sheets.', 'row_invalid');
    }
}
