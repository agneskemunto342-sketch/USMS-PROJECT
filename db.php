<?php
// db.php — SQLite bootstrap + seed for IUSMS prototype

function getDB(): PDO
{
    $path = __DIR__ . '/iusms.db';

    // Fail loudly if the directory is not writable
    if (!is_dir(__DIR__) || !is_writable(__DIR__)) {
        throw new RuntimeException(
            "Cannot create/write iusms.db — directory '" . __DIR__ . "' is not writable."
        );
    }

    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // SQLite does NOT enforce foreign keys unless explicitly told to
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');   // better concurrent reads

    return $db;
}

function initDB(): void
{
    $db = getDB();

    // Only create schema if the users table is missing
    $exists = $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='users'"
    )->fetchColumn();

    if (!$exists) {
        $db->exec("
            CREATE TABLE users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT    NOT NULL UNIQUE,   -- reg no OR email
                password_hash TEXT    NOT NULL,
                role          TEXT    NOT NULL CHECK(role IN ('admin','lecturer','student')),
                full_name     TEXT    NOT NULL,
                created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE students (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
                reg_number TEXT    NOT NULL UNIQUE,
                course     TEXT    NOT NULL,
                year       INTEGER NOT NULL CHECK(year BETWEEN 1 AND 6)
            );

            CREATE TABLE courses (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                code    TEXT    NOT NULL UNIQUE,
                name    TEXT    NOT NULL,
                credits INTEGER NOT NULL CHECK(credits > 0)
            );

            CREATE TABLE registrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
                course_id  INTEGER NOT NULL REFERENCES courses(id)  ON DELETE CASCADE,
                semester   TEXT    NOT NULL,
                UNIQUE(student_id, course_id, semester)
            );

            CREATE TABLE marks (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
                course_id  INTEGER NOT NULL REFERENCES courses(id)  ON DELETE CASCADE,
                cat_score  REAL    NOT NULL CHECK(cat_score  BETWEEN 0 AND 30),
                exam_score REAL    NOT NULL CHECK(exam_score BETWEEN 0 AND 70),
                UNIQUE(student_id, course_id)
            );

            CREATE TABLE financials (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id  INTEGER NOT NULL UNIQUE REFERENCES students(id) ON DELETE CASCADE,
                total_fees  REAL    NOT NULL CHECK(total_fees  >= 0),
                amount_paid REAL    NOT NULL DEFAULT 0 CHECK(amount_paid >= 0)
            );

            CREATE INDEX idx_reg_student ON registrations(student_id);
            CREATE INDEX idx_reg_course  ON registrations(course_id);
            CREATE INDEX idx_marks_stu   ON marks(student_id);
            CREATE INDEX idx_marks_crs   ON marks(course_id);
        ");
    }

    // Seed only if empty
    if ((int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
        seedDB($db);
    }
}

function seedDB(PDO $db): void
{
    // WARNING: 'password' is a demo-only credential. Never ship this.
    $hash = password_hash('password', PASSWORD_BCRYPT);

    $db->beginTransaction();
    try {
        // --- Admin ---
        $db->prepare("INSERT INTO users(username,password_hash,role,full_name) VALUES(?,?,?,?)")
           ->execute(['admin', $hash, 'admin', 'Registrar Admin']);

        // --- Lecturer ---
        $db->prepare("INSERT INTO users(username,password_hash,role,full_name) VALUES(?,?,?,?)")
           ->execute(['lecturer', $hash, 'lecturer', 'Dr. Jane Doe']);

        // --- Student ---
        $db->prepare("INSERT INTO users(username,password_hash,role,full_name) VALUES(?,?,?,?)")
           ->execute(['sc211001', $hash, 'student', 'Kate Awino']);
        $userId = (int) $db->lastInsertId();

        $db->prepare("INSERT INTO students(user_id,reg_number,course,year) VALUES(?,?,?,?)")
           ->execute([$userId, 'SC211/0001/2024', 'BSc Software Engineering', 2]);
        $studentId = (int) $db->lastInsertId();

        $db->prepare("INSERT INTO financials(student_id,total_fees,amount_paid) VALUES(?,?,?)")
           ->execute([$studentId, 120000.0, 75000.0]);

        // --- Courses ---
        $courses = [
            ['SCS304', 'Software Engineering', 3],
            ['SCS301', 'Database Systems',     3],
            ['SCS302', 'Operating Systems',    3],
            ['SCS303', 'Computer Networks',    4],
        ];
        $insCourse = $db->prepare("INSERT INTO courses(code,name,credits) VALUES(?,?,?)");
        foreach ($courses as $c) {
            $insCourse->execute($c);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// Bootstrap once per request
initDB();