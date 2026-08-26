<?php
require_once __DIR__ . "/../includes/connection.php";
require_once __DIR__ . "/../includes/session_helpers.php";
require_once __DIR__ . "/../includes/functions.php";

$VALID_CONDITIONS = ["New", "Like New", "Good", "Fair", "Poor"];
$VALID_LISTING_TYPES = ["sell", "trade", "donate"];

$action = $_GET["action"] ?? null;
if ($action === null) send_error("Missing 'action' parameter.", 400);

switch ($action) {
    case "list_listings":
        action_list_listings($mysql);
        break;
    case "get_listing":
        action_get_listing($mysql);
        break;
    case "create_listing":
        action_create_listing($mysql);
        break;
    case "update_listing":
        action_update_listing($mysql);
        break;
    case "mark_sold":
        action_mark_sold($mysql);
        break;
    case "delete_listing":
        action_delete_listing($mysql);
        break;
    case "list_my_listings":
        action_list_my_listings($mysql);
        break;
    default:
        send_error("Unknown action.", 404);
}

function action_list_listings($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }

    $q = trim($_GET["q"] ?? "");

    $sql = "SELECT l.id, l.condition, l.listing_type, l.asking_price, l.ai_suggested_price,
                   b.title, b.author, s.name AS subject_name, gl.name AS grade_level_name, b.reference_price,
                   u.name AS seller_name, b.cover_image AS book_cover_image,
                   (SELECT image_path FROM listing_images WHERE listing_id = l.id ORDER BY id LIMIT 1) AS thumbnail
            FROM listings l
            JOIN books b ON l.book_id = b.id
            JOIN subjects s ON b.subject_id = s.id
            JOIN grade_levels gl ON s.grade_level_id = gl.id
            JOIN users u ON l.seller_id = u.id
            WHERE l.status = 'active'";

    if ($q !== "") {
        $sql .= " AND (b.title LIKE ? OR b.author LIKE ?)";
        $stmt = $mysql->prepare($sql . " ORDER BY l.created_at DESC");
        $like = "%{$q}%";
        $stmt->bind_param("ss", $like, $like);
    } else {
        $stmt = $mysql->prepare($sql . " ORDER BY l.created_at DESC");
    }
    $stmt->execute();

    send_json(["listings" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

function action_get_listing($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }

    $id = $_GET["id"] ?? null;
    if ($id === null || !is_numeric($id)) {
        send_error("id is required.", 400);
    }

    $stmt = $mysql->prepare(
        "SELECT l.id, l.book_id, l.seller_id, l.condition, l.listing_type, l.asking_price,
                l.ai_suggested_price, l.ai_justification, l.status, l.description, l.created_at,
                b.title, b.author, b.edition, b.reference_price, b.cover_image AS book_cover_image,
                s.id AS subject_id, s.name AS subject_name,
                gl.id AS grade_level_id, gl.name AS grade_level_name,
                u.name AS seller_name
         FROM listings l
         JOIN books b ON l.book_id = b.id
         JOIN subjects s ON b.subject_id = s.id
         JOIN grade_levels gl ON s.grade_level_id = gl.id
         JOIN users u ON l.seller_id = u.id
         WHERE l.id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();

    if ($listing === null) {
        send_error("Listing not found.", 404);
    }

    $stmt = $mysql->prepare("SELECT id, image_path FROM listing_images WHERE listing_id = ? ORDER BY id");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $listing["images"] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    send_json(["listing" => $listing]);
}

function action_list_my_listings($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $statusFilter = $_GET["statusFilter"] ?? "all";
    $sellerId = current_user_id();

    $sql = "SELECT l.id, l.condition, l.listing_type, l.asking_price, l.status, b.title, b.author
            FROM listings l JOIN books b ON l.book_id = b.id
            WHERE l.seller_id = ?";

    if ($statusFilter !== "all") {
        $stmt = $mysql->prepare($sql . " AND l.status = ? ORDER BY l.created_at DESC");
        $stmt->bind_param("is", $sellerId, $statusFilter);
    } else {
        $stmt = $mysql->prepare($sql . " ORDER BY l.created_at DESC");
        $stmt->bind_param("i", $sellerId);
    }
    $stmt->execute();

    send_json(["listings" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

function action_create_listing($mysql) {
    global $VALID_CONDITIONS, $VALID_LISTING_TYPES;

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $missing = require_fields($_POST, ["book_id", "condition", "listingType"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $bookId = $_POST["book_id"];
    $condition = $_POST["condition"];
    $listingType = $_POST["listingType"];
    $description = trim($_POST["description"] ?? "");

    if (!in_array($condition, $VALID_CONDITIONS, true)) {
        send_error("Invalid condition.", 400);
    }
    if (!in_array($listingType, $VALID_LISTING_TYPES, true)) {
        send_error("Invalid listing type.", 400);
    }

    $askingPrice = null;
    if ($listingType === "sell") {
        if (!isset($_POST["askingPrice"]) || $_POST["askingPrice"] === "" || !is_numeric($_POST["askingPrice"]) || (float)$_POST["askingPrice"] < 0) {
            send_error("Asking price is required for a sell listing.", 400);
        }
        $askingPrice = (float)$_POST["askingPrice"];
    }

    $schoolId = get_book_school_id($mysql, $bookId);
    if ($schoolId === null) {
        send_error("Book not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to list this book.", 403);
    }

    $suggestion = get_ai_price_suggestion($mysql, $bookId, $condition);
    $sellerId = current_user_id();
    $suggestedPrice = $suggestion["suggested_price"];
    $justification = $suggestion["justification"];

    $stmt = $mysql->prepare(
        "INSERT INTO listings (book_id, seller_id, `condition`, listing_type, asking_price, ai_suggested_price, ai_justification, status, description)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)"
    );
    $stmt->bind_param(
        "iissddss",
        $bookId, $sellerId, $condition, $listingType, $askingPrice, $suggestedPrice, $justification, $description
    );
    $stmt->execute();
    $listingId = (int)$mysql->insert_id;

    $imagePaths = save_uploaded_listing_images("listingImages", __DIR__ . "/../../uploads/listings");
    $stmt = $mysql->prepare("INSERT INTO listing_images (listing_id, image_path) VALUES (?, ?)");
    foreach ($imagePaths as $path) {
        $stmt->bind_param("is", $listingId, $path);
        $stmt->execute();
    }

    send_json(["listing" => [
        "id" => $listingId,
        "book_id" => (int)$bookId,
        "condition" => $condition,
        "listing_type" => $listingType,
        "asking_price" => $askingPrice,
        "ai_suggested_price" => $suggestedPrice,
        "ai_justification" => $justification,
        "status" => "active",
        "description" => $description,
        "images" => $imagePaths,
    ]], 201);
}

function action_update_listing($mysql) {
    global $VALID_CONDITIONS, $VALID_LISTING_TYPES;

    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $missing = require_fields($_POST, ["id", "book_id", "condition", "listingType", "status"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $id = $_POST["id"];
    $stmt = $mysql->prepare("SELECT seller_id FROM listings WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    if ($row === null) {
        send_error("Listing not found.", 404);
    }
    if ((int)$row[0] !== current_user_id()) {
        send_error("You don't have permission to edit this listing.", 403);
    }

    $bookId = $_POST["book_id"];
    $condition = $_POST["condition"];
    $listingType = $_POST["listingType"];
    $status = $_POST["status"];
    $description = trim($_POST["description"] ?? "");

    if (!in_array($condition, $VALID_CONDITIONS, true)) {
        send_error("Invalid condition.", 400);
    }
    if (!in_array($listingType, $VALID_LISTING_TYPES, true)) {
        send_error("Invalid listing type.", 400);
    }
    if (!in_array($status, ["active", "sold", "removed"], true)) {
        send_error("Invalid status.", 400);
    }

    $askingPrice = null;
    if ($listingType === "sell") {
        if (!isset($_POST["askingPrice"]) || $_POST["askingPrice"] === "" || !is_numeric($_POST["askingPrice"]) || (float)$_POST["askingPrice"] < 0) {
            send_error("Asking price is required for a sell listing.", 400);
        }
        $askingPrice = (float)$_POST["askingPrice"];
    }

    $schoolId = get_book_school_id($mysql, $bookId);
    if ($schoolId === null) {
        send_error("Book not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to list this book.", 403);
    }

    $suggestion = get_ai_price_suggestion($mysql, $bookId, $condition);
    $suggestedPrice = $suggestion["suggested_price"];
    $justification = $suggestion["justification"];

    $stmt = $mysql->prepare(
        "UPDATE listings SET book_id = ?, `condition` = ?, listing_type = ?, asking_price = ?,
                ai_suggested_price = ?, ai_justification = ?, status = ?, description = ?
         WHERE id = ?"
    );
    $stmt->bind_param(
        "isssddssi",
        $bookId, $condition, $listingType, $askingPrice, $suggestedPrice, $justification, $status, $description, $id
    );
    $stmt->execute();

    $imagePaths = save_uploaded_listing_images("listingImages", __DIR__ . "/../../uploads/listings");
    $stmt = $mysql->prepare("INSERT INTO listing_images (listing_id, image_path) VALUES (?, ?)");
    foreach ($imagePaths as $path) {
        $stmt->bind_param("is", $id, $path);
        $stmt->execute();
    }

    send_json(["listing" => [
        "id" => (int)$id,
        "book_id" => (int)$bookId,
        "condition" => $condition,
        "listing_type" => $listingType,
        "asking_price" => $askingPrice,
        "ai_suggested_price" => $suggestedPrice,
        "ai_justification" => $justification,
        "status" => $status,
        "description" => $description,
        "new_images" => $imagePaths,
    ]]);
}

function action_mark_sold($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $input = get_json_input();
    $missing = require_fields($input, ["id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $id = $input["id"];
    $stmt = $mysql->prepare("SELECT seller_id FROM listings WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    if ($row === null) {
        send_error("Listing not found.", 404);
    }
    if ((int)$row[0] !== current_user_id()) {
        send_error("You don't have permission to modify this listing.", 403);
    }

    $stmt = $mysql->prepare("UPDATE listings SET status = 'sold' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    send_json(["listing" => ["id" => (int)$id, "status" => "sold"]]);
}

function action_delete_listing($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $input = get_json_input();
    $missing = require_fields($input, ["id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $id = $input["id"];
    $stmt = $mysql->prepare("SELECT seller_id FROM listings WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    if ($row === null) {
        send_error("Listing not found.", 404);
    }
    if ((int)$row[0] !== current_user_id()) {
        send_error("You don't have permission to delete this listing.", 403);
    }

    $stmt = $mysql->prepare("UPDATE listings SET status = 'removed' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    send_json(["deleted" => true]);
}
