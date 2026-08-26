<?php
require_once __DIR__ . "/../includes/connection.php";
require_once __DIR__ . "/../includes/session_helpers.php";
require_once __DIR__ . "/../includes/functions.php";

$action = $_GET["action"] ?? null;
if ($action === null) send_error("Missing 'action' parameter.", 400);

switch ($action) {
    case "list_grades":
        action_list_grades($mysql);
        break;
    case "list_subjects":
        action_list_subjects($mysql);
        break;
    case "list_books":
        action_list_books($mysql);
        break;
    case "add_grade_level":
        action_add_grade_level($mysql);
        break;
    case "update_grade_level":
        action_update_grade_level($mysql);
        break;
    case "delete_grade_level":
        action_delete_grade_level($mysql);
        break;
    case "add_subject":
        action_add_subject($mysql);
        break;
    case "update_subject":
        action_update_subject($mysql);
        break;
    case "delete_subject":
        action_delete_subject($mysql);
        break;
    case "add_book":
        action_add_book($mysql);
        break;
    case "update_book":
        action_update_book($mysql);
        break;
    case "delete_book":
        action_delete_book($mysql);
        break;
    case "extract_curriculum":
        action_extract_curriculum($mysql);
        break;
    case "import_curriculum":
        action_import_curriculum($mysql);
        break;
    default:
        send_error("Unknown action.", 404);
}

function action_list_grades($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }

    $stmt = $mysql->prepare("SELECT id, name, academic_year FROM grade_levels WHERE school_id = 1 ORDER BY id");
    $stmt->execute();

    send_json(["grades" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

function action_list_subjects($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }

    $gradeLevelId = $_GET["grade_level_id"] ?? null;
    if ($gradeLevelId === null || !is_numeric($gradeLevelId)) {
        send_error("grade_level_id is required.", 400);
    }
    if (get_grade_level_school_id($mysql, $gradeLevelId) === null) {
        send_error("Grade level not found.", 404);
    }

    $stmt = $mysql->prepare("SELECT id, name FROM subjects WHERE grade_level_id = ? ORDER BY name");
    $stmt->bind_param("i", $gradeLevelId);
    $stmt->execute();

    send_json(["subjects" => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

function action_list_books($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        send_error("Method not allowed.", 405);
    }

    $subjectId = $_GET["subject_id"] ?? null;
    $gradeLevelId = $_GET["grade_level_id"] ?? null;

    if (($subjectId === null) === ($gradeLevelId === null)) {
        send_error("Provide either subject_id or grade_level_id.", 400);
    }

    if ($subjectId !== null) {
        if (!is_numeric($subjectId) || get_subject_school_id($mysql, $subjectId) === null) {
            send_error("Subject not found.", 404);
        }
        $stmt = $mysql->prepare(
            "SELECT id, title, author, edition, isbn, reference_price, cover_image
             FROM books WHERE subject_id = ? ORDER BY title"
        );
        $stmt->bind_param("i", $subjectId);
        $stmt->execute();
        $books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        if (!is_numeric($gradeLevelId) || get_grade_level_school_id($mysql, $gradeLevelId) === null) {
            send_error("Grade level not found.", 404);
        }
        $stmt = $mysql->prepare(
            "SELECT b.id, b.title, b.author, b.edition, b.isbn, b.reference_price, b.cover_image,
                    s.name AS subject_name, gl.id AS grade_level_id, gl.name AS grade_level_name
             FROM books b
             JOIN subjects s ON b.subject_id = s.id
             JOIN grade_levels gl ON s.grade_level_id = gl.id
             WHERE gl.id = ?
             ORDER BY s.name, b.title"
        );
        $stmt->bind_param("i", $gradeLevelId);
        $stmt->execute();
        $books = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    send_json(["books" => $books]);
}

function action_add_grade_level($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["gradeLevelName", "academicYear"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $schoolId = current_user_school_id($mysql);
    $name = trim($input["gradeLevelName"]);
    $academicYear = trim($input["academicYear"]);

    $stmt = $mysql->prepare("INSERT INTO grade_levels (school_id, name, academic_year) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $schoolId, $name, $academicYear);
    $stmt->execute();

    send_json(["grade_level" => [
        "id" => (int)$mysql->insert_id,
        "name" => $name,
        "academic_year" => $academicYear,
    ]], 201);
}

function action_update_grade_level($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["id", "name", "academic_year"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $schoolId = get_grade_level_school_id($mysql, $input["id"]);
    if ($schoolId === null) {
        send_error("Grade level not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this grade level.", 403);
    }

    $name = trim($input["name"]);
    $academicYear = trim($input["academic_year"]);
    $id = $input["id"];

    $stmt = $mysql->prepare("UPDATE grade_levels SET name = ?, academic_year = ? WHERE id = ?");
    $stmt->bind_param("ssi", $name, $academicYear, $id);
    $stmt->execute();

    send_json(["grade_level" => [
        "id" => (int)$id,
        "name" => $name,
        "academic_year" => $academicYear,
    ]]);
}

function action_delete_grade_level($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $schoolId = get_grade_level_school_id($mysql, $input["id"]);
    if ($schoolId === null) {
        send_error("Grade level not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this grade level.", 403);
    }

    $enrolledCount = count_children_in_grade_level($mysql, $input["id"]);
    if ($enrolledCount > 0) {
        send_error("Cannot delete: {$enrolledCount} students are currently enrolled in this grade level.", 409);
    }

    $id = $input["id"];
    $stmt = $mysql->prepare("DELETE FROM grade_levels WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    send_json(["deleted" => true]);
}

function action_add_subject($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["grade_level_id", "subjectName"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $schoolId = get_grade_level_school_id($mysql, $input["grade_level_id"]);
    if ($schoolId === null) {
        send_error("Grade level not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this grade level.", 403);
    }

    $gradeLevelId = $input["grade_level_id"];
    $name = trim($input["subjectName"]);

    $stmt = $mysql->prepare("INSERT INTO subjects (grade_level_id, name) VALUES (?, ?)");
    $stmt->bind_param("is", $gradeLevelId, $name);
    $stmt->execute();

    send_json(["subject" => [
        "id" => (int)$mysql->insert_id,
        "name" => $name,
        "grade_level_id" => (int)$gradeLevelId,
    ]], 201);
}

function action_update_subject($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["id", "name"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $schoolId = get_subject_school_id($mysql, $input["id"]);
    if ($schoolId === null) {
        send_error("Subject not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this subject.", 403);
    }

    $name = trim($input["name"]);
    $id = $input["id"];

    $stmt = $mysql->prepare("UPDATE subjects SET name = ? WHERE id = ?");
    $stmt->bind_param("si", $name, $id);
    $stmt->execute();

    send_json(["subject" => ["id" => (int)$id, "name" => $name]]);
}

function action_delete_subject($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $schoolId = get_subject_school_id($mysql, $input["id"]);
    if ($schoolId === null) {
        send_error("Subject not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this subject.", 403);
    }

    $bookCount = count_books_in_subject($mysql, $input["id"]);
    if ($bookCount > 0) {
        send_error("Cannot delete: {$bookCount} books exist under this subject.", 409);
    }

    $id = $input["id"];
    $stmt = $mysql->prepare("DELETE FROM subjects WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    send_json(["deleted" => true]);
}

function action_add_book($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $missing = require_fields($_POST, ["subject_id", "bookTitle", "bookReferencePrice"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }
    if (!is_numeric($_POST["bookReferencePrice"]) || (float)$_POST["bookReferencePrice"] < 0) {
        send_error("Reference price must be a non-negative number.", 400);
    }

    $schoolId = get_subject_school_id($mysql, $_POST["subject_id"]);
    if ($schoolId === null) {
        send_error("Subject not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this subject.", 403);
    }

    $coverPath = save_uploaded_image("bookCoverImage", __DIR__ . "/../../uploads/books");

    $subjectId = $_POST["subject_id"];
    $title = trim($_POST["bookTitle"]);
    $author = trim($_POST["bookAuthor"] ?? "");
    $edition = trim($_POST["bookEdition"] ?? "");
    $isbn = trim($_POST["bookIsbn"] ?? "");
    $referencePriceRaw = $_POST["bookReferencePrice"];
    $referencePrice = (float)$referencePriceRaw;

    $stmt = $mysql->prepare(
        "INSERT INTO books (subject_id, title, author, edition, isbn, reference_price, cover_image)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("issssds", $subjectId, $title, $author, $edition, $isbn, $referencePrice, $coverPath);
    $stmt->execute();

    send_json(["book" => [
        "id" => (int)$mysql->insert_id,
        "title" => $title,
        "author" => $author,
        "edition" => $edition,
        "isbn" => $isbn,
        "reference_price" => $referencePriceRaw,
        "cover_image" => $coverPath,
    ]], 201);
}

function action_update_book($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $missing = require_fields($_POST, ["id", "bookTitle", "bookReferencePrice"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }
    if (!is_numeric($_POST["bookReferencePrice"]) || (float)$_POST["bookReferencePrice"] < 0) {
        send_error("Reference price must be a non-negative number.", 400);
    }

    $schoolId = get_book_school_id($mysql, $_POST["id"]);
    if ($schoolId === null) {
        send_error("Book not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this book.", 403);
    }

    $id = $_POST["id"];
    $newCover = save_uploaded_image("bookCoverImage", __DIR__ . "/../../uploads/books");
    if ($newCover === null) {
        $stmt = $mysql->prepare("SELECT cover_image FROM books WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $newCover = $stmt->get_result()->fetch_row()[0];
    }

    $title = trim($_POST["bookTitle"]);
    $author = trim($_POST["bookAuthor"] ?? "");
    $edition = trim($_POST["bookEdition"] ?? "");
    $isbn = trim($_POST["bookIsbn"] ?? "");
    $referencePriceRaw = $_POST["bookReferencePrice"];
    $referencePrice = (float)$referencePriceRaw;

    $stmt = $mysql->prepare(
        "UPDATE books SET title = ?, author = ?, edition = ?, isbn = ?,
                reference_price = ?, cover_image = ?
         WHERE id = ?"
    );
    $stmt->bind_param("ssssdsi", $title, $author, $edition, $isbn, $referencePrice, $newCover, $id);
    $stmt->execute();

    send_json(["book" => [
        "id" => (int)$id,
        "title" => $title,
        "author" => $author,
        "edition" => $edition,
        "isbn" => $isbn,
        "reference_price" => $referencePriceRaw,
        "cover_image" => $newCover,
    ]]);
}

function action_delete_book($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }

    $schoolId = get_book_school_id($mysql, $input["id"]);
    if ($schoolId === null) {
        send_error("Book not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this book.", 403);
    }

    $listingCount = count_listings_for_book($mysql, $input["id"]);
    if ($listingCount > 0) {
        send_error("Cannot delete: {$listingCount} active listings reference this book.", 409);
    }

    $id = $input["id"];
    $stmt = $mysql->prepare("DELETE FROM books WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    send_json(["deleted" => true]);
}

function action_extract_curriculum($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $missing = require_fields($_POST, ["grade_level_id"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }
    if (!is_numeric($_POST["grade_level_id"])) {
        send_error("Grade level not found.", 404);
    }

    $schoolId = get_grade_level_school_id($mysql, $_POST["grade_level_id"]);
    if ($schoolId === null) {
        send_error("Grade level not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this grade level.", 403);
    }

    $image = read_curriculum_image_base64("curriculumImage");
    $rows = extract_curriculum_from_image($image["mime_type"], $image["data"]);

    send_json(["rows" => $rows]);
}

function action_import_curriculum($mysql) {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        send_error("Method not allowed.", 405);
    }
    require_role("admin");

    $input = get_json_input();
    $missing = require_fields($input, ["grade_level_id", "rows"]);
    if ($missing) {
        send_error("Missing: " . implode(", ", $missing), 400);
    }
    if (!is_numeric($input["grade_level_id"])) {
        send_error("Grade level not found.", 404);
    }
    if (!is_array($input["rows"]) || count($input["rows"]) === 0) {
        send_error("No rows to import.", 400);
    }

    $gradeLevelId = $input["grade_level_id"];
    $schoolId = get_grade_level_school_id($mysql, $gradeLevelId);
    if ($schoolId === null) {
        send_error("Grade level not found.", 404);
    }
    if ($schoolId !== current_user_school_id($mysql)) {
        send_error("You don't have permission to modify this grade level.", 403);
    }

    $cleanRows = [];
    foreach ($input["rows"] as $i => $row) {
        $subject = trim((string)($row["subject"] ?? ""));
        $title = trim((string)($row["title"] ?? ""));
        if ($subject === "" || $title === "") {
            send_error("Row " . ($i + 1) . ": subject and title are required.", 400);
        }

        $priceRaw = $row["reference_price"] ?? "";
        if ($priceRaw === "" || $priceRaw === null) {
            $price = 0.0;
        } elseif (!is_numeric($priceRaw) || (float)$priceRaw < 0) {
            send_error("Row " . ($i + 1) . ": reference price must be a non-negative number.", 400);
        } else {
            $price = (float)$priceRaw;
        }

        $cleanRows[] = [
            "subject" => $subject,
            "title" => $title,
            "author" => trim((string)($row["author"] ?? "")) ?: null,
            "edition" => trim((string)($row["edition"] ?? "")) ?: null,
            "isbn" => trim((string)($row["isbn"] ?? "")) ?: null,
            "reference_price" => $price,
        ];
    }

    $mysql->begin_transaction();
    try {
        $subjectIdCache = [];
        $findStmt = $mysql->prepare("SELECT id FROM subjects WHERE grade_level_id = ? AND name = ?");
        $insertSubjectStmt = $mysql->prepare("INSERT INTO subjects (grade_level_id, name) VALUES (?, ?)");
        $insertBookStmt = $mysql->prepare(
            "INSERT INTO books (subject_id, title, author, edition, isbn, reference_price)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $subjectsCreated = 0;
        $booksCreated = 0;

        foreach ($cleanRows as $row) {
            if (!isset($subjectIdCache[$row["subject"]])) {
                $findStmt->bind_param("is", $gradeLevelId, $row["subject"]);
                $findStmt->execute();
                $existing = $findStmt->get_result()->fetch_row();

                if ($existing !== null) {
                    $subjectIdCache[$row["subject"]] = (int)$existing[0];
                } else {
                    $insertSubjectStmt->bind_param("is", $gradeLevelId, $row["subject"]);
                    $insertSubjectStmt->execute();
                    $subjectIdCache[$row["subject"]] = (int)$mysql->insert_id;
                    $subjectsCreated++;
                }
            }
            $subjectId = $subjectIdCache[$row["subject"]];

            $insertBookStmt->bind_param(
                "issssd",
                $subjectId, $row["title"], $row["author"], $row["edition"], $row["isbn"], $row["reference_price"]
            );
            $insertBookStmt->execute();
            $booksCreated++;
        }

        $mysql->commit();
    } catch (mysqli_sql_exception $e) {
        $mysql->rollback();
        error_log("Curriculum import failed: " . $e->getMessage());
        send_error("Import failed, no changes were saved.", 500);
    }

    send_json([
        "grade_level_id" => (int)$gradeLevelId,
        "subjects_created" => $subjectsCreated,
        "books_created" => $booksCreated,
    ], 201);
}
