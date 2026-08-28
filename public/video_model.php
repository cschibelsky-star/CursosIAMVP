<?php
declare(strict_types=1);

function ensureVideoModel(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_video_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        provider VARCHAR(40) NOT NULL DEFAULT 'heygen',
        account_mode VARCHAR(40) NOT NULL DEFAULT 'vitrine_managed',
        quota_minutes DECIMAL(8,2) NOT NULL DEFAULT 0,
        used_minutes DECIMAL(8,2) NOT NULL DEFAULT 0,
        visual_approved TINYINT(1) NOT NULL DEFAULT 0,
        visual_approved_at DATETIME NULL,
        real_submission_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_course_video_settings(course_id),
        CONSTRAINT fk_course_video_settings_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lesson_video_jobs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        lesson_id INT UNSIGNED NOT NULL,
        provider VARCHAR(40) NOT NULL DEFAULT 'heygen',
        status VARCHAR(40) NOT NULL DEFAULT 'aguardando_homologacao',
        estimated_minutes DECIMAL(8,2) NOT NULL DEFAULT 0,
        external_job_id VARCHAR(180) NULL,
        video_url VARCHAR(500) NULL,
        error_message TEXT NULL,
        queued_at DATETIME NULL,
        submitted_at DATETIME NULL,
        completed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_lesson_video_job(lesson_id),
        INDEX idx_video_jobs_course(course_id),
        INDEX idx_video_jobs_status(status),
        CONSTRAINT fk_video_jobs_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
        CONSTRAINT fk_video_jobs_lesson FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function videoEstimateMinutes(string $script): float
{
    $words = preg_split('/\s+/u', trim($script), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) return 0.0;
    return round(max(1, count($words) / 145), 2);
}

function videoCourseState(PDO $pdo, int $courseId): array
{
    $stmt=$pdo->prepare("SELECT COUNT(*) total,
        SUM(CASE WHEN l.review_status='aprovada' THEN 1 ELSE 0 END) approved
        FROM modules m INNER JOIN lessons l ON l.module_id=m.id WHERE m.course_id=?");
    $stmt->execute([$courseId]);
    $ped=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
    $total=(int)($ped['total']??0);$approved=(int)($ped['approved']??0);

    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT asset_type) FROM course_assets WHERE course_id=? AND status='gerado' AND asset_type IN ('slides','apostila','exercicios','avaliacao','pagina_venda','certificado')");
    $stmt->execute([$courseId]);
    $assets=(int)$stmt->fetchColumn();

    $stmt=$pdo->prepare('SELECT * FROM course_video_settings WHERE course_id=?');
    $stmt->execute([$courseId]);
    $settings=$stmt->fetch(PDO::FETCH_ASSOC)?:[];

    return [
        'total_lessons'=>$total,
        'approved_lessons'=>$approved,
        'pedagogical_ok'=>$total>0&&$approved===$total,
        'assets_count'=>$assets,
        'package_ok'=>$assets===6,
        'visual_approved'=>(int)($settings['visual_approved']??0)===1,
        'real_submission_enabled'=>(int)($settings['real_submission_enabled']??0)===1,
        'quota_minutes'=>(float)($settings['quota_minutes']??0),
        'used_minutes'=>(float)($settings['used_minutes']??0),
        'provider'=>(string)($settings['provider']??'heygen'),
        'account_mode'=>(string)($settings['account_mode']??'vitrine_managed'),
    ];
}

function videoSyncQueue(PDO $pdo, int $courseId): int
{
    $stmt=$pdo->prepare("SELECT l.id,l.script FROM modules m INNER JOIN lessons l ON l.module_id=m.id WHERE m.course_id=? AND l.review_status='aprovada' ORDER BY m.position,l.position");
    $stmt->execute([$courseId]);
    $count=0;
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $lesson){
        $minutes=videoEstimateMinutes((string)$lesson['script']);
        $up=$pdo->prepare("INSERT INTO lesson_video_jobs(course_id,lesson_id,provider,status,estimated_minutes)
            VALUES(?,?, 'heygen','pronto_para_fila',?)
            ON DUPLICATE KEY UPDATE estimated_minutes=VALUES(estimated_minutes),
                status=CASE WHEN status IN ('concluido','enviado','processando') THEN status ELSE 'pronto_para_fila' END,
                updated_at=CURRENT_TIMESTAMP");
        $up->execute([$courseId,(int)$lesson['id'],$minutes]);
        $count++;
    }
    return $count;
}
