<?php
declare(strict_types=1);

function ensureAcademicModel(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS students (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        email VARCHAR(180) NULL,
        phone VARCHAR(40) NULL,
        document_ref VARCHAR(80) NULL,
        city VARCHAR(120) NULL,
        state VARCHAR(40) NULL,
        active TINYINT(1