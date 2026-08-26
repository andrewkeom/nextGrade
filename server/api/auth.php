<?php
require_once __DIR__ . "/../includes/connection.php";
require_once __DIR__ . "/../includes/session_helpers.php";
require_once __DIR__ . "/../includes/functions.php";

$action = $_GET["action"] ?? null;
if ($action === null) send_error("Missing 'action' parameter.", 400);

switch ($action) {
    case "login":
        action_login($mysql);
        break;
    case "signup":
        action_signup($mysql);
        break;
    case "logout":
        action_logout();
        break;
    case "check_session":
        action_check_session($mysql);
        break;
    case "update_profile":
        action_update_profile($mysql);
        break;
    case "change_password":
        action_change_password($mysql);
        break;
    default:
        send_error("Unknown action.", 404);
}

function action_login($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }

    $input = get_json_input();
    $missing = require_fields($input, ["email", "password"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $email = normalize_email($input["email"]);

    $stmt = $mysql->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !password_verify($input["password"], $user["password_hash"])) {
        send_error("Invalid email or password.", 401);
    }

    $token = login_user($user["id"], $user["role"]);

    send_json(["token" => $token, "user" => [
        "id" => $user["id"],
        "name" => $user["name"],
        "email" => $user["email"],
        "role" => $user["role"],
    ]]);
}

function action_signup($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }

    $input = get_json_input();
    $missing = require_fields($input, ["name", "email", "password", "confirmPassword"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $name = trim($input["name"]);
    $email = normalize_email($input["email"]);
    $password = $input["password"];
    $children = is_array($input["children"] ?? null) ? $input["children"] : [];

    if ($name === "") {
        send_error("Name is required.", 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_error("Please enter a valid email address.", 400);
    }
    if (strlen($password) < 8 || $password !== $input["confirmPassword"]) {
        send_error("Password must be at least 8 characters and match confirmation.", 400);
    }
    foreach ($children as $child) {
        if (!isset($child["name"]) || trim($child["name"]) === "" || !isset($child["grade_level_id"]) || !is_numeric($child["grade_level_id"])) {
            send_error("Each child needs a name and a grade level.", 400);
        }
    }

    $stmt = $mysql->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row() !== null) {
        send_error("An account with that email already exists.", 409);
    }

    $schoolId = 1;

    $mysql->begin_transaction();
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $mysql->prepare(
            "INSERT INTO users (school_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, 'parent')"
        );
        $stmt->bind_param("isss", $schoolId, $name, $email, $hash);
        $stmt->execute();
        $userId = (int)$mysql->insert_id;

        foreach ($children as $child) {
            insert_child($mysql, $userId, $child["name"], (int)$child["grade_level_id"], $schoolId);
        }

        $mysql->commit();
    } catch (InvalidArgumentException $e) {
        $mysql->rollback();
        send_error($e->getMessage(), 400);
    } catch (mysqli_sql_exception $e) {
        $mysql->rollback();
        if ($e->getCode() === 1062) {
            send_error("An account with that email already exists.", 409);
        }
        error_log($e->getMessage());
        send_error("Something went wrong.", 500);
    }

    $token = login_user($userId, "parent");

    send_json(["token" => $token, "user" => [
        "id" => $userId,
        "name" => $name,
        "email" => $email,
        "role" => "parent",
    ]], 201);
}

function action_logout() {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }

    logout_user();
    send_json(["logged_out" => true]);
}

function action_check_session($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }

    $userId = current_user_id();
    if ($userId === null) {
        send_json(["logged_in" => false]);
    }

    $stmt = $mysql->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        logout_user();
        send_json(["logged_in" => false]);
    }

    send_json(["logged_in" => true, "user" => $user]);
}

function action_update_profile($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $input = get_json_input();
    $missing = require_fields($input, ["name", "email"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $name = trim($input["name"]);
    $email = normalize_email($input["email"]);

    if ($name === "") {
        send_error("Name is required.", 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_error("Please enter a valid email address.", 400);
    }

    $selfId = current_user_id();

    $stmt = $mysql->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->bind_param("si", $email, $selfId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row() !== null) {
        send_error("That email is already in use.", 409);
    }

    $stmt = $mysql->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $email, $selfId);
    $stmt->execute();

    send_json(["user" => [
        "id" => $selfId,
        "name" => $name,
        "email" => $email,
        "role" => current_user_role(),
    ]]);
}

function action_change_password($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_login();

    $input = get_json_input();
    $missing = require_fields($input, ["currentPassword", "newPassword", "confirmNewPassword"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    if ($input["newPassword"] !== $input["confirmNewPassword"] || strlen($input["newPassword"]) < 8) {
        send_error("New password must be at least 8 characters and match confirmation.", 400);
    }

    $selfId = current_user_id();

    $stmt = $mysql->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->bind_param("i", $selfId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $hash = $row === null ? null : $row[0];

    if (!password_verify($input["currentPassword"], $hash)) {
        send_error("Current password is incorrect.", 400);
    }

    $newHash = password_hash($input["newPassword"], PASSWORD_DEFAULT);
    $stmt = $mysql->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->bind_param("si", $newHash, $selfId);
    $stmt->execute();

    send_json(["password_updated" => true]);
}
