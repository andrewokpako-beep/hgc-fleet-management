<?php

require_once __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../vendor/autoload.php';

function getGoogleSheetsService()
{
    if (!file_exists(GOOGLE_CREDENTIALS_FILE)) {
        throw new RuntimeException(
            'Fleet Google credentials file not found.'
        );
    }

    $client = new Google\Client();

    $client->setApplicationName(APP_NAME);

    $client->setAuthConfig(GOOGLE_CREDENTIALS_FILE);

    $client->setScopes([
        Google\Service\Sheets::SPREADSHEETS_READONLY
    ]);

    return new Google\Service\Sheets($client);
}

function getGoogleSheetsWriterService()
{
    if (!file_exists(GOOGLE_CREDENTIALS_FILE)) {
        throw new RuntimeException('Fleet Google credentials file not found.');
    }

    $client = new Google\Client();
    $client->setApplicationName(APP_NAME . ' certificate register sync');
    $client->setAuthConfig(GOOGLE_CREDENTIALS_FILE);
    $client->setScopes([Google\Service\Sheets::SPREADSHEETS]);

    return new Google\Service\Sheets($client);
}
