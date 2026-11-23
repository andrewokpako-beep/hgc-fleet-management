<?php
function buildProfilingReport(array $profile, array $identifierIndex, array $comparison): array
{
    $sheets = [];
    foreach ($profile['worksheets'] as $sheet) {
        $sheets[] = [
            'source_name' => $sheet['source_name'],
            'source_code' => $sheet['source_code'],
            'sheet_id' => $sheet['sheet_id'],
            'sheet_name' => $sheet['sheet_name'],
            'declared_grid' => $sheet['declared_grid_rows'] . ' rows x ' . $sheet['declared_grid_columns'] . ' columns',
            'populated_row_count' => $sheet['populated_row_count'],
            'non_empty_cell_count' => $sheet['non_empty_cell_count'],
            'requested_range' => $sheet['requested_range']
        ];
    }
    return [
        'report_title' => 'Fleet Management - Read-only Source Profiling',
        'generated_at' => date(DATE_ATOM),
        'run_id' => $profile['run_id'] ?? null,
        'safety' => [
            'google_sheets_write_operations' => false,
            'mysql_import_operations' => false,
            'duplicate_groups_are_confirmed_duplicates' => false
        ],
        'totals' => [
            'source_spreadsheets' => count($profile['sources']),
            'worksheets' => count($profile['worksheets']),
            'populated_rows' => array_sum(array_column($profile['worksheets'], 'populated_row_count')),
            'identifier_candidates' => count($identifierIndex),
            'possible_duplicate_groups' => count($comparison['duplicate_groups'])
        ],
        'worksheets' => $sheets,
        'identifier_comparison' => $comparison,
        'review_guidance' => [
            'Confirm header rows and column meanings for each worksheet.',
            'Confirm authoritative source for each asset field.',
            'Treat candidate duplicates as review items, not automatic merges.',
            'Keep truck heads and trailers as separate asset types.',
            'Do not treat dashboard formulas or totals as asset records.',
            'A missing source row must not automatically delete an asset.'
        ]
    ];
}
