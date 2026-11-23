<?php
function fleetColumnLetter(int $number): string
{
    $letter = '';
    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $letter = chr(65 + $remainder) . $letter;
        $number = intdiv($number - 1, 26);
    }
    return $letter;
}

function fleetRowHasData(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string)$value) !== '') return true;
    }
    return false;
}

function extractFleetSheets(Google\Service\Sheets $service): array
{
    $sources = [
        ['source_code' => 'CONSOLIDATED_TRUCKS', 'source_name' => 'Consolidated Trucks', 'spreadsheet_id' => SHEET_CONSOLIDATED_ID],
        ['source_code' => 'NGWERERE_21MILES', 'source_name' => 'Ngwerere / 21 Miles Trucks', 'spreadsheet_id' => SHEET_NGWERERE_21MILES_ID]
    ];
    $result = ['application' => 'Fleet Management System', 'read_only' => true, 'sources' => [], 'worksheets' => []];

    foreach ($sources as $source) {
        $metadata = $service->spreadsheets->get(
            $source['spreadsheet_id'],
            ['fields' => 'spreadsheetId,properties.title,sheets.properties']
        );
        $result['sources'][] = [
            'source_code' => $source['source_code'],
            'source_name' => $source['source_name'],
            'spreadsheet_id' => $source['spreadsheet_id'],
            'spreadsheet_title' => $metadata->getProperties()->getTitle()
        ];

        foreach ($metadata->getSheets() as $sheet) {
            $p = $sheet->getProperties();
            $title = $p->getTitle();
            // FleetDesk-owned output tabs mirror the app database and must never be re-imported as source observations.
            if (str_starts_with($title, '_FleetDesk_')) continue;
            $sheetId = (string)$p->getSheetId();
            $grid = $p->getGridProperties();
            $maxRows = max(1, (int)$grid->getRowCount());
            $maxCols = max(1, (int)$grid->getColumnCount());
            $range = "'" . str_replace("'", "''", $title) . "'!A1:" . fleetColumnLetter($maxCols) . $maxRows;

            // Fetch the full declared grid, not the 15-row diagnostic preview.
            $response = $service->spreadsheets_values->get(
                $source['spreadsheet_id'],
                $range,
                ['valueRenderOption' => 'UNFORMATTED_VALUE']
            );
            $values = $response->getValues() ?? [];
            $rows = [];
            $cells = 0;

            foreach ($values as $i => $row) {
                if (!is_array($row) || !fleetRowHasData($row)) continue;
                $rows[] = ['source_row_number' => $i + 1, 'values' => $row];
                foreach ($row as $value) {
                    if (trim((string)$value) !== '') $cells++;
                }
            }

            $result['worksheets'][] = [
                'source_code' => $source['source_code'],
                'source_name' => $source['source_name'],
                'spreadsheet_id' => $source['spreadsheet_id'],
                'sheet_id' => $sheetId,
                'sheet_name' => $title,
                'declared_grid_rows' => $maxRows,
                'declared_grid_columns' => $maxCols,
                'requested_range' => $range,
                'populated_row_count' => count($rows),
                'non_empty_cell_count' => $cells,
                'rows' => $rows
            ];
        }
    }
    return $result;
}
