<?php
require_once __DIR__ . "/../includes/connection.php";
require_once __DIR__ . "/../includes/session_helpers.php";
require_once __DIR__ . "/../includes/functions.php";

$action = $_GET["action"] ?? null;
if ($action === null) send_error("Missing 'action' parameter.", 400);

switch ($action) {
    case "report_price":
        action_report_price($mysql);
        break;
    case "list_reports":
        action_list_reports($mysql);
        break;
    case "list_my_reports":
        action_list_my_reports($mysql);
        break;
    case "override_price_report":
        action_override_price_report($mysql);
        break;
    case "flag_report":
        action_flag_report($mysql);
        break;
    default:
        send_error("Unknown action.", 404);
}

function action_report_price($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $input = get_json_input();
    $missing = require_fields($input, ["listing_id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $listingId = $input["listing_id"];
    $reason = isset($input["reason"]) ? trim($input["reason"]) : null;
    if ($reason === "") {
        $reason = null;
    }
    $selfId = current_user_id();

    $stmt = $mysql->prepare("SELECT seller_id FROM listings WHERE id = ?");
    $stmt->bind_param("i", $listingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    if ($row === null) {
        send_error("Listing not found.", 404);
    }
    if ((int)$row[0] === $selfId) {
        send_error("You can't report your own listing.", 403);
    }

    $stmt = $mysql->prepare(
        "SELECT id FROM price_reports WHERE listing_id = ? AND reported_by = ? AND status = 'pending'"
    );
    $stmt->bind_param("ii", $listingId, $selfId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row() !== null) {
        send_error("You already reported this listing.", 409);
    }

    $stmt = $mysql->prepare(
        "INSERT INTO price_reports (listing_id, reported_by, reason, status, created_at) VALUES (?, ?, ?, 'pending', NOW())"
    );
    $stmt->bind_param("iis", $listingId, $selfId, $reason);
    $stmt->execute();

    send_json(["price_report" => [
        "id" => (int)$mysql->insert_id,
        "listing_id" => (int)$listingId,
        "reason" => $reason,
        "status" => "pending",
    ]], 201);
}

function action_list_reports($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $statusFilter = $_GET["statusFilter"] ?? "pending";
    if (!in_array($statusFilter, ["pending", "resolved"], true)) {
        send_error("Invalid statusFilter.", 400);
    }

    $stmt = $mysql->prepare(
        "SELECT pr.id, pr.reason, pr.status, pr.created_at, pr.admin_response,
                l.id AS listing_id, l.asking_price, l.ai_suggested_price,
                b.title, b.author, u.name AS reporter_name
         FROM price_reports pr
         JOIN listings l ON pr.listing_id = l.id
         JOIN books b ON l.book_id = b.id
         JOIN users u ON pr.reported_by = u.id
         WHERE pr.status = ?
         ORDER BY pr.created_at DESC"
    );
    $stmt->bind_param("s", $statusFilter);
    $stmt->execute();

    send_json(["reports" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

function action_list_my_reports($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $statusFilter = $_GET["statusFilter"] ?? "pending";
    if (!in_array($statusFilter, ["pending", "resolved"], true)) {
        send_error("Invalid statusFilter.", 400);
    }

    $selfId = current_user_id();
    $stmt = $mysql->prepare(
        "SELECT pr.id, pr.listing_id, pr.reason, pr.status, pr.admin_response,
                pr.created_at, pr.resolved_at, b.title, l.asking_price
         FROM price_reports pr
         JOIN listings l ON pr.listing_id = l.id
         JOIN books b ON l.book_id = b.id
         WHERE l.seller_id = ? AND pr.status = ?
         ORDER BY pr.created_at DESC"
    );
    $stmt->bind_param("is", $selfId, $statusFilter);
    $stmt->execute();

    send_json(["reports" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

function action_override_price_report($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["id", "override_price"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }
    if (!is_numeric($input["override_price"]) || (float)$input["override_price"] < 0) {
        send_error("Override price must be a non-negative number.", 400);
    }

    $id = $input["id"];
    $overridePrice = (float)$input["override_price"];
    $adminResponse = isset($input["admin_response"]) ? trim($input["admin_response"]) : null;
    if ($adminResponse === "") {
        $adminResponse = null;
    }

    $stmt = $mysql->prepare("SELECT listing_id, status FROM price_reports WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc();

    if ($report === null) {
        send_error("Dispute not found.", 404);
    }
    if ($report["status"] !== "pending") {
        send_error("This dispute has already been resolved.", 409);
    }

    $listingId = $report["listing_id"];

    $stmt = $mysql->prepare("UPDATE listings SET asking_price = ? WHERE id = ?");
    $stmt->bind_param("di", $overridePrice, $listingId);
    $stmt->execute();

    $stmt = $mysql->prepare(
        "UPDATE price_reports SET status = 'resolved', admin_response = ?, resolved_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param("si", $adminResponse, $id);
    $stmt->execute();

    send_json(["price_report" => [
        "id" => (int)$id,
        "listing_id" => (int)$listingId,
        "status" => "resolved",
        "admin_response" => $adminResponse,
        "new_asking_price" => $overridePrice,
    ]]);
}

function action_flag_report($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["id", "admin_response"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $id = $input["id"];
    $adminResponse = trim($input["admin_response"]);
    if ($adminResponse === "") {
        send_error("admin_response is required.", 400);
    }

    $stmt = $mysql->prepare("SELECT status FROM price_reports WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    if ($row === null) {
        send_error("Dispute not found.", 404);
    }
    if ($row[0] !== "pending") {
        send_error("This dispute has already been resolved.", 409);
    }

    $stmt = $mysql->prepare(
        "UPDATE price_reports SET status = 'resolved', admin_response = ?, resolved_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param("si", $adminResponse, $id);
    $stmt->execute();

    send_json(["price_report" => [
        "id" => (int)$id,
        "status" => "resolved",
        "admin_response" => $adminResponse,
    ]]);
}
