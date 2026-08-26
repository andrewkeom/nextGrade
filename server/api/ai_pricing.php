<?php
require_once __DIR__ . "/../includes/connection.php";
require_once __DIR__ . "/../includes/session_helpers.php";
require_once __DIR__ . "/../includes/functions.php";

$action = $_GET["action"] ?? null;
if ($action === null) send_error("Missing 'action' parameter.", 400);

switch ($action) {
    case "suggest_price":
        action_suggest_price($mysql);
        break;
    default:
        send_error("Unknown action.", 404);
}

function action_suggest_price($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $missing = require_fields($_GET, ["book_id", "condition"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $bookId = $_GET["book_id"];
    $condition = $_GET["condition"];

    if (!in_array($condition, ["New", "Like New", "Good", "Fair", "Poor"], true)) {
        send_error("Invalid condition.", 400);
    }

    $suggestion = get_ai_price_suggestion($mysql, $bookId, $condition);

    send_json($suggestion);
}
