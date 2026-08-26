<?php
define("TOKEN_EXPIRY_DAYS", 30);

function get_bearer_token() {
    $header = $_SERVER["HTTP_AUTHORIZATION"]
        ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"]
        ?? null;

    if ($header === null && function_exists("apache_request_headers")) {
        $headers = apache_request_headers();
        $header = $headers["Authorization"] ?? $headers["authorization"] ?? null;
    }

    if ($header !== null && preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }
    return null;
}

function login_user($userId, $role) {
    global $mysql;

    $token = bin2hex(random_bytes(32));
    $expiresAt = date("Y-m-d H:i:s", strtotime("+" . TOKEN_EXPIRY_DAYS . " days"));

    $stmt = $mysql->prepare("INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $userId, $token, $expiresAt);
    $stmt->execute();

    return $token;
}

function logout_user() {
    global $mysql;

    $token = get_bearer_token();
    if ($token !== null) {
        $stmt = $mysql->prepare("DELETE FROM auth_tokens WHERE token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
    }
}

function current_auth_row() {
    static $row = false;

    if ($row === false) {
        global $mysql;
        $token = get_bearer_token();

        if ($token === null) {
            $row = null;
        } else {
            $stmt = $mysql->prepare(
                "SELECT auth_tokens.user_id, users.role
                 FROM auth_tokens
                 JOIN users ON users.id = auth_tokens.user_id
                 WHERE auth_tokens.token = ? AND auth_tokens.expires_at > NOW()"
            );
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            $row = $found ?: null;
        }
    }

    return $row;
}

function require_login() {
    if (current_user_id() === null) {
        http_response_code(401);
        header("Content-Type: application/json");
        echo json_encode([
            "success" => false,
            "error" => "You must be logged in.",
        ]);
        exit;
    }
}

function require_role($role) {
    require_login();

    if (current_user_role() !== $role) {
        http_response_code(403);
        header("Content-Type: application/json");
        echo json_encode([
            "success" => false,
            "error" => "You don't have permission to do that.",
        ]);
        exit;
    }
}

function current_user_id() {
    $row = current_auth_row();
    return $row ? (int)$row["user_id"] : null;
}

function current_user_role() {
    $row = current_auth_row();
    return $row ? $row["role"] : null;
}
