<?php

namespace App\Integrations\Google;

use App\Integrations\IntegrationException;

class SpreadsheetIdentifier
{
    public static function parse(string $input): string
    {
        $input = trim($input);
        if (str_contains($input, '://')) {
            $parts = parse_url($input);
            if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'docs.google.com' || isset($parts['user']) || isset($parts['port']) || ! preg_match('#^/spreadsheets/d/([A-Za-z0-9_-]+)(?:/|$)#', $parts['path'] ?? '', $match)) {
                throw new IntegrationException('Wklej URL https://docs.google.com/spreadsheets/d/... lub sam spreadsheet ID.', 'invalid_spreadsheet');
            }
            $input = $match[1];
        }
        if (! preg_match('/^[A-Za-z0-9_-]{20,200}$/', $input)) {
            throw new IntegrationException('Nieprawidłowy identyfikator Google Sheets.', 'invalid_spreadsheet');
        }

        return $input;
    }
}
