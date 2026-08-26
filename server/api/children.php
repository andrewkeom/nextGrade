<?php
require_once __DIR__ . "/../includes/connection.php";
require_once __DIR__ . "/../includes/session_helpers.php";
require_once __DIR__ . "/../includes/functions.php";

$action = $_GET["action"] ?? null;
if ($action === null) send_error("Missing 'action' parameter.", 400);

switch ($action) {
    case "list_children":
        action_list_children($mysql);
        break;
    case "add_child":
        action_add_child($mysql);
        break;
    case "update_child":
        action_update_child($mysql);
        break;
    case "delete_child":
        action_delete_child($mysql);
        break;
    default:
        send_error("Unknown action.", 404);
}

function action_list_children($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $selfId = current_user_id();
    $stmt = $mysql->prepare(
        "SELECT c.id, c.name, c.grade_level_id, gl.name AS grade_level_name
         FROM children c
         JOIN grade_levels gl ON c.grade_level_id = gl.id
         WHERE c.parent_id = ?
         ORDER BY c.id"
    );
    $stmt->bind_param("i", $selfId);
    $stmt->execute();

    send_json(["children" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

function action_add_child($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $input = get_json_input();
    $missing = require_fields($input, ["name", "grade_level_id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    try {
        $childId = insert_child(
            $mysql,
            current_user_id(),
            $input["name"],
            (int)$input["grade_level_id"],
            current_user_school_id($mysql)
        );
    } catch (InvalidArgumentException $e) {
        send_error($e->getMessage(), 400);
    }

    send_json(["child" => [
        "id" => $childId,
        "name" => trim($input["name"]),
        "grade_level_id" => (int)$input["grade_level_id"],
    ]], 201);
}

function action_update_child($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $input = get_json_input();
    $missing = require_fields($input, ["id", "name", "grade_level_id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $id = $input["id"];
    $stmt = $mysql->prepare("SELECT parent_id FROM children WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $parentId = $row === null ? null : $row[0];

    if ($parentId === null) {
        send_error("Child not found.", 404);
    }
    if ((int)$parentId !== current_user_id()) {
        send_error("You don't have permission to edit this child.", 403);
    }

    $name = trim($input["name"]);
    if ($name === "") {
        send_error("Child name is required.", 400);
    }

    $gradeLevelId = $input["grade_level_id"];
    $schoolId = current_user_school_id($mysql);
    $stmt = $mysql->prepare("SELECT id FROM grade_levels WHERE id = ? AND school_id = ?");
    $stmt->bind_param("ii", $gradeLevelId, $schoolId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row() === null) {
        send_error("Grade level not found.", 404);
    }

    $stmt = $mysql->prepare("UPDATE children SET name = ?, grade_level_id = ? WHERE id = ?");
    $stmt->bind_param("sii", $name, $gradeLevelId, $id);
    $stmt->execute();

    send_json(["child" => [
        "id" => (int)$id,
        "name" => $name,
        "grade_level_id" => (int)$gradeLevelId,
    ]]);
}

function action_delete_child($mysql) {
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
    $stmt = $mysql->prepare("SELECT parent_id FROM children WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $parentId = $row === null ? null : $row[0];

    if ($parentId === null) {
        send_error("Child not found.", 404);
    }
    if ((int)$parentId !== current_user_id()) {
        send_error("You don't have permission to delete this child.", 403);
    }

    $stmt = $mysql->prepare("DELETE FROM children WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    send_json(["deleted" => true]);
}
