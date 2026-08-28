<?php
/**
 * Stop-Service MySQL80
 * netstat -ano | findstr :3306
 * Open the XAMPP Control Panel and click Start next to MySQL.
 * cd C:\xampp\htdocs\nextGrade\server
 * C:\xampp\php\php.exe seed.php
 *
 * Test-data seeder — adds a realistic-but-fake batch of data on top of
 * database.sql's base seed, so every page/flow has something to click
 * through end to end instead of empty states everywhere.
 *
 * What it adds:
 *   - Subjects + books for 1st, 4th, 5th, 6th, 7th, and 8th Grade (2nd/3rd
 *     already had a little from database.sql; 9th-12th are deliberately
 *     left with zero subjects, and a few subjects here are deliberately
 *     left with zero books, so the "no curriculum yet" / "no books added
 *     for this subject yet" empty states in curriculum.js still show up
 *     somewhere instead of being untestable).
 *   - 8 new parent accounts (all password "Password123!"), each with 1-2
 *     children, spread across both curriculum-rich and curriculum-empty
 *     grades.
 *   - 17 listings across those parents covering every condition, every
 *     listing_type, a couple of sold listings and one removed one, and a
 *     deliberate mix of the 3 image states listing.js/marketplace.js
 *     handle: own uploaded photo, falling back to the book's cover image,
 *     and neither (gallery hides itself).
 *   - 7 message conversations between them (including two different
 *     buyers messaging the same seller about the same listing, and one
 *     buyer following up on the same listing they'd already asked about)
 *     with a mix of read/unread messages.
 *   - 5 price disputes: 2 still pending, and 3 resolved — one overridden
 *     with no admin note (so dashboard.js's auto-generated "price was
 *     updated to $X" sentence shows), one overridden WITH a note, and one
 *     flagged back to the seller with the price left unchanged.
 *
 * This never touches parent@example.com / admin@example.com or any of
 * their data — only new accounts, all under the @seed.test domain
 * (".test" is IANA-reserved for testing, so it can never collide with a
 * real address) are created. That also makes this safe to re-run any
 * time you want a fresh batch: it deletes every @seed.test account first
 * (which cascades away their children/listings/messages/price reports
 * automatically via the schema's ON DELETE CASCADE foreign keys) and
 * rebuilds everything from scratch. Curriculum rows work differently —
 * they're found-or-created by name, the same way curriculum.php's real
 * import_curriculum action works, so re-running never creates duplicate
 * subjects/books.
 *
 * This is a command-line tool, not a page or API endpoint — nothing
 * else in the app calls it. Run it from a terminal:
 *   cd server
 *   php seed.php
 */

if (php_sapi_name() !== "cli") {
    http_response_code(403);
    exit("This script can only be run from the command line (php seed.php), not through a browser.\n");
}

require_once __DIR__ . "/includes/config.php";
require_once __DIR__ . "/includes/functions.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$mysql->set_charset(DB_CHARSET);

define("SEED_SCHOOL_ID", 1);
define("SEED_PASSWORD", "Password123!");

$mathPngPath = __DIR__ . "/../client/assets/math.png";
$uploadsBooksDir = __DIR__ . "/../uploads/books";
$uploadsListingsDir = __DIR__ . "/../uploads/listings";

// ============================================================
// Small single-purpose helpers — each one does one insert/lookup,
// mirroring the style of server/includes/functions.php.
// ============================================================

function seed_get_grade_level_id($mysql, $schoolId, $name) {
    $stmt = $mysql->prepare("SELECT id FROM grade_levels WHERE school_id = ? AND name = ?");
    $stmt->bind_param("is", $schoolId, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    if ($row === null) {
        throw new RuntimeException("Grade level '{$name}' not found — did database.sql get imported?");
    }
    return (int)$row[0];
}

function seed_find_or_create_subject($mysql, $gradeLevelId, $name) {
    $stmt = $mysql->prepare("SELECT id FROM subjects WHERE grade_level_id = ? AND name = ?");
    $stmt->bind_param("is", $gradeLevelId, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    if ($row !== null) {
        return (int)$row[0];
    }

    $stmt = $mysql->prepare("INSERT INTO subjects (grade_level_id, name) VALUES (?, ?)");
    $stmt->bind_param("is", $gradeLevelId, $name);
    $stmt->execute();
    return (int)$mysql->insert_id;
}

// Copies client/assets/math.png into an uploads/ folder under a fresh
// random filename, the same naming scheme functions.php's real upload
// helpers use (bin2hex(random_bytes(8)) + extension). Returns the
// root-relative path the DB column expects (e.g. "/uploads/books/abcd...png").
function seed_copy_image($sourceAbsPath, $destDirAbsPath, $urlPrefix) {
    $ext = strtolower(pathinfo($sourceAbsPath, PATHINFO_EXTENSION));
    $filename = bin2hex(random_bytes(8)) . "." . $ext;
    $destPath = rtrim($destDirAbsPath, "/\\") . DIRECTORY_SEPARATOR . $filename;
    if (!copy($sourceAbsPath, $destPath)) {
        throw new RuntimeException("Could not copy seed image to {$destPath}");
    }
    return $urlPrefix . $filename;
}

// Only copies+inserts a new cover image when the book doesn't already
// exist — otherwise re-running the seeder would leave an orphaned image
// file behind every time (the file gets copied, but a book that already
// existed keeps its original cover_image, so the new copy is never
// referenced by anything).
function seed_find_or_create_book($mysql, $subjectId, $title, $author, $edition, $isbn, $price, $wantsCover, $mathPngPath, $uploadsBooksDir) {
    $stmt = $mysql->prepare("SELECT id FROM books WHERE subject_id = ? AND title = ?");
    $stmt->bind_param("is", $subjectId, $title);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    if ($row !== null) {
        return (int)$row[0];
    }

    $coverImage = $wantsCover ? seed_copy_image($mathPngPath, $uploadsBooksDir, "/uploads/books/") : null;

    $stmt = $mysql->prepare(
        "INSERT INTO books (subject_id, title, author, edition, isbn, reference_price, cover_image)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("issssds", $subjectId, $title, $author, $edition, $isbn, $price, $coverImage);
    $stmt->execute();
    return (int)$mysql->insert_id;
}

// The ON DELETE CASCADE below removes the listing_images *rows*, but not
// the actual image files those rows pointed to on disk — those would
// otherwise pile up as orphaned files every time the seeder is re-run
// (book covers don't have this problem, since find-or-create never
// re-copies a cover for a book that already exists). Call this BEFORE
// seed_reset_accounts, while the rows can still be looked up.
function seed_delete_account_listing_images($mysql, array $emails, $projectRootAbsPath) {
    $placeholders = implode(",", array_fill(0, count($emails), "?"));
    $stmt = $mysql->prepare("SELECT id FROM users WHERE email IN ({$placeholders})");
    $stmt->bind_param(str_repeat("s", count($emails)), ...$emails);
    $stmt->execute();
    $userIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), "id");
    if (count($userIds) === 0) {
        return; // first-ever run — nothing to clean up yet
    }

    $idPlaceholders = implode(",", array_fill(0, count($userIds), "?"));
    $stmt = $mysql->prepare(
        "SELECT li.image_path FROM listing_images li
         JOIN listings l ON li.listing_id = l.id
         WHERE l.seller_id IN ({$idPlaceholders})"
    );
    $stmt->bind_param(str_repeat("i", count($userIds)), ...$userIds);
    $stmt->execute();

    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $absPath = $projectRootAbsPath . $row["image_path"];
        if (is_file($absPath)) {
            unlink($absPath);
        }
    }
}

// Deleting a user cascades (via the schema's ON DELETE CASCADE foreign
// keys) to their children, listings — which further cascades to that
// listing's images/messages/price reports — other messages, and price
// reports they filed. So this one query undoes an entire seed account's
// footprint before it gets rebuilt fresh.
function seed_reset_accounts($mysql, array $emails) {
    $placeholders = implode(",", array_fill(0, count($emails), "?"));
    $stmt = $mysql->prepare("DELETE FROM users WHERE email IN ({$placeholders})");
    $stmt->bind_param(str_repeat("s", count($emails)), ...$emails);
    $stmt->execute();
}

function seed_create_user($mysql, $schoolId, $name, $email, $passwordHash) {
    $stmt = $mysql->prepare(
        "INSERT INTO users (school_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, 'parent')"
    );
    $stmt->bind_param("isss", $schoolId, $name, $email, $passwordHash);
    $stmt->execute();
    return (int)$mysql->insert_id;
}

function seed_create_child($mysql, $parentId, $name, $gradeLevelId) {
    $stmt = $mysql->prepare("INSERT INTO children (parent_id, name, grade_level_id) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $parentId, $name, $gradeLevelId);
    $stmt->execute();
}

function seed_create_listing($mysql, $bookId, $sellerId, $condition, $listingType, $status, $askingPrice, $aiSuggestedPrice, $aiJustification, $description, $createdAt) {
    $stmt = $mysql->prepare(
        "INSERT INTO listings
            (book_id, seller_id, `condition`, listing_type, asking_price, ai_suggested_price, ai_justification, status, description, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "iissddssss",
        $bookId, $sellerId, $condition, $listingType, $askingPrice, $aiSuggestedPrice, $aiJustification, $status, $description, $createdAt
    );
    $stmt->execute();
    return (int)$mysql->insert_id;
}

function seed_attach_listing_image($mysql, $listingId, $imagePath) {
    $stmt = $mysql->prepare("INSERT INTO listing_images (listing_id, image_path) VALUES (?, ?)");
    $stmt->bind_param("is", $listingId, $imagePath);
    $stmt->execute();
}

function seed_send_message($mysql, $listingId, $senderId, $receiverId, $content, $isRead, $createdAt) {
    $stmt = $mysql->prepare(
        "INSERT INTO messages (listing_id, sender_id, receiver_id, content, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iiisis", $listingId, $senderId, $receiverId, $content, $isRead, $createdAt);
    $stmt->execute();
}

function seed_create_price_report($mysql, $listingId, $reportedBy, $reason, $status, $adminResponse, $createdAt, $resolvedAt) {
    $stmt = $mysql->prepare(
        "INSERT INTO price_reports (listing_id, reported_by, reason, status, admin_response, created_at, resolved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iisssss", $listingId, $reportedBy, $reason, $status, $adminResponse, $createdAt, $resolvedAt);
    $stmt->execute();
}

function seed_set_listing_price($mysql, $listingId, $newPrice) {
    $stmt = $mysql->prepare("UPDATE listings SET asking_price = ? WHERE id = ?");
    $stmt->bind_param("di", $newPrice, $listingId);
    $stmt->execute();
}

function seed_days_ago($days) {
    return date("Y-m-d H:i:s", strtotime("-{$days} days"));
}

function seed_hours_ago($hours) {
    return date("Y-m-d H:i:s", strtotime("-{$hours} hours"));
}

// ============================================================
// DATA — everything below is plain arrays. The loops that turn this
// into database rows are further down, under "RUN".
// ============================================================

// Grade name => Subject name => list of books. The 2nd/3rd Grade entries
// match database.sql's existing rows exactly on purpose (find-or-create
// just locates them by title instead of inserting duplicates) — that
// also gives us their price/edition below for building listings.
$curriculumData = [
    "1st Grade" => [
        "Mathematics" => [
            ["title" => "Counting Adventures Grade 1", "author" => "Lena Osei", "edition" => "2022", "isbn" => "978-0-000-01001-1", "price" => 18.99, "cover" => true],
            ["title" => "Shapes and Numbers Grade 1", "author" => "Tomas Rivera", "edition" => "2019", "isbn" => "978-0-000-01002-2", "price" => 17.50, "cover" => false],
        ],
        "Reading" => [
            ["title" => "First Steps in Reading Grade 1", "author" => "Grace Whitfield", "edition" => "2021", "isbn" => "978-0-000-01003-3", "price" => 16.99, "cover" => true],
            ["title" => "Storytime Grade 1", "author" => "Grace Whitfield", "edition" => "2018", "isbn" => "978-0-000-01004-4", "price" => 15.50, "cover" => false],
        ],
        "Science" => [
            ["title" => "My First Science Book Grade 1", "author" => "Ravi Chandran", "edition" => "2023", "isbn" => "978-0-000-01005-5", "price" => 19.99, "cover" => false],
        ],
    ],
    "2nd Grade" => [
        "Mathematics" => [
            ["title" => "Math Adventures Grade 2", "author" => "Jane Carter", "edition" => "2023", "isbn" => "978-0-000-00001-1", "price" => 24.99, "cover" => false],
        ],
        "Reading" => [
            ["title" => "Reading Journeys Grade 2", "author" => "Mark Ellis", "edition" => "2022", "isbn" => "978-0-000-00002-2", "price" => 19.99, "cover" => false],
        ],
    ],
    "3rd Grade" => [
        "Mathematics" => [
            ["title" => "Math Adventures Grade 3", "author" => "Jane Carter", "edition" => "2023", "isbn" => "978-0-000-00003-3", "price" => 26.99, "cover" => false],
        ],
        "Science" => [
            ["title" => "Exploring Science Grade 3", "author" => "Priya Nair", "edition" => "2021", "isbn" => "978-0-000-00004-4", "price" => 22.50, "cover" => false],
        ],
    ],
    "4th Grade" => [
        "Mathematics" => [
            ["title" => "Fractions and Beyond Grade 4", "author" => "Helen Marsh", "edition" => "2020", "isbn" => "978-0-000-04001-1", "price" => 27.99, "cover" => true],
            ["title" => "Problem Solving Grade 4", "author" => "Helen Marsh", "edition" => "2017", "isbn" => "978-0-000-04002-2", "price" => 25.00, "cover" => false],
        ],
        "English Language Arts" => [
            ["title" => "Grammar in Action Grade 4", "author" => "Oliver Bennett", "edition" => "2022", "isbn" => "978-0-000-04003-3", "price" => 23.50, "cover" => false],
            ["title" => "Creative Writing Grade 4", "author" => "Oliver Bennett", "edition" => "2019", "isbn" => "978-0-000-04004-4", "price" => 22.00, "cover" => true],
        ],
        "Science" => [
            ["title" => "Earth and Space Grade 4", "author" => "Priya Nair", "edition" => "2021", "isbn" => "978-0-000-04005-5", "price" => 26.50, "cover" => false],
            ["title" => "Living Things Grade 4", "author" => "Priya Nair", "edition" => "2018", "isbn" => "978-0-000-04006-6", "price" => 24.00, "cover" => false],
        ],
        "Social Studies" => [
            ["title" => "Communities Near and Far Grade 4", "author" => "Marcus Webb", "edition" => "2020", "isbn" => "978-0-000-04007-7", "price" => 21.99, "cover" => true],
            ["title" => "Our Country's History Grade 4", "author" => "Marcus Webb", "edition" => "2016", "isbn" => "978-0-000-04008-8", "price" => 20.50, "cover" => false],
        ],
        // Deliberately no books yet — exercises curriculum.js's
        // "No books added for this subject yet." empty state.
        "Art" => [],
    ],
    "5th Grade" => [
        "Mathematics" => [
            ["title" => "Decimals and Data Grade 5", "author" => "Helen Marsh", "edition" => "2022", "isbn" => "978-0-000-05001-1", "price" => 28.99, "cover" => false],
            ["title" => "Geometry Basics Grade 5", "author" => "Helen Marsh", "edition" => "2019", "isbn" => "978-0-000-05002-2", "price" => 26.75, "cover" => true],
        ],
        "English Language Arts" => [
            ["title" => "Reading Comprehension Grade 5", "author" => "Grace Whitfield", "edition" => "2021", "isbn" => "978-0-000-05003-3", "price" => 24.99, "cover" => false],
            ["title" => "The Writer's Toolkit Grade 5", "author" => "Oliver Bennett", "edition" => "2018", "isbn" => "978-0-000-05004-4", "price" => 23.25, "cover" => false],
        ],
        "Science" => [
            ["title" => "Ecosystems Grade 5", "author" => "Priya Nair", "edition" => "2023", "isbn" => "978-0-000-05005-5", "price" => 27.50, "cover" => true],
            ["title" => "Matter and Energy Grade 5", "author" => "Ravi Chandran", "edition" => "2020", "isbn" => "978-0-000-05006-6", "price" => 25.99, "cover" => false],
        ],
        "Social Studies" => [
            ["title" => "Exploring Geography Grade 5", "author" => "Marcus Webb", "edition" => "2021", "isbn" => "978-0-000-05007-7", "price" => 22.99, "cover" => false],
        ],
    ],
    "6th Grade" => [
        "Mathematics" => [
            ["title" => "Ratios and Proportions Grade 6", "author" => "Helen Marsh", "edition" => "2020", "isbn" => "978-0-000-06001-1", "price" => 29.99, "cover" => true],
            ["title" => "Pre-Algebra Foundations Grade 6", "author" => "Helen Marsh", "edition" => "2017", "isbn" => "978-0-000-06002-2", "price" => 27.50, "cover" => false],
        ],
        "English Language Arts" => [
            ["title" => "Literature and Language Grade 6", "author" => "Grace Whitfield", "edition" => "2022", "isbn" => "978-0-000-06003-3", "price" => 25.50, "cover" => false],
            ["title" => "Essay Writing Grade 6", "author" => "Oliver Bennett", "edition" => "2019", "isbn" => "978-0-000-06004-4", "price" => 24.00, "cover" => false],
        ],
        "Science" => [
            ["title" => "Cells and Life Grade 6", "author" => "Priya Nair", "edition" => "2021", "isbn" => "978-0-000-06005-5", "price" => 28.25, "cover" => true],
            ["title" => "Weather and Climate Grade 6", "author" => "Ravi Chandran", "edition" => "2018", "isbn" => "978-0-000-06006-6", "price" => 26.00, "cover" => false],
        ],
        "Social Studies" => [
            ["title" => "Ancient Civilizations Grade 6", "author" => "Marcus Webb", "edition" => "2020", "isbn" => "978-0-000-06007-7", "price" => 23.99, "cover" => false],
            ["title" => "World Cultures Grade 6", "author" => "Marcus Webb", "edition" => "2016", "isbn" => "978-0-000-06008-8", "price" => 22.50, "cover" => false],
        ],
        // Deliberately no books yet — same empty-state purpose as Art above.
        "Music" => [],
    ],
    "7th Grade" => [
        "Mathematics" => [
            ["title" => "Linear Equations Grade 7", "author" => "Helen Marsh", "edition" => "2021", "isbn" => "978-0-000-07001-1", "price" => 30.99, "cover" => true],
            ["title" => "Statistics and Probability Grade 7", "author" => "Helen Marsh", "edition" => "2018", "isbn" => "978-0-000-07002-2", "price" => 28.50, "cover" => false],
        ],
        "English Language Arts" => [
            ["title" => "Novels and Narratives Grade 7", "author" => "Grace Whitfield", "edition" => "2022", "isbn" => "978-0-000-07003-3", "price" => 26.99, "cover" => false],
            ["title" => "Persuasive Writing Grade 7", "author" => "Oliver Bennett", "edition" => "2019", "isbn" => "978-0-000-07004-4", "price" => 25.25, "cover" => true],
        ],
        "Science" => [
            ["title" => "Chemistry Basics Grade 7", "author" => "Priya Nair", "edition" => "2020", "isbn" => "978-0-000-07005-5", "price" => 29.50, "cover" => false],
            ["title" => "Human Biology Grade 7", "author" => "Ravi Chandran", "edition" => "2017", "isbn" => "978-0-000-07006-6", "price" => 27.00, "cover" => false],
        ],
        "History" => [
            ["title" => "World History Grade 7", "author" => "Marcus Webb", "edition" => "2021", "isbn" => "978-0-000-07007-7", "price" => 24.99, "cover" => false],
            ["title" => "Civics and Government Grade 7", "author" => "Marcus Webb", "edition" => "2018", "isbn" => "978-0-000-07008-8", "price" => 23.50, "cover" => false],
        ],
    ],
    "8th Grade" => [
        "Mathematics" => [
            ["title" => "Algebra I Grade 8", "author" => "Helen Marsh", "edition" => "2022", "isbn" => "978-0-000-08001-1", "price" => 32.99, "cover" => true],
            ["title" => "Functions and Graphs Grade 8", "author" => "Helen Marsh", "edition" => "2019", "isbn" => "978-0-000-08002-2", "price" => 30.50, "cover" => false],
        ],
        "English Language Arts" => [
            ["title" => "American Literature Grade 8", "author" => "Grace Whitfield", "edition" => "2021", "isbn" => "978-0-000-08003-3", "price" => 27.99, "cover" => false],
            ["title" => "Research and Rhetoric Grade 8", "author" => "Oliver Bennett", "edition" => "2018", "isbn" => "978-0-000-08004-4", "price" => 26.50, "cover" => false],
        ],
        "Science" => [
            ["title" => "Physical Science Grade 8", "author" => "Priya Nair", "edition" => "2020", "isbn" => "978-0-000-08005-5", "price" => 31.25, "cover" => true],
            ["title" => "Environmental Science Grade 8", "author" => "Ravi Chandran", "edition" => "2017", "isbn" => "978-0-000-08006-6", "price" => 28.75, "cover" => false],
        ],
        "History" => [
            ["title" => "20th Century History Grade 8", "author" => "Marcus Webb", "edition" => "2021", "isbn" => "978-0-000-08007-7", "price" => 25.99, "cover" => false],
        ],
        // Deliberately no books yet — same empty-state purpose as above.
        "Physical Education" => [],
    ],
    // 9th-12th Grade are left with zero subjects on purpose, so
    // curriculum.html's "select a grade with nothing under it yet" case
    // is still reachable during testing.
];

// Short key => [name, email]. All under @seed.test (never a real address).
$seedUsers = [
    "maria" => ["name" => "Maria Gonzalez", "email" => "maria.gonzalez@seed.test"],
    "david" => ["name" => "David Kim", "email" => "david.kim@seed.test"],
    "fatima" => ["name" => "Fatima Haddad", "email" => "fatima.haddad@seed.test"],
    "liam" => ["name" => "Liam O'Brien", "email" => "liam.obrien@seed.test"],
    "aisha" => ["name" => "Aisha Bello", "email" => "aisha.bello@seed.test"],
    "noah" => ["name" => "Noah Petrov", "email" => "noah.petrov@seed.test"],
    "sofia" => ["name" => "Sofia Rossi", "email" => "sofia.rossi@seed.test"],
    "ethan" => ["name" => "Ethan Walker", "email" => "ethan.walker@seed.test"],
];

// parent key, child name, grade name. Spread across both curriculum-rich
// and curriculum-empty grades on purpose (David and Ethan each have one
// child in a grade with no books yet).
$seedChildren = [
    ["parent" => "maria", "name" => "Camila", "grade" => "4th Grade"],
    ["parent" => "david", "name" => "Ethan Jr.", "grade" => "6th Grade"],
    ["parent" => "david", "name" => "Olivia", "grade" => "9th Grade"],
    ["parent" => "fatima", "name" => "Yusuf", "grade" => "2nd Grade"],
    ["parent" => "liam", "name" => "Aoife", "grade" => "7th Grade"],
    ["parent" => "aisha", "name" => "Zainab", "grade" => "5th Grade"],
    ["parent" => "noah", "name" => "Ivan", "grade" => "8th Grade"],
    ["parent" => "noah", "name" => "Nadia", "grade" => "1st Grade"],
    ["parent" => "sofia", "name" => "Marco", "grade" => "3rd Grade"],
    ["parent" => "ethan", "name" => "Grace", "grade" => "11th Grade"],
];

// label => listing spec. "overpriced" bumps asking_price 30% above the
// AI suggestion, so the 5 listings used as disputes below have a price a
// parent would plausibly flag. ownPhoto/book-cover combinations are
// chosen so all 3 gallery states (own photo / book-cover fallback /
// neither) each show up several times across the batch.
$listingSpecs = [
    "maria-counting" => ["seller" => "maria", "book" => "Counting Adventures Grade 1", "condition" => "Like New", "type" => "sell", "status" => "active", "ownPhoto" => true, "daysAgo" => 2, "overpriced" => true, "desc" => "Barely used for one semester of 1st grade. Comes from a smoke-free, pet-free home."],
    "maria-storytime" => ["seller" => "maria", "book" => "Storytime Grade 1", "condition" => "Good", "type" => "sell", "status" => "active", "ownPhoto" => false, "daysAgo" => 9, "overpriced" => false, "desc" => "Some wear on the cover but all pages intact. Great for read-aloud practice."],
    "maria-fractions" => ["seller" => "maria", "book" => "Fractions and Beyond Grade 4", "condition" => "Fair", "type" => "donate", "status" => "sold", "ownPhoto" => false, "daysAgo" => 20, "overpriced" => false, "desc" => "Giving this away — has some highlighter marks but still very usable. First come, first served!"],
    "fatima-first-steps" => ["seller" => "fatima", "book" => "First Steps in Reading Grade 1", "condition" => "New", "type" => "sell", "status" => "active", "ownPhoto" => false, "daysAgo" => 1, "overpriced" => false, "desc" => "Never used! Bought as a backup copy and didn't end up needing it. Still has the receipt."],
    "fatima-math2" => ["seller" => "fatima", "book" => "Math Adventures Grade 2", "condition" => "Good", "type" => "trade", "status" => "active", "ownPhoto" => true, "daysAgo" => 6, "overpriced" => false, "desc" => "Looking to trade for the Grade 3 edition of the same series — my son just moved up a grade."],
    "liam-science3" => ["seller" => "liam", "book" => "Exploring Science Grade 3", "condition" => "Poor", "type" => "sell", "status" => "active", "ownPhoto" => false, "daysAgo" => 14, "overpriced" => false, "desc" => "Cover is torn and there's some water damage on the back pages, but all the content pages are readable. Priced to reflect the condition."],
    "liam-ratios" => ["seller" => "liam", "book" => "Ratios and Proportions Grade 6", "condition" => "Like New", "type" => "sell", "status" => "active", "ownPhoto" => false, "daysAgo" => 4, "overpriced" => true, "desc" => "Used for one unit only, looks almost brand new."],
    "liam-prealgebra" => ["seller" => "liam", "book" => "Pre-Algebra Foundations Grade 6", "condition" => "Fair", "type" => "sell", "status" => "removed", "ownPhoto" => false, "daysAgo" => 30, "overpriced" => false, "desc" => "Listing removed — book has already been passed on to a family friend."],
    "david-cells" => ["seller" => "david", "book" => "Cells and Life Grade 6", "condition" => "New", "type" => "sell", "status" => "active", "ownPhoto" => true, "daysAgo" => 3, "overpriced" => true, "desc" => "Extra copy from a school book drive, never opened."],
    "david-weather" => ["seller" => "david", "book" => "Weather and Climate Grade 6", "condition" => "Good", "type" => "sell", "status" => "active", "ownPhoto" => false, "daysAgo" => 11, "overpriced" => false, "desc" => "Light wear on the corners, no markings inside."],
    "aisha-decimals" => ["seller" => "aisha", "book" => "Decimals and Data Grade 5", "condition" => "Good", "type" => "sell", "status" => "active", "ownPhoto" => false, "daysAgo" => 8, "overpriced" => false, "desc" => "Good used condition, a few pencil notes in the margins that erase easily."],
    "aisha-geometry" => ["seller" => "aisha", "book" => "Geometry Basics Grade 5", "condition" => "Like New", "type" => "sell", "status" => "sold", "ownPhoto" => false, "daysAgo" => 18, "overpriced" => false, "desc" => "Sold! Thanks to everyone who reached out."],
    "noah-linear-equations" => ["seller" => "noah", "book" => "Linear Equations Grade 7", "condition" => "New", "type" => "sell", "status" => "active", "ownPhoto" => true, "daysAgo" => 5, "overpriced" => true, "desc" => "Ordered two by mistake — this one has never been opened."],
    "noah-persuasive" => ["seller" => "noah", "book" => "Persuasive Writing Grade 7", "condition" => "Good", "type" => "trade", "status" => "active", "ownPhoto" => false, "daysAgo" => 7, "overpriced" => false, "desc" => "Would love to trade for the Grade 8 edition if anyone has one to spare."],
    "sofia-algebra" => ["seller" => "sofia", "book" => "Algebra I Grade 8", "condition" => "Like New", "type" => "sell", "status" => "active", "ownPhoto" => false, "daysAgo" => 2, "overpriced" => true, "desc" => "Used for a single semester, no writing inside, corners still crisp."],
    "sofia-physical-science" => ["seller" => "sofia", "book" => "Physical Science Grade 8", "condition" => "Fair", "type" => "sell", "status" => "active", "ownPhoto" => true, "daysAgo" => 13, "overpriced" => false, "desc" => "Shows normal wear from a full school year but nothing missing or torn."],
    "ethan-reading-journeys" => ["seller" => "ethan", "book" => "Reading Journeys Grade 2", "condition" => "Good", "type" => "sell", "status" => "active", "ownPhoto" => false, "daysAgo" => 16, "overpriced" => false, "desc" => "Good condition, from a smoke-free home. Happy to bundle with other 2nd grade books if you need more."],
];

// One thread = one listing + one buyer messaging that listing's seller.
// "read" is from the RECEIVER's point of view (i.e. whether that message
// has been read yet), matching the messages.is_read column.
$messageThreads = [
    ["listing" => "maria-counting", "buyer" => "david", "seller" => "maria", "messages" => [
        ["from" => "buyer", "text" => "Hi! Is this still available?", "hoursAgo" => 48, "read" => true],
        ["from" => "seller", "text" => "Yes, it's still up for grabs!", "hoursAgo" => 47, "read" => true],
        ["from" => "buyer", "text" => "Would you take \$15 instead of \$17?", "hoursAgo" => 46, "read" => true],
        ["from" => "seller", "text" => "I can do \$16, final offer.", "hoursAgo" => 45, "read" => true],
        ["from" => "buyer", "text" => "Deal — can we meet this weekend?", "hoursAgo" => 2, "read" => false],
    ]],
    ["listing" => "maria-storytime", "buyer" => "sofia", "seller" => "maria", "messages" => [
        ["from" => "buyer", "text" => "Hey, does this book have any writing or highlighting inside?", "hoursAgo" => 30, "read" => true],
        ["from" => "seller", "text" => "Just a little pencil on the first few pages, nothing major.", "hoursAgo" => 29, "read" => true],
        ["from" => "buyer", "text" => "Perfect, I'll take it — thank you!", "hoursAgo" => 28, "read" => true],
    ]],
    ["listing" => "maria-counting", "buyer" => "liam", "seller" => "maria", "messages" => [
        ["from" => "buyer", "text" => "Is this one already sold? Saw it's popular.", "hoursAgo" => 20, "read" => true],
        ["from" => "seller", "text" => "Not yet — still available!", "hoursAgo" => 19, "read" => true],
        ["from" => "buyer", "text" => "Great, I'm interested too, I'll follow up soon.", "hoursAgo" => 1, "read" => false],
    ]],
    ["listing" => "liam-ratios", "buyer" => "fatima", "seller" => "liam", "messages" => [
        ["from" => "buyer", "text" => "Hi, is the cover in good shape? Photos look a little worn.", "hoursAgo" => 40, "read" => true],
        ["from" => "seller", "text" => "It's mostly the corners, the pages are all clean.", "hoursAgo" => 39, "read" => true],
        ["from" => "buyer", "text" => "Got it. Would you do \$22 instead of the asking price?", "hoursAgo" => 5, "read" => false],
        ["from" => "buyer", "text" => "Also, could you hold it until Friday?", "hoursAgo" => 4, "read" => false],
    ]],
    ["listing" => "david-cells", "buyer" => "ethan", "seller" => "david", "messages" => [
        ["from" => "buyer", "text" => "Hi, is this the current edition used in class?", "hoursAgo" => 15, "read" => true],
        ["from" => "seller", "text" => "Yes, this is the one on this year's list.", "hoursAgo" => 14, "read" => true],
        ["from" => "buyer", "text" => "Perfect, I'll take it!", "hoursAgo" => 13, "read" => true],
    ]],
    ["listing" => "fatima-first-steps", "buyer" => "maria", "seller" => "fatima", "messages" => [
        ["from" => "buyer", "text" => "Hi, is this brand new like the listing says?", "hoursAgo" => 22, "read" => true],
        ["from" => "seller", "text" => "Yes, never used, still has the receipt!", "hoursAgo" => 21, "read" => true],
        ["from" => "buyer", "text" => "Perfect. Would you do \$14?", "hoursAgo" => 20, "read" => true],
        ["from" => "buyer", "text" => "Just following up on this :)", "hoursAgo" => 3, "read" => false],
    ]],
    ["listing" => "noah-linear-equations", "buyer" => "aisha", "seller" => "noah", "messages" => [
        ["from" => "buyer", "text" => "Hi, is this still for sale?", "hoursAgo" => 10, "read" => true],
        ["from" => "seller", "text" => "Yep! Great condition, barely opened.", "hoursAgo" => 9, "read" => true],
        ["from" => "buyer", "text" => "Sounds good, I'll take it.", "hoursAgo" => 8, "read" => true],
    ]],
];

// One report per row. "resolution" is null for pending ones.
$priceReportSpecs = [
    ["listing" => "maria-counting", "reporter" => "noah", "reason" => "This seems priced higher than similar copies I've seen — can you double check?", "daysAgo" => 6, "resolution" => null],
    ["listing" => "liam-ratios", "reporter" => "aisha", "reason" => "The asking price seems high for a book in this condition.", "daysAgo" => 3, "resolution" => null],
    ["listing" => "noah-linear-equations", "reporter" => "ethan", "reason" => "Same book is listed for less elsewhere in similar condition.", "daysAgo" => 10, "resolution" => ["type" => "override", "adminResponse" => null, "resolvedDaysAgo" => 8]],
    ["listing" => "david-cells", "reporter" => "sofia", "reason" => "New condition but priced almost like a store copy — seems off for a used listing.", "daysAgo" => 12, "resolution" => ["type" => "override", "adminResponse" => "We adjusted the price to better match a New-condition copy at this edition's age.", "resolvedDaysAgo" => 9]],
    ["listing" => "sofia-algebra", "reporter" => "david", "reason" => "Price seems steep for Like New condition.", "daysAgo" => 7, "resolution" => ["type" => "flag", "adminResponse" => "Your price is fair for a Like New copy of this edition — no change needed.", "resolvedDaysAgo" => 5]],
];

// ============================================================
// RUN — wrapped in one transaction so a failure partway through
// leaves the database untouched instead of half-seeded.
// ============================================================

try {
    $mysql->begin_transaction();

    echo "Seeding curriculum (subjects + books)...\n";
    $bookIds = [];
    $bookMeta = [];
    foreach ($curriculumData as $gradeName => $subjects) {
        $gradeLevelId = seed_get_grade_level_id($mysql, SEED_SCHOOL_ID, $gradeName);
        foreach ($subjects as $subjectName => $books) {
            $subjectId = seed_find_or_create_subject($mysql, $gradeLevelId, $subjectName);
            foreach ($books as $book) {
                $bookId = seed_find_or_create_book(
                    $mysql, $subjectId, $book["title"], $book["author"], $book["edition"],
                    $book["isbn"], $book["price"], $book["cover"], $mathPngPath, $uploadsBooksDir
                );
                $bookIds[$book["title"]] = $bookId;
                $bookMeta[$book["title"]] = ["price" => $book["price"], "edition" => $book["edition"]];
            }
        }
    }

    echo "Resetting + creating seed parent accounts...\n";
    $projectRoot = __DIR__ . "/..";
    seed_delete_account_listing_images($mysql, array_column($seedUsers, "email"), $projectRoot);
    seed_reset_accounts($mysql, array_column($seedUsers, "email"));
    $passwordHash = password_hash(SEED_PASSWORD, PASSWORD_DEFAULT);
    $userIds = [];
    foreach ($seedUsers as $key => $user) {
        $userIds[$key] = seed_create_user($mysql, SEED_SCHOOL_ID, $user["name"], $user["email"], $passwordHash);
    }

    foreach ($seedChildren as $child) {
        $gradeLevelId = seed_get_grade_level_id($mysql, SEED_SCHOOL_ID, $child["grade"]);
        seed_create_child($mysql, $userIds[$child["parent"]], $child["name"], $gradeLevelId);
    }

    echo "Creating listings...\n";
    $listingIds = [];
    foreach ($listingSpecs as $label => $spec) {
        $meta = $bookMeta[$spec["book"]];
        $suggestedPrice = calculate_suggested_price($meta["price"], $spec["condition"], $meta["edition"]);
        $askingPrice = $spec["overpriced"] ? round($suggestedPrice * 1.3, 2) : $suggestedPrice;
        $justification = default_justification($spec["condition"], $meta["edition"]);

        $listingId = seed_create_listing(
            $mysql, $bookIds[$spec["book"]], $userIds[$spec["seller"]], $spec["condition"], $spec["type"],
            $spec["status"], $askingPrice, $suggestedPrice, $justification, $spec["desc"], seed_days_ago($spec["daysAgo"])
        );
        $listingIds[$label] = $listingId;

        if ($spec["ownPhoto"]) {
            $imagePath = seed_copy_image($mathPngPath, $uploadsListingsDir, "/uploads/listings/");
            seed_attach_listing_image($mysql, $listingId, $imagePath);
        }
    }

    echo "Creating message threads...\n";
    foreach ($messageThreads as $thread) {
        $listingId = $listingIds[$thread["listing"]];
        $buyerId = $userIds[$thread["buyer"]];
        $sellerId = $userIds[$thread["seller"]];
        foreach ($thread["messages"] as $message) {
            $senderId = $message["from"] === "buyer" ? $buyerId : $sellerId;
            $receiverId = $message["from"] === "buyer" ? $sellerId : $buyerId;
            seed_send_message(
                $mysql, $listingId, $senderId, $receiverId, $message["text"],
                $message["read"] ? 1 : 0, seed_hours_ago($message["hoursAgo"])
            );
        }
    }

    echo "Creating price disputes...\n";
    foreach ($priceReportSpecs as $report) {
        $listingId = $listingIds[$report["listing"]];
        $reporterId = $userIds[$report["reporter"]];
        $createdAt = seed_days_ago($report["daysAgo"]);
        $resolution = $report["resolution"];

        if ($resolution === null) {
            seed_create_price_report($mysql, $listingId, $reporterId, $report["reason"], "pending", null, $createdAt, null);
            continue;
        }

        $resolvedAt = seed_days_ago($resolution["resolvedDaysAgo"]);
        if ($resolution["type"] === "override") {
            // Mirrors what price_report.php's override_price_report action
            // actually does: lower the listing's real asking_price, not
            // just record a note.
            $stmt = $mysql->prepare("SELECT asking_price FROM listings WHERE id = ?");
            $stmt->bind_param("i", $listingId);
            $stmt->execute();
            $currentPrice = (float)$stmt->get_result()->fetch_row()[0];
            seed_set_listing_price($mysql, $listingId, round($currentPrice * 0.85, 2));
        }

        seed_create_price_report(
            $mysql, $listingId, $reporterId, $report["reason"], "resolved",
            $resolution["adminResponse"], $createdAt, $resolvedAt
        );
    }

    $mysql->commit();

    echo "\nDone! Seed accounts (all password: " . SEED_PASSWORD . "):\n";
    foreach ($seedUsers as $user) {
        echo "  - {$user["email"]} ({$user["name"]})\n";
    }
    echo "\n" . count($listingIds) . " listings, " . count($messageThreads) . " message threads, "
        . count($priceReportSpecs) . " price disputes created.\n";
    echo "Log in as admin@example.com to see the new subjects/books and the dispute queue.\n";
} catch (Throwable $e) {
    $mysql->rollback();
    fwrite(STDERR, "Seeding failed, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
