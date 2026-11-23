
<?php

require_once __DIR__ . '/connection.php';

$spreadsheets = [
    'Consolidated Trucks' => SHEET_CONSOLIDATED_ID,
    'Ngwerere / 21 Miles Trucks' => SHEET_NGWERERE_21MILES_ID
];

header('Content-Type: text/plain; charset=utf-8');

$service = getGoogleSheetsService();

foreach ($spreadsheets as $name => $spreadsheetId) {

    echo "\n====================================\n";
    echo "SPREADSHEET: $name\n";
    echo "====================================\n";

    try {
        $spreadsheet = $service->spreadsheets->get(
            $spreadsheetId,
            ['fields' => 'spreadsheetId,properties.title,sheets.properties']
        );

        echo "Connection: SUCCESS\n";
        echo "Title: " . $spreadsheet->getProperties()->getTitle() . "\n";

        foreach ($spreadsheet->getSheets() as $sheet) {

            $properties = $sheet->getProperties();

            echo "\nWorksheet: " . $properties->getTitle() . "\n";
            echo "Sheet ID: " . $properties->getSheetId() . "\n";
            echo "Rows: " . $properties->getGridProperties()->getRowCount() . "\n";
            echo "Columns: " . $properties->getGridProperties()->getColumnCount() . "\n";
        }

    } catch (Throwable $e) {

        echo "Connection: FAILED\n";
        echo "Error: " . $e->getMessage() . "\n";
    }
}

