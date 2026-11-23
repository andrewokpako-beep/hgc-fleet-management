<?php
function normalizeFleetIdentifier(string $value): string
{
    $value = trim($value);
    $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    // Remove common separators only. Preserve all letters/digits and suffixes (e.g. ZM).
    return preg_replace('/[\s.\-\/_]+/u', '', $value) ?? $value;
}

function fleetTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function looksLikeIdentifierCandidate(string $value): bool
{
    $value = trim($value);
    if ($value === '' || fleetTextLength($value) < 5 || fleetTextLength($value) > 40) return false;
    if (preg_match('/^(ACTIVE|PARKING|MOVING|IN\s+TRIP|NO\s+CONNECTION|DASHBOARD|TOTAL|MAKE|COMMENT)$/i', $value)) return false;
    $key = normalizeFleetIdentifier($value);
    return $key !== ''
        && preg_match('/[A-Z]/', $key)
        && preg_match('/[0-9]/', $key)
        && preg_match('/^[A-Z0-9]{5,40}$/', $key);
}

function buildIdentifierIndex(array $profile): array
{
    $index = [];
    foreach ($profile['worksheets'] as $sheet) {
        foreach ($sheet['rows'] as $row) {
            foreach ($row['values'] as $columnIndex => $rawValue) {
                if (!is_scalar($rawValue)) continue;
                $raw = trim((string)$rawValue);
                if (!looksLikeIdentifierCandidate($raw)) continue;
                $index[] = [
                    'normalized_value' => normalizeFleetIdentifier($raw),
                    'raw_value' => $raw,
                    'source_code' => $sheet['source_code'],
                    'source_name' => $sheet['source_name'],
                    'spreadsheet_id' => $sheet['spreadsheet_id'],
                    'sheet_id' => $sheet['sheet_id'],
                    'sheet_name' => $sheet['sheet_name'],
                    'source_row_number' => $row['source_row_number'],
                    'column_number' => $columnIndex + 1,
                    'column_letter' => fleetColumnLetter($columnIndex + 1)
                ];
            }
        }
    }
    return $index;
}
