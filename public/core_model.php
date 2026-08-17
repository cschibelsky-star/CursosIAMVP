<?php
declare(strict_types=1);

function ensureCoreCourseModel(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS courses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        audience VARCHAR(180) NOT NULL,
        objective TEXT NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'rascunho',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sources (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        source_type VARCHAR(30) NOT NULL,
        name VARCHAR(255) NOT NULL,
        content LONGTEXT NULL,
        processing_status VARCHAR(40) NOT NULL DEFAULT 'processado',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sources_course(course_id),
        CONSTRAINT fk_sources_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS modules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        position INT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        objective TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_module_position(course_id,position),
        CONSTRAINT fk_modules_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lessons (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        module_id INT UNSIGNED NOT NULL,
        position INT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        objective TEXT NOT NULL,
        script LONGTEXT NOT NULL,
        review_status VARCHAR(40) NOT NULL DEFAULT 'pendente',
        reviewer_notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_lesson_position(module_id,position),
        CONSTRAINT fk_lessons_module FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
