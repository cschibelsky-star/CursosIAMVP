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
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS generation_engine VARCHAR(40) NULL AFTER status");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS generation_note TEXT NULL AFTER generation_engine");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS course_level VARCHAR(40) NULL AFTER objective");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS desired_hours DECIMAL(6,2) NULL AFTER course_level");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS modality VARCHAR(30) NULL AFTER desired_hours");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS language_style VARCHAR(80) NULL AFTER modality");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS expected_outcome TEXT NULL AFTER language_style");

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
    $pdo->exec("ALTER TABLE sources ADD COLUMN IF NOT EXISTS quality_status VARCHAR(20) NULL AFTER processing_status");
    $pdo->exec("ALTER TABLE sources ADD COLUMN IF NOT EXISTS quality_note VARCHAR(255) NULL AFTER quality_status");
    $pdo->exec("ALTER TABLE sources ADD COLUMN IF NOT EXISTS active_for_generation TINYINT(1) NOT NULL DEFAULT 1 AFTER quality_note");
    $pdo->exec("ALTER TABLE sources ADD COLUMN IF NOT EXISTS content_hash CHAR(64) NULL AFTER active_for_generation");
    try { $pdo->exec("CREATE INDEX idx_sources_hash ON sources(content_hash)"); } catch (Throwable $e) {}
    $pdo->exec("UPDATE sources SET content_hash=SHA2(content,256) WHERE content_hash IS NULL AND content IS NOT NULL");

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
