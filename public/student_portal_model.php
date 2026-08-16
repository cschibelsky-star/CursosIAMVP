<?php
declare(strict_types=1);

function ensureStudentPortal(PDO $pdo): void
{
    try { $pdo->exec("ALTER TABLE enrollments ADD COLUMN portal_access_code VARCHAR(64) NULL AFTER last_seen_at"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE enrollments ADD COLUMN last_portal_login_at DATETIME NULL AFTER portal_access_code"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE lessons ADD COLUMN video_url VARCHAR(500) NULL AFTER script"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE lessons ADD COLUMN estimated_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER video_url"); } catch (Throwable $e) {}

    $stmt = $pdo->query("SELECT id FROM enrollments WHERE portal_access_code IS NULL OR portal_access_code='' ORDER BY id");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $enrollmentId) {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
            $check = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE portal_access_code=?');
            $check->execute([$code]);
        } while ((int)$check->fetchColumn() > 0);
        $update = $pdo->prepare('UPDATE enrollments SET portal_access_code=? WHERE id=? AND (portal_access_code IS NULL OR portal_access_code=\'\')');
        $update->execute([$code, (int)$enrollmentId]);
    }

    try { $pdo->exec('ALTER TABLE enrollments ADD UNIQUE KEY uq_enrollments_portal_access_code (portal_access_code)'); } catch (Throwable $e) {}
}

function portalEnrollmentByCode(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare("SELECT e.*,s.name AS student_name,s.email,s.phone,c.title AS course_title,c.objective AS course_objective,ch.name AS cohort_name,ch.modality,ch.start_date,ch.end_date,ch.location,o.name AS organization_name
        FROM enrollments e
        INNER JOIN students s ON s.id=e.student_id
        INNER JOIN courses c ON c.id=e.course_id
        LEFT JOIN cohorts ch ON ch.id=e.cohort_id
        LEFT JOIN organizations o ON o.id=ch.organization_id
        WHERE e.portal_access_code=? AND s.active=1
        LIMIT 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function portalCourseLessons(PDO $pdo, int $enrollmentId, int $courseId): array
{
    $stmt = $pdo->prepare("SELECT m.id AS module_id,m.position AS module_position,m.title AS module_title,l.id AS lesson_id,l.position AS lesson_position,l.title AS lesson_title,l.objective,l.video_url,l.estimated_minutes,
        COALESCE(lp.status,'nao_iniciada') AS progress_status,COALESCE(lp.watched_seconds,0) AS watched_seconds,COALESCE(lp.total_seconds,0) AS total_seconds,COALESCE(lp.percent_complete,0) AS percent_complete,COALESCE(lp.last_position_seconds,0) AS last_position_seconds
        FROM modules m
        INNER JOIN lessons l ON l.module_id=m.id
        LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.enrollment_id=?
        WHERE m.course_id=?
        ORDER BY m.position,l.position");
    $stmt->execute([$enrollmentId,$courseId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function portalAttendance(PDO $pdo, int $enrollmentId, ?int $cohortId): array
{
    if (!$cohortId) return [];
    $stmt = $pdo->prepare("SELECT s.*,COALESCE(a.status,'nao_lancada') AS attendance_status,COALESCE(a.minutes_attended,0) AS minutes_attended
        FROM attendance_sessions s
        LEFT JOIN attendance a ON a.session_id=s.id AND a.enrollment_id=?
        WHERE s.cohort_id=? ORDER BY s.session_date,s.start_time");
    $stmt->execute([$enrollmentId,$cohortId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function portalCertificate(PDO $pdo, int $enrollmentId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM certificates WHERE enrollment_id=? LIMIT 1');
    $stmt->execute([$enrollmentId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
