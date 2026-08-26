<?php
require_once __DIR__ . "/config.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    $mysql->set_charset(DB_CHARSET);
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed. Is MySQL running in XAMPP, and has database.sql been imported?",
    ]);
    exit;
}
