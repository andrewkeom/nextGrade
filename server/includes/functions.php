<?php
require_once __DIR__ . "/session_helpers.php";

function send_json($data, $statusCode = 200) {
    http_response_code($statusCode);
    header("Content-Type: application/json");
    echo json_encode(array_merge(["success" => true], $data));
    exit;
}

function send_error($message, $statusCode = 400) {
    http_response_code($statusCode);
    header("Content-Type: application/json");
    echo json_encode(["success" => false, "error" => $message]);
    exit;
}

function get_json_input() {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_fields($input, $fieldNames) {
    $missing = [];
    foreach ($fieldNames as $field) {
        if (!isset($input[$field]) || $input[$field] === "") {
            $missing[] = $field;
        }
    }
    return $missing;
}

function normalize_email($email) {
    return strtolower(trim($email));
}

function current_user_school_id($mysql) {
    $id = current_user_id();

    $stmt = $mysql->prepare("SELECT school_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    return $row === null ? null : (int)$row[0];
}

function get_grade_level_school_id($mysql, $id) {
    $stmt = $mysql->prepare("SELECT school_id FROM grade_levels WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    return $row === null ? null : (int)$row[0];
}

function get_subject_school_id($mysql, $id) {
    $stmt = $mysql->prepare(
        "SELECT gl.school_id
         FROM subjects s
         JOIN grade_levels gl ON s.grade_level_id = gl.id
         WHERE s.id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    return $row === null ? null : (int)$row[0];
}

function get_book_school_id($mysql, $id) {
    $stmt = $mysql->prepare(
        "SELECT gl.school_id
         FROM books b
         JOIN subjects s ON b.subject_id = s.id
         JOIN grade_levels gl ON s.grade_level_id = gl.id
         WHERE b.id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();

    return $row === null ? null : (int)$row[0];
}

function count_children_in_grade_level($mysql, $gradeLevelId) {
    $stmt = $mysql->prepare("SELECT COUNT(*) FROM children WHERE grade_level_id = ?");
    $stmt->bind_param("i", $gradeLevelId);
    $stmt->execute();

    return (int)$stmt->get_result()->fetch_row()[0];
}

function count_books_in_subject($mysql, $subjectId) {
    $stmt = $mysql->prepare("SELECT COUNT(*) FROM books WHERE subject_id = ?");
    $stmt->bind_param("i", $subjectId);
    $stmt->execute();

    return (int)$stmt->get_result()->fetch_row()[0];
}

function count_listings_for_book($mysql, $bookId) {
    $stmt = $mysql->prepare("SELECT COUNT(*) FROM listings WHERE book_id = ?");
    $stmt->bind_param("i", $bookId);
    $stmt->execute();

    return (int)$stmt->get_result()->fetch_row()[0];
}

function validate_image_upload($file) {
    if ($file["error"] !== UPLOAD_ERR_OK) {
        send_error("Image upload failed.", 400);
    }

    $allowedExtensions = ["jpg", "jpeg", "png", "webp", "gif"];
    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $imageInfo = @getimagesize($file["tmp_name"]);

    if (!in_array($ext, $allowedExtensions, true) || $imageInfo === false) {
        send_error("Please upload a valid image file (jpg, png, webp, or gif).", 400);
    }

    return $ext;
}

function validate_and_store_image($file, $destDirAbsPath, $urlPrefix) {
    $ext = validate_image_upload($file);

    $filename = bin2hex(random_bytes(8)) . "." . $ext;
    $destPath = rtrim($destDirAbsPath, "/\\") . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file["tmp_name"], $destPath)) {
        send_error("Could not save the uploaded image.", 500);
    }

    return $urlPrefix . $filename;
}

function save_uploaded_image($fieldName, $destDirAbsPath) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]["error"] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return validate_and_store_image($_FILES[$fieldName], $destDirAbsPath, "/uploads/books/");
}

function normalize_multi_file_upload($fieldName) {
    if (!isset($_FILES[$fieldName])) {
        return [];
    }

    $file = $_FILES[$fieldName];
    if (!is_array($file["name"])) {
        return [$file];
    }

    $files = [];
    for ($i = 0; $i < count($file["name"]); $i++) {
        if ($file["error"][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            "name" => $file["name"][$i],
            "type" => $file["type"][$i],
            "tmp_name" => $file["tmp_name"][$i],
            "error" => $file["error"][$i],
            "size" => $file["size"][$i],
        ];
    }
    return $files;
}

function save_uploaded_listing_images($fieldName, $destDirAbsPath, $maxFiles = 6) {
    $files = normalize_multi_file_upload($fieldName);
    if (count($files) > $maxFiles) {
        send_error("You can upload at most {$maxFiles} images per listing.", 400);
    }

    $paths = [];
    foreach ($files as $file) {
        $paths[] = validate_and_store_image($file, $destDirAbsPath, "/uploads/listings/");
    }
    return $paths;
}

function calculate_suggested_price($referencePrice, $condition, $edition) {
    $conditionMultipliers = [
        "New" => 0.90,
        "Like New" => 0.75,
        "Good" => 0.60,
        "Fair" => 0.40,
        "Poor" => 0.20,
    ];
    $conditionMultiplier = $conditionMultipliers[$condition];

    $ageMultiplier = 1.0;
    if (preg_match('/^\d{4}$/', trim((string)$edition))) {
        $yearsOld = max(0, (int)date("Y") - (int)$edition);
        $ageMultiplier = max(0.80, 1 - 0.02 * $yearsOld);
    }

    return max(1.00, round($referencePrice * $conditionMultiplier * $ageMultiplier, 2));
}

function default_justification($condition, $edition) {
    $text = "Priced based on {$condition} condition";
    if ($edition) {
        $text .= " and edition {$edition}";
    }
    return $text . ".";
}

function get_ai_justification($referencePrice, $condition, $edition, $suggestedPrice) {
    if (!defined("AI_API_KEY") || AI_API_KEY === "" || str_contains(AI_API_KEY, "your-real-key-here")) {
        return default_justification($condition, $edition);
    }

    $prompt = "A used textbook originally priced at \${$referencePrice} is being listed in "
        . "'{$condition}' condition" . ($edition ? ", edition {$edition}" : "")
        . ". The suggested resale price is \${$suggestedPrice}. Write one short, friendly "
        . "sentence (max 25 words) explaining this price to a parent seller.";

    $model = defined("AI_MODEL") ? AI_MODEL : "gemini-2.0-flash";
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "x-goog-api-key: " . AI_API_KEY],
        CURLOPT_POSTFIELDS => json_encode(["contents" => [["parts" => [["text" => $prompt]]]]]),
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        error_log("AI justification call failed: " . ($curlError ?: "HTTP {$httpCode}"));
        return default_justification($condition, $edition);
    }

    $data = json_decode($response, true);
    $text = $data["candidates"][0]["content"]["parts"][0]["text"] ?? null;

    return $text !== null ? trim($text) : default_justification($condition, $edition);
}

function get_ai_price_suggestion($mysql, $bookId, $condition) {
    $stmt = $mysql->prepare("SELECT reference_price, edition FROM books WHERE id = ?");
    $stmt->bind_param("i", $bookId);
    $stmt->execute();
    $book = $stmt->get_result()->fetch_assoc();
    if ($book === null) {
        send_error("Book not found.", 404);
    }

    $suggestedPrice = calculate_suggested_price((float)$book["reference_price"], $condition, $book["edition"]);
    $justification = get_ai_justification($book["reference_price"], $condition, $book["edition"], $suggestedPrice);

    return ["suggested_price" => $suggestedPrice, "justification" => $justification];
}

function read_curriculum_image_base64($fieldName = "curriculumImage") {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]["error"] === UPLOAD_ERR_NO_FILE) {
        send_error("Please upload a photo of the curriculum sheet.", 400);
    }
    $file = $_FILES[$fieldName];

    if ($file["size"] > 8 * 1024 * 1024) {
        send_error("Image is too large (max 8MB). Please use a smaller photo.", 400);
    }

    $ext = validate_image_upload($file);
    $mimeTypes = [
        "jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png",
        "webp" => "image/webp", "gif" => "image/gif",
    ];

    $bytes = file_get_contents($file["tmp_name"]);
    if ($bytes === false) {
        send_error("Could not read the uploaded image.", 500);
    }

    return ["mime_type" => $mimeTypes[$ext], "data" => base64_encode($bytes)];
}

function extract_curriculum_from_image($mimeType, $base64Data) {
    if (!defined("AI_API_KEY") || AI_API_KEY === "" || str_contains(AI_API_KEY, "your-real-key-here")) {
        send_error("AI extraction is not configured. Contact the site administrator.", 503);
    }

    $prompt = "You are reading a photographed page from a printed school curriculum "
        . "list for a single grade level. It is a table (or list) with columns similar "
        . "to: Subject/Matiere, Title/Collection, Edition, sometimes a price written "
        . "inside the subject or title cell, and sometimes an ISBN barcode number "
        . "printed near a row. Some rows may be annotated with text such as 'From "
        . "School' or an equivalent (book is free, no price) or 'Still Printing' or an "
        . "equivalent (not currently available). Text may be in French, English, "
        . "Arabic, Armenian, or a mix.\n\n"
        . "Extract every row as an object with:\n"
        . "- subject: the subject name, required, keep original language/spelling\n"
        . "- title: the book title, required\n"
        . "- author: author/publisher/collection if shown, else empty string\n"
        . "- edition: edition or year if shown, else empty string\n"
        . "- isbn: the ISBN if printed for that row, else empty string\n"
        . "- reference_price: a plain number, no currency symbol. If free / provided by "
        . "the school / no listed price / still printing / unclear, use 0. Never omit "
        . "this field or leave it non-numeric.\n"
        . "- note: any short annotation not captured above (e.g. 'From School', 'Still "
        . "Printing'), else empty string\n\n"
        . "Ignore headers, page titles, and page numbers. Return only real subject+book rows.";

    $schema = [
        "type" => "OBJECT",
        "properties" => [
            "rows" => [
                "type" => "ARRAY",
                "items" => [
                    "type" => "OBJECT",
                    "properties" => [
                        "subject" => ["type" => "STRING"],
                        "title" => ["type" => "STRING"],
                        "author" => ["type" => "STRING"],
                        "edition" => ["type" => "STRING"],
                        "isbn" => ["type" => "STRING"],
                        "reference_price" => ["type" => "NUMBER"],
                        "note" => ["type" => "STRING"],
                    ],
                    "required" => ["subject", "title", "reference_price"],
                ],
            ],
        ],
        "required" => ["rows"],
    ];

    $model = defined("AI_MODEL") ? AI_MODEL : "gemini-2.0-flash";
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "x-goog-api-key: " . AI_API_KEY],
        CURLOPT_POSTFIELDS => json_encode([
            "contents" => [["parts" => [
                ["text" => $prompt],
                ["inline_data" => ["mime_type" => $mimeType, "data" => $base64Data]],
            ]]],
            "generationConfig" => [
                "responseMimeType" => "application/json",
                "responseSchema" => $schema,
            ],
        ]),
        CURLOPT_TIMEOUT => 45,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError || $httpCode !== 200) {
        error_log("Curriculum extraction call failed: " . ($curlError ?: "HTTP {$httpCode}"));
        send_error("Could not read the curriculum sheet. Please try again or enter subjects/books manually.", 502);
    }

    $data = json_decode($response, true);
    $text = $data["candidates"][0]["content"]["parts"][0]["text"] ?? null;
    $parsed = $text !== null ? json_decode($text, true) : null;

    if (!is_array($parsed) || !isset($parsed["rows"]) || !is_array($parsed["rows"])) {
        error_log("Curriculum extraction returned unparsable JSON: " . substr((string)$text, 0, 500));
        send_error("Could not understand the curriculum sheet. Please try a clearer photo.", 502);
    }

    $rows = [];
    foreach ($parsed["rows"] as $row) {
        $subject = trim((string)($row["subject"] ?? ""));
        $title = trim((string)($row["title"] ?? ""));
        if ($subject === "" || $title === "") {
            continue;
        }
        $price = $row["reference_price"] ?? 0;
        $rows[] = [
            "subject" => $subject,
            "title" => $title,
            "author" => trim((string)($row["author"] ?? "")),
            "edition" => trim((string)($row["edition"] ?? "")),
            "isbn" => trim((string)($row["isbn"] ?? "")),
            "reference_price" => is_numeric($price) && (float)$price >= 0 ? (float)$price : 0.0,
            "note" => trim((string)($row["note"] ?? "")),
        ];
    }

    if (count($rows) === 0) {
        send_error("No subjects/books could be read from that photo. Please try a clearer photo.", 422);
    }

    return $rows;
}

function insert_child($mysql, $parentId, $name, $gradeLevelId, $schoolId) {
    $name = trim($name);
    if ($name === "") {
        throw new InvalidArgumentException("Child name is required.");
    }

    $stmt = $mysql->prepare("SELECT id FROM grade_levels WHERE id = ? AND school_id = ?");
    $stmt->bind_param("ii", $gradeLevelId, $schoolId);
    $stmt->execute();
    if ($stmt->get_result()->fetch_row() === null) {
        throw new InvalidArgumentException("Grade level not found.");
    }

    $stmt = $mysql->prepare("INSERT INTO children (parent_id, name, grade_level_id) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $parentId, $name, $gradeLevelId);
    $stmt->execute();

    return (int)$mysql->insert_id;
}
