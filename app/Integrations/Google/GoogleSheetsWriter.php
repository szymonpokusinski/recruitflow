<?php

namespace App\Integrations\Google;

use App\Integrations\IntegrationException;
use App\Integrations\Mapping\SheetValueNormalizer;
use App\Models\Integrations\DataSource;
use App\Models\Recruitment\SyncOperation;
use App\Recruitment\EntityRegistry;
use Carbon\CarbonImmutable;

class GoogleSheetsWriter
{
    public function __construct(private GoogleSheetsGateway $gateway) {}

    private function entered(mixed $value): array
    {
        // Text is always literal; strings beginning with '=' cannot become formulas.
        return match (true) {
            $value === null || $value === '' => [], is_bool($value) => ['boolValue' => $value], is_numeric($value) && ! is_string($value) => ['numberValue' => (float) $value], default => ['stringValue' => (string) $value]
        };
    }

    private function cellValue(array $cell): mixed
    {
        $value = $cell['effectiveValue'] ?? $cell['userEnteredValue'] ?? [];

        return $value['stringValue'] ?? $value['numberValue'] ?? $value['boolValue'] ?? null;
    }

    private function serialize(string $field, mixed $value, string $timezone, array $rule): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($field === 'archived') {
            return $value ? 'Archiwalny' : 'Aktywny';
        }
        if (SyncFieldMap::isDate($field)) {
            $date = CarbonImmutable::parse((string) $value, $timezone)->setTimezone($timezone);
            $wall = CarbonImmutable::parse($date->format('Y-m-d H:i:s'), 'UTC');

            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->diffInSeconds($wall) / 86400;
        }
        if (is_bool($value)) {
            return ($rule['condition']['type'] ?? '') === 'BOOLEAN' ? $value : ($value ? 'TAK' : 'NIE');
        }

        return is_array($value) ? implode('; ', $value) : $value;
    }

    private function validate(mixed $value, array $rule): void
    {
        if ($value === null || $value === '' || empty($rule)) {
            return;
        }
        $condition = $rule['condition'] ?? [];
        $values = array_column($condition['values'] ?? [], 'userEnteredValue');
        $type = $condition['type'] ?? '';
        if ($type === 'ONE_OF_LIST' && ! in_array((string) $value, $values, true)) {
            throw new IntegrationException('Wartość nie spełnia walidacji listy docelowej komórki.', 'validation_mismatch');
        }
        if ($type === 'ONE_OF_RANGE' || $type === 'CUSTOM_FORMULA') {
            throw new IntegrationException('Walidacja komórki wymaga ręcznej weryfikacji (zakres lub formuła).', 'validation_unsupported');
        }
        if (str_starts_with($type, 'DATE_')) {
            if (! is_numeric($value)) {
                throw new IntegrationException('Komórka wymaga poprawnej daty.', 'validation_mismatch');
            }
            $bounds = array_map(function ($text) {
                if (is_numeric($text)) {
                    return (float) $text;
                }
                if (preg_match('/^=?\s*(?:DATE|DATA)\(\s*(\d{4})[;,]\s*(\d{1,2})[;,]\s*(\d{1,2})\s*\)$/i', $text, $parts)) {
                    if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                        throw new IntegrationException('Nieprawidłowy warunek daty w arkuszu.', 'validation_unsupported');
                    }

                    return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->diffInDays(CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, 'UTC'));
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
                    return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->diffInDays(CarbonImmutable::parse($text, 'UTC'));
                }
                throw new IntegrationException('Warunek daty wymaga ręcznej weryfikacji formuły.', 'validation_unsupported');
            }, $values);
            $day = floor($value);
            $a = $bounds[0] ?? null;
            $b = $bounds[1] ?? null;
            $valid = match ($type) {
                'DATE_IS_VALID' => true, 'DATE_BEFORE' => $a !== null && $day < $a, 'DATE_AFTER' => $a !== null && $day > $a,
                'DATE_ON_OR_BEFORE' => $a !== null && $day <= $a, 'DATE_ON_OR_AFTER' => $a !== null && $day >= $a,
                'DATE_EQ' => $a !== null && $day == $a, 'DATE_NOT_EQ' => $a !== null && $day != $a,
                'DATE_BETWEEN' => $a !== null && $b !== null && $day >= $a && $day <= $b,
                'DATE_NOT_BETWEEN' => $a !== null && $b !== null && ($day < $a || $day > $b), default => false,
            };
            if (! $valid) {
                throw new IntegrationException('Data nie spełnia walidacji docelowej komórki.', 'validation_mismatch');
            }
        }
        if (str_starts_with($type, 'NUMBER_')) {
            if (! is_numeric($value)) {
                throw new IntegrationException('Komórka wymaga liczby.', 'validation_mismatch');
            }
            $a = (float) ($values[0] ?? 0);
            $b = (float) ($values[1] ?? 0);
            $valid = match ($type) {
                'NUMBER_BETWEEN' => $value >= $a && $value <= $b, 'NUMBER_NOT_BETWEEN' => $value < $a || $value > $b, 'NUMBER_GREATER' => $value > $a, 'NUMBER_GREATER_THAN_EQ' => $value >= $a, 'NUMBER_LESS' => $value < $a, 'NUMBER_LESS_THAN_EQ' => $value <= $a, 'NUMBER_EQ' => $value == $a, 'NUMBER_NOT_EQ' => $value != $a, default => true
            };
            if (! $valid) {
                throw new IntegrationException('Liczba nie spełnia walidacji komórki.', 'validation_mismatch');
            }
        }
    }

    private function locate(DataSource $source, array $table, string $keyColumn, string $key): ?int
    {
        $column = GoogleSheetsGateway::column($table['headers'][$keyColumn]);
        $range = GoogleSheetsGateway::range($table['title'], $column.($table['header_row'] + 1).':'.$column.(config('integrations.max_rows') + $table['header_row']));
        $data = $this->gateway->request($source, 'GET', '/values/'.rawurlencode($range), ['valueRenderOption' => 'UNFORMATTED_VALUE']);
        $matches = [];
        foreach ($data['values'] ?? [] as $index => $row) {
            if ((string) ($row[0] ?? '') === $key) {
                $matches[] = $table['header_row'] + $index;
            }
        }
        if (count($matches) > 1) {
            throw new IntegrationException('Znaleziono kilka rekordów z tym samym kluczem. Niczego nie zapisano.', 'duplicate_key');
        }

        return $matches[0] ?? null;
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) {
            return true;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }

        return $a === $b;
    }

    public function write(DataSource $source, SyncOperation $operation, array $schema): array
    {
        $role = $operation->entity_type;
        $table = $schema['roles'][$role] ?? null;
        if (! $table) {
            throw new IntegrationException('Brak zakładki odpowiadającej kolekcji danych.', 'schema_incompatible');
        }
        $fields = SyncFieldMap::fields($role);
        $keyColumn = $fields['externalKey'] ?? '';
        $payload = $operation->payload;
        $key = (string) ($payload['externalKey'] ?? $this->entityKey($operation));
        if (! $key || ! isset($table['headers'][$keyColumn])) {
            throw new IntegrationException('Brak stabilnego klucza rekordu.', 'schema_incompatible');
        }
        $rowIndex = $this->locate($source, $table, $keyColumn, $key);
        $new = $rowIndex === null;
        $row = $new ? [] : ($this->gateway->grid($source, $table['title'], ($rowIndex + 1).':'.($rowIndex + 1))[0]['values'] ?? []);
        $values = [];
        $unsupported = [];
        $conflicts = [];
        // The identity is also required for updates whose payload only contains changed fields.
        $payload['externalKey'] = $key;
        foreach ($payload as $field => $value) {
            if (in_array($field, SyncFieldMap::INTERNAL, true) && ! isset($fields[$field])) {
                continue;
            }
            $column = $fields[$field] ?? null;
            if (! $column || ! isset($table['headers'][$column])) {
                if ($value !== null && $value !== '' && $value !== []) {
                    $unsupported[] = $field;
                }

                continue;
            }
            $columnIndex = $table['headers'][$column];
            $cell = $row[$columnIndex] ?? [];
            if (isset($table['formulas'][$column]) || isset($cell['userEnteredValue']['formulaValue'])) {
                if ($role === 'applications' && in_array($column, ['company', 'position', 'offer_url', 'cv_sent', 'cv_sent_at', 'application_stage'], true)) {
                    continue;
                }
                throw new IntegrationException('Pole '.$column.' jest wyliczane. Zapis nie naruszył formuły.', 'formula_protected');
            }
            $rule = $cell['dataValidation'] ?? $table['validations'][$column] ?? [];
            $desired = $this->serialize($field, $value, $schema['timezone'], $rule);
            // Blank defaults of new drafts are not explicit requests to clear template values.
            if ($new && $field !== 'externalKey' && ($desired === null || $desired === '')) {
                continue;
            }
            $this->validate($desired, $rule);
            $remote = $this->cellValue($cell);
            $base = $this->serialize($field, $operation->base_values[$field] ?? null, $schema['timezone'], $rule);
            if (! $new && $field !== 'externalKey' && ! $this->same($remote, $desired) && ! $this->same($remote, $base)) {
                $conflicts[$field] = ['base' => $operation->base_values[$field] ?? null, 'local' => $value, 'remote' => $this->domainValue($field, $remote, $schema['timezone'])];
            }
            $values[$columnIndex] = ['field' => $field, 'value' => $desired, 'changed' => $new || ! $this->same($remote, $desired)];
        }
        if ($conflicts) {
            return ['conflicts' => $conflicts, 'unsupported' => $unsupported];
        }
        // A moved/renamed column between inspection and mutation must stop the operation.
        $freshHeaders = $this->gateway->grid($source, $table['title'], $table['header_row'].':'.$table['header_row']);
        foreach ($table['headers'] as $header => $index) {
            if (($freshHeaders[0]['values'][$index]['effectiveValue']['stringValue'] ?? $freshHeaders[0]['values'][$index]['userEnteredValue']['stringValue'] ?? '') !== $header) {
                throw new IntegrationException('Nagłówki zmieniły się podczas operacji. Sprawdź schemat.', 'schema_changed');
            }
        }
        if ($new) {
            $cells = array_fill(0, max(array_keys($values)) + 1, []);
            foreach ($values as $index => $item) {
                $cells[$index] = ['userEnteredValue' => $this->entered($item['value'])];
            }
            // appendCells atomically chooses a new row; parallel clients cannot reserve
            // the same empty template row. Retries first locate the stable key above.
            $this->gateway->request($source, 'POST', ':batchUpdate', ['requests' => [['appendCells' => ['sheetId' => $table['sheet_id'], 'rows' => [['values' => $cells]], 'fields' => 'userEnteredValue']]]]);
            $rowIndex = $this->locate($source, $table, $keyColumn, $key);
            if ($rowIndex === null) {
                throw new IntegrationException('Nie potwierdzono dopisania rekordu. Kolejka zachowuje operację.', 'verification_failed');
            }
        } else {
            $requests = [];
            foreach ($values as $index => $item) {
                if ($item['changed']) {
                    $requests[] = ['updateCells' => ['range' => ['sheetId' => $table['sheet_id'], 'startRowIndex' => $rowIndex, 'endRowIndex' => $rowIndex + 1, 'startColumnIndex' => $index, 'endColumnIndex' => $index + 1], 'rows' => [['values' => [['userEnteredValue' => $this->entered($item['value'])]]]], 'fields' => 'userEnteredValue']];
                }
            }
            if ($requests) {
                $this->gateway->request($source, 'POST', ':batchUpdate', ['requests' => $requests]);
            }
        }
        $verified = $this->gateway->grid($source, $table['title'], ($rowIndex + 1).':'.($rowIndex + 1))[0]['values'] ?? [];
        $formulaRequests = [];
        foreach (($new || str_starts_with($key, 'LOCAL_') ? $table['formulas'] : []) as $column => $template) {
            $index = $table['headers'][$column];
            $cell = $verified[$index] ?? [];
            if (! $template['array'] && empty($cell['userEnteredValue']) && ! isset($cell['effectiveValue'])) {
                $formulaRequests[] = ['copyPaste' => ['source' => ['sheetId' => $table['sheet_id'], 'startRowIndex' => $template['row'], 'endRowIndex' => $template['row'] + 1, 'startColumnIndex' => $index, 'endColumnIndex' => $index + 1], 'destination' => ['sheetId' => $table['sheet_id'], 'startRowIndex' => $rowIndex, 'endRowIndex' => $rowIndex + 1, 'startColumnIndex' => $index, 'endColumnIndex' => $index + 1], 'pasteType' => 'PASTE_FORMULA']];
            }
        }
        if ($formulaRequests) {
            $this->gateway->request($source, 'POST', ':batchUpdate', ['requests' => $formulaRequests]);
            $verified = $this->gateway->grid($source, $table['title'], ($rowIndex + 1).':'.($rowIndex + 1))[0]['values'] ?? [];
        }
        foreach ($values as $index => $item) {
            if (! $this->same($this->cellValue($verified[$index] ?? []), $item['value'])) {
                throw new IntegrationException('Ponowny odczyt nie potwierdził wartości. Operacja nie została oznaczona jako zakończona.', 'verification_failed');
            }
        }

        return ['conflicts' => [], 'unsupported' => $unsupported, 'key' => $key];
    }

    private function entityKey(SyncOperation $operation): string
    {
        $model = EntityRegistry::MODELS[$operation->entity_type] ?? null;

        return $model ? (string) $model::find($operation->entity_id)?->external_key : '';
    }

    private function domainValue(string $field, mixed $value, string $timezone): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (SyncFieldMap::isDate($field)) {
            return SheetValueNormalizer::date($value, $timezone, $field);
        }
        if (in_array($field, ['favorite', 'hidden', 'watchOffer', 'cvSent', 'requiresAction'], true)) {
            return SheetValueNormalizer::boolean($value, $field);
        }
        if ($field === 'archived') {
            return $value === 'Archiwalny';
        }

        return $value;
    }
}
