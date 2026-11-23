<?php
function compareIdentifierSources(array $identifierIndex): array
{
    $groups = [];
    foreach ($identifierIndex as $entry) $groups[$entry['normalized_value']][] = $entry;

    $duplicates = [];
    foreach ($groups as $normalized => $entries) {
        if (count($entries) < 2) continue;
        $sheets = [];
        $spreadsheets = [];
        foreach ($entries as $entry) {
            $sheets[$entry['spreadsheet_id'] . ':' . $entry['sheet_id']] = true;
            $spreadsheets[$entry['spreadsheet_id']] = true;
        }
        $duplicates[] = [
            'normalized_value' => $normalized,
            'occurrence_count' => count($entries),
            'worksheet_count' => count($sheets),
            'spreadsheet_count' => count($spreadsheets),
            'review_note' => 'Possible repeated identifier only; confirm identifier type and asset identity before linking or merging.',
            'occurrences' => $entries
        ];
    }
    usort($duplicates, fn($a, $b) => $b['occurrence_count'] <=> $a['occurrence_count']);
    return [
        'candidate_count' => count($identifierIndex),
        'unique_normalized_candidate_count' => count($groups),
        'duplicate_groups' => $duplicates
    ];
}
