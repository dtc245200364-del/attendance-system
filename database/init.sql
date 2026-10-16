SET NAMES utf8mb4;
CREATE TABLE classes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(30) NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE students (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 class_id INT UNSIGNED NOT NULL,
 code VARCHAR(30) NOT NULL UNIQUE,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NULL,
 active BOOLEAN NOT NULL DEFAULT TRUE,
 FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE sessions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 class_id INT UNSIGNED NOT NULL,
 title VARCHAR(150) NOT NULL,
 starts_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_class_start (class_id, starts_at),
 FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE attendance (
 session_id INT UNSIGNED NOT NULL,
 student_id INT UNSIGNED NOT NULL,
 status ENUM('unmarked','present','absent','late') NOT NULL DEFAULT 'unmarked',
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (session_id, student_id),
 FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
 FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Snapshot the roster at session creation. Attendance rows remain when a student is archived.
-- Application account needs no schema modification privileges.
