<?php

define('APP_NAME', 'Fleet Management System');

define('DB_HOST', 'localhost');
define('DB_NAME', 'fleet_management');
define('DB_USER', 'root');
define('DB_PASS', '');

// Google credentials outside public web directory
define(
    'GOOGLE_CREDENTIALS_FILE',
    'C:/xampp/secure/fleet/google-service-account.json'
);

// Consolidated Trucks
define(
    'SHEET_CONSOLIDATED_ID',
    '1kwKwJBsX1FMfMW5C8HKiyOMsnxvsja8PuaEDdcqubPM'
);

// Ngwerere / 21 Miles Trucks
define(
    'SHEET_NGWERERE_21MILES_ID',
    '1uc36Fwf-Svg3rK6F5vdIKghn2Kx7XcVpRnNTqt4iRTg'
);

// Fleet certificate expiry register (write access is used only by the
// certificate approval sync; source spreadsheet connections remain read-only).
define(
    'SHEET_CERTIFICATE_REGISTER_ID',
    '1T3AfEdskuCcVRShzzTnfdBwZUj3xDxGWIL-BP28q3do'
);

date_default_timezone_set('Africa/Lusaka');
