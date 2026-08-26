-- nextGrade database schema + seed data
-- Run this once in phpMyAdmin (Import tab) or via the mysql CLI to set up
-- everything the PHP API in server/ expects to already exist.

CREATE DATABASE IF NOT EXISTS nextgrade_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nextgrade_db;

-- ============================================================
-- SCHOOLS
-- One row per school. Everything else (grades, users, ...) is
-- scoped to a school_id, even though we only seed one school for now.
-- ============================================================
CREATE TABLE schools (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- GRADE LEVELS
-- e.g. "3rd Grade" for the 2025-2026 academic_year, under a school.
-- ============================================================
CREATE TABLE grade_levels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  name VARCHAR(50) NOT NULL,
  academic_year VARCHAR(20) NOT NULL,
  FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SUBJECTS
-- e.g. "Mathematics", under a specific grade level.
-- ============================================================
CREATE TABLE subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grade_level_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- BOOKS
-- The curriculum's required textbooks, under a subject.
-- reference_price is the "new" price admins set — the AI pricing
-- feature discounts off of this based on condition/age.
-- ============================================================
CREATE TABLE books (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  author VARCHAR(150),
  edition VARCHAR(50),
  isbn VARCHAR(20),
  reference_price DECIMAL(8,2) NOT NULL,
  cover_image VARCHAR(255),
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- USERS
-- Both parents and admins live in one table, split by `role`.
-- password_hash stores the output of PHP's password_hash() —
-- never a plaintext password.
-- ============================================================
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  school_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('parent', 'admin') NOT NULL DEFAULT 'parent',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- AUTH TOKENS
-- Backs token-based auth (client stores the token in localStorage and
-- sends it as `Authorization: Bearer <token>` on every request — see
-- server/includes/session_helpers.php). One row per login; logging in
-- from two browsers creates two valid tokens. Logout deletes the row;
-- expires_at is checked on every request so stale tokens stop working
-- even without an explicit logout.
-- ============================================================
CREATE TABLE auth_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(64) NOT NULL UNIQUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- CHILDREN
-- A parent's kids, each tied to the grade level they're in.
-- ============================================================
CREATE TABLE children (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  grade_level_id INT NOT NULL,
  FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- LISTINGS
-- One parent selling/trading/donating one copy of one book.
-- ai_suggested_price/ai_justification are filled in by
-- api/ai_pricing.php when the listing is created.
-- ============================================================
CREATE TABLE listings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NOT NULL,
  seller_id INT NOT NULL,
  `condition` ENUM('New', 'Like New', 'Good', 'Fair', 'Poor') NOT NULL,
  listing_type ENUM('sell', 'trade', 'donate') NOT NULL,
  asking_price DECIMAL(8,2),
  ai_suggested_price DECIMAL(8,2),
  ai_justification TEXT,
  status ENUM('active', 'sold', 'removed') NOT NULL DEFAULT 'active',
  description TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- `condition` is backtick-quoted because CONDITION is a MySQL reserved word.

-- ============================================================
-- LISTING IMAGES
-- A listing can have several photos; each row is one image file.
-- ============================================================
CREATE TABLE listing_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  listing_id INT NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- PRICE REPORTS
-- A parent flags a listing's price as unfair; an admin resolves it.
-- ============================================================
CREATE TABLE price_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  listing_id INT NOT NULL,
  reported_by INT NOT NULL,
  reason TEXT,
  status ENUM('pending', 'resolved') NOT NULL DEFAULT 'pending',
  admin_response TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
  FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- MESSAGES
-- Always tied to a listing, so a conversation = one listing + two users.
-- ============================================================
CREATE TABLE messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  listing_id INT NOT NULL,
  sender_id INT NOT NULL,
  receiver_id INT NOT NULL,
  content TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- SEED DATA
-- One school, all 12 grade levels, a couple of subjects/books so
-- curriculum.php and the marketplace have real data to return right
-- away. Two test accounts (both password: Password123!) so auth.php
-- can be tested without signing up through the UI first.
-- ============================================================

INSERT INTO schools (id, name, address) VALUES
  (1, 'Riverside Elementary', '123 Riverside Ave, Springfield');

INSERT INTO grade_levels (school_id, name, academic_year) VALUES
  (1, '1st Grade', '2025-2026'),
  (1, '2nd Grade', '2025-2026'),
  (1, '3rd Grade', '2025-2026'),
  (1, '4th Grade', '2025-2026'),
  (1, '5th Grade', '2025-2026'),
  (1, '6th Grade', '2025-2026'),
  (1, '7th Grade', '2025-2026'),
  (1, '8th Grade', '2025-2026'),
  (1, '9th Grade', '2025-2026'),
  (1, '10th Grade', '2025-2026'),
  (1, '11th Grade', '2025-2026'),
  (1, '12th Grade', '2025-2026');

-- Subjects + books for 2nd Grade (grade_level_id = 2) and 3rd Grade (id = 3)
INSERT INTO subjects (grade_level_id, name) VALUES
  (2, 'Mathematics'),
  (2, 'Reading'),
  (3, 'Mathematics'),
  (3, 'Science');

INSERT INTO books (subject_id, title, author, edition, isbn, reference_price) VALUES
  (1, 'Math Adventures Grade 2', 'Jane Carter', '2023', '978-0-000-00001-1', 24.99),
  (2, 'Reading Journeys Grade 2', 'Mark Ellis', '2022', '978-0-000-00002-2', 19.99),
  (3, 'Math Adventures Grade 3', 'Jane Carter', '2023', '978-0-000-00003-3', 26.99),
  (4, 'Exploring Science Grade 3', 'Priya Nair', '2021', '978-0-000-00004-4', 22.50);

-- password_hash for both accounts below is "Password123!"
INSERT INTO users (school_id, name, email, password_hash, role) VALUES
  (1, 'Alex Parent', 'parent@example.com', '$2y$10$8Uypra0tVhrkQm1rnkA4N.l50E./iKGu2l7kdHc3hAkxK7oftGYh.', 'parent'),
  (1, 'Sam Admin', 'admin@example.com', '$2y$10$8Uypra0tVhrkQm1rnkA4N.l50E./iKGu2l7kdHc3hAkxK7oftGYh.', 'admin');

INSERT INTO children (parent_id, name, grade_level_id) VALUES
  (1, 'Jordan', 2);
