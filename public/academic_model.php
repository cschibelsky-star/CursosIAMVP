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
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_students_name(name),
        INDEX idx_students_email(email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS organizations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        organization_type VARCHAR(60) NOT NULL DEFAULT 'orgao_publico',
        city VARCHAR(120) NULL,
        state VARCHAR(40) NULL,
        contact_name VARCHAR(180) NULL,
        contact_email VARCHAR(180) NULL,
        contract_reference VARCHAR(120) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_organizations_name(name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cohorts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        organization_id INT UNSIGNED NULL,
        name VARCHAR(180) NOT NULL,
        modality VARCHAR(30) NOT NULL DEFAULT 'online',
        planned_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
        start_date DATE NULL,
        end_date DATE NULL,
        location VARCHAR(220) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'planejada',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cohorts_course(course_id),
        INDEX idx_cohorts_org(organization_id),
        CONSTRAINT fk_cohorts_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
        CONSTRAINT fk_cohorts_org FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS enrollments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        student_id INT UNSIGNED NOT NULL,
        course_id INT UNSIGNED NOT NULL,
        cohort_id INT UNSIGNED NULL,
        enrollment_type VARCHAR(30) NOT NULL DEFAULT 'individual',
        status VARCHAR(40) NOT NULL DEFAULT 'matriculado',
        payment_status VARCHAR(40) NOT NULL DEFAULT 'pendente',
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(60) NULL,
        paid_at DATETIME NULL,
        enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        last_seen_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_student_course_cohort(student_id,course_id,cohort_id),
        INDEX idx_enrollments_student(student_id),
        INDEX idx_enrollments_course(course_id),
        INDEX idx_enrollments_cohort(cohort_id),
        CONSTRAINT fk_enrollments_student FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,
        CONSTRAINT fk_enrollments_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
        CONSTRAINT fk_enrollments_cohort FOREIGN KEY(cohort_id) REFERENCES cohorts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lesson_progress (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT UNSIGNED NOT NULL,
        lesson_id INT UNSIGNED NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'nao_iniciada',
        watched_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        total_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        percent_complete DECIMAL(5,2) NOT NULL DEFAULT 0,
        last_position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        last_seen_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_progress_enrollment_lesson(enrollment_id,lesson_id),
        INDEX idx_progress_enrollment(enrollment_id),
        INDEX idx_progress_lesson(lesson_id),
        CONSTRAINT fk_progress_enrollment FOREIGN KEY(enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
        CONSTRAINT fk_progress_lesson FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cohort_id INT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        session_date DATE NOT NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        planned_minutes INT UNSIGNED NOT NULL DEFAULT 0,
        location VARCHAR(220) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attendance_sessions_cohort(cohort_id),
        CONSTRAINT fk_attendance_sessions_cohort FOREIGN KEY(cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id INT UNSIGNED NOT NULL,
        enrollment_id INT UNSIGNED NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'presente',
        minutes_attended INT UNSIGNED NOT NULL DEFAULT 0,
        check_in_at DATETIME NULL,
        check_out_at DATETIME NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_attendance_session_enrollment(session_id,enrollment_id),
        INDEX idx_attendance_enrollment(enrollment_id),
        CONSTRAINT fk_attendance_session FOREIGN KEY(session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_attendance_enrollment FOREIGN KEY(enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS certificates (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT UNSIGNED NOT NULL,
        certificate_type VARCHAR(30) NOT NULL DEFAULT 'certificado',
        certificate_code VARCHAR(120) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pendente',
        issued_at DATETIME NULL,
        validation_hash VARCHAR(180) NULL,
        file_path VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_certificate_enrollment(enrollment_id),
        UNIQUE KEY uq_certificate_code(certificate_code),
        CONSTRAINT fk_certificates_enrollment FOREIGN KEY(enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function academicEnrollmentMetrics(PDO $pdo, int $enrollmentId): array
{
    $stmt = $pdo->prepare("SELECT
        COUNT(l.id) AS total_lessons,
        SUM(CASE WHEN lp.status='concluida' OR lp.percent_complete>=100 THEN 1 ELSE 0 END) AS completed_lessons,
        COALESCE(SUM(lp.watched_seconds),0) AS watched_seconds,
        COALESCE(AVG(NULLIF(lp.percent_complete,0)),0) AS avg_progress
        FROM enrollments e
        INNER JOIN modules m ON m.course_id=e.course_id
        INNER JOIN lessons l ON l.module_id=m.id
        LEFT JOIN lesson_progress lp ON lp.enrollment_id=e.id AND lp.lesson_id=l.id
        WHERE e.id=?");
    $stmt->execute([$enrollmentId]);
    $online = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("SELECT
        COUNT(a.id) AS attendance_records,
        SUM(CASE WHEN a.status='presente' THEN 1 ELSE 0 END) AS present_count,
        COALESCE(SUM(a.minutes_attended),0) AS attended_minutes,
        COALESCE(SUM(s.planned_minutes),0) AS planned_minutes
        FROM attendance a
        INNER JOIN attendance_sessions s ON s.id=a.session_id
        WHERE a.enrollment_id=?");
    $stmt->execute([$enrollmentId]);
    $presence = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return array_merge($online, $presence);
}
