<?php
require_once __DIR__ . "/../includes/connection.php";
require_once __DIR__ . "/../includes/session_helpers.php";
require_once __DIR__ . "/../includes/functions.php";

$action = $_GET["action"] ?? null;
if ($action === null) send_error("Missing 'action' parameter.", 400);

switch ($action) {
    case "list_conversations":
        action_list_conversations($mysql);
        break;
    case "list_thread":
        action_list_thread($mysql);
        break;
    case "send_message":
        action_send_message($mysql);
        break;
    default:
        send_error("Unknown action.", 404);
}

function action_list_conversations($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $selfId = current_user_id();

    $stmt = $mysql->prepare(
        "SELECT listing_id,
                CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS other_user_id,
                MAX(created_at) AS last_message_at
         FROM messages
         WHERE sender_id = ? OR receiver_id = ?
         GROUP BY listing_id, other_user_id
         ORDER BY last_message_at DESC"
    );
    $stmt->bind_param("iii", $selfId, $selfId, $selfId);
    $stmt->execute();
    $pairs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $conversations = [];
    foreach ($pairs as $pair) {
        $listingId = $pair["listing_id"];
        $otherUserId = $pair["other_user_id"];

        $stmt = $mysql->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->bind_param("i", $otherUserId);
        $stmt->execute();
        $otherUserName = $stmt->get_result()->fetch_row()[0] ?? null;

        $stmt = $mysql->prepare(
            "SELECT b.title FROM listings l JOIN books b ON l.book_id = b.id WHERE l.id = ?"
        );
        $stmt->bind_param("i", $listingId);
        $stmt->execute();
        $listingTitle = $stmt->get_result()->fetch_row()[0] ?? null;

        $stmt = $mysql->prepare(
            "SELECT content, created_at FROM messages
             WHERE listing_id = ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->bind_param("iiiii", $listingId, $selfId, $otherUserId, $otherUserId, $selfId);
        $stmt->execute();
        $lastMessage = $stmt->get_result()->fetch_assoc();

        $stmt = $mysql->prepare(
            "SELECT COUNT(*) FROM messages WHERE listing_id = ? AND receiver_id = ? AND sender_id = ? AND is_read = 0"
        );
        $stmt->bind_param("iii", $listingId, $selfId, $otherUserId);
        $stmt->execute();
        $unreadCount = (int)$stmt->get_result()->fetch_row()[0];

        $conversations[] = [
            "listing_id" => (int)$listingId,
            "other_user_id" => (int)$otherUserId,
            "other_user_name" => $otherUserName,
            "listing_title" => $listingTitle,
            "last_message" => $lastMessage["content"] ?? null,
            "last_message_at" => $lastMessage["created_at"] ?? null,
            "unread_count" => $unreadCount,
        ];
    }

    send_json(["conversations" => $conversations]);
}

function action_list_thread($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $missing = require_fields($_GET, ["listing_id", "other_user_id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $listingId = $_GET["listing_id"];
    $otherUserId = $_GET["other_user_id"];
    $selfId = current_user_id();

    $stmt = $mysql->prepare(
        "SELECT id, sender_id, receiver_id, content, is_read, created_at
         FROM messages
         WHERE listing_id = ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
         ORDER BY created_at ASC"
    );
    $stmt->bind_param("iiiii", $listingId, $selfId, $otherUserId, $otherUserId, $selfId);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt = $mysql->prepare(
        "UPDATE messages SET is_read = 1 WHERE listing_id = ? AND sender_id = ? AND receiver_id = ? AND is_read = 0"
    );
    $stmt->bind_param("iii", $listingId, $otherUserId, $selfId);
    $stmt->execute();

    send_json(["messages" => $messages]);
}

function action_send_message($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $input = get_json_input();
    $missing = require_fields($input, ["listing_id", "receiver_id", "content"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $listingId = $input["listing_id"];
    $receiverId = $input["receiver_id"];
    $content = trim($input["content"]);
    $selfId = current_user_id();

    if ($content === "") {
        send_error("Message can't be empty.", 400);
    }
    if ((int)$receiverId === $selfId) {
        send_error("You can't message yourself.", 400);
    }

    $stmt = $mysql->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->bind_param("i", $receiverId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row() === null) {
        send_error("Recipient not found.", 404);
    }

    $stmt = $mysql->prepare(
        "INSERT INTO messages (listing_id, sender_id, receiver_id, content, is_read, created_at)
         VALUES (?, ?, ?, ?, 0, NOW())"
    );
    $stmt->bind_param("iiis", $listingId, $selfId, $receiverId, $content);
    $stmt->execute();

    send_json(["message" => [
        "id" => (int)$mysql->insert_id,
        "listing_id" => (int)$listingId,
        "sender_id" => $selfId,
        "receiver_id" => (int)$receiverId,
        "content" => $content,
    ]], 201);
}
