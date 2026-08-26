<?php
require_once __DIR__ . "/../includes/connection.php";
require_once __DIR__ . "/../includes/session_helpers.php";
require_once __DIR__ . "/../includes/functions.php";

$action = $_GET["action"] ?? null;
if ($action === null) send_error("Missing 'action' parameter.", 400);

switch ($action) {
    case "dashboard_summary":
        action_dashboard_summary($mysql);
        break;
    default:
        send_error("Unknown action.", 404);
}

function action_dashboard_summary($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $schoolId = current_user_school_id($mysql);

    $stmt = $mysql->prepare(
        "SELECT COUNT(*) FROM children c JOIN users u ON c.parent_id = u.id WHERE u.school_id = ?"
    );
    $stmt->bind_param("i", $schoolId);
    $stmt->execute();
    $totalChildren = (int)$stmt->get_result()->fetch_row()[0];

    $stmt = $mysql->prepare(
        "SELECT l.status, COUNT(*) AS total
         FROM listings l
         JOIN users u ON l.seller_id = u.id
         WHERE u.school_id = ? AND l.status IN ('active', 'sold')
         GROUP BY l.status"
    );
    $stmt->bind_param("i", $schoolId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $activeListings = 0;
    $soldListings = 0;
    foreach ($rows as $row) {
        if ($row["status"] === "active") {
            $activeListings = (int)$row["total"];
        } elseif ($row["status"] === "sold") {
            $soldListings = (int)$row["total"];
        }
    }

    send_json([
        "total_children" => $totalChildren,
        "active_listings" => $activeListings,
        "sold_listings" => $soldListings,
    ]);
}
