
<?php

require_once __DIR__ . '/connection.php';

header('Content-Type: text/plain; charset=utf-8');

$service = getGoogleSheetsService();

$spreadsheets = [
    'Consolidated Trucks' => SHEET_CONSOLIDATED_ID,
    'Ngwerere / 21 Miles Trucks' => SHEET_NGWERERE_21MILES_ID
];

function columnLetter($number)
{
    $letter = '';

    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $letter = chr(65 + $remainder) . $letter;
        $number = (int)(($number - 1) / 26);
    }

    return $letter;
}

foreach ($spreadsheets as $spreadsheetName => $spreadsheetId) {

    echo "\n\n";
    echo "########################################\n";
    echo "SPREADSHEET: $spreadsheetName\n";
    echo "########################################\n";

    try {

        $metadata = $service->spreadsheets->get(
            $spreadsheetId,
            ['fields' => 'sheets.properties']
        );

        foreach ($metadata->getSheets() as $sheet) {

            $sheetName = $sheet->getProperties()->getTitle();

            echo "\n----------------------------------------\n";
            echo "WORKSHEET: $sheetName\n";
            echo "----------------------------------------\n";

            $escapedName = str_replace("'", "''", $sheetName);
            $range = "'" . $escapedName . "'!A1:Z1000";

            try {

                $response = $service->spreadsheets_values->get(
                    $spreadsheetId,
                    $range
                );

                $rows = $response->getValues() ?? [];

                $populatedCount = 0;
                $printedCount = 0;

                foreach ($rows as $rowIndex => $row) {

                    $cells = [];

                    foreach ($row as $columnIndex => $value) {

                        $value = trim((string)$value);

                        if ($value === '') {
                            continue;
                        }

                        $cells[] = columnLetter($columnIndex + 1)
                            . '=' . $value;
                    }

                    if (empty($cells)) {
                        continue;
                    }

                    $populatedCount++;

                    // Display first 15 populated rows only
                    if ($printedCount < 15) {

                        echo "Row " . ($rowIndex + 1) . ": ";

                        echo implode(" | ", $cells) . "\n";

                        $printedCount++;
                    }
                }

                echo "\nTotal populated rows: $populatedCount\n";

                if ($populatedCount > 15) {
                    echo "Additional rows omitted from preview.\n";
                }

            } catch (Throwable $e) {

                echo "READ ERROR: " . $e->getMessage() . "\n";
            }
        }

    } catch (Throwable $e) {

        echo "SPREADSHEET ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n\nINSPECTION COMPLETED.\n";
