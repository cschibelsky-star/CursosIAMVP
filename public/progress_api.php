<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/academic_eligibility.php';
require_once __DIR__ . '/student_portal_model.php';

function progressDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}

try {
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('method_not_allowed');
    $enrollmentId=(int)($_SESSION['student_enrollment_id']??0);
    $sessionCsrf=(string)($_SESSION['student_csrf']??'');
    $csrf=(string)($_POST['csrf']??'');
    if($enrollmentId<1 || $sessionCsrf==='' || !hash_equals($sessionCsrf,$csrf)) throw new RuntimeException('unauthorized');

    $lessonId=(int)($_POST['lesson_id']??0);
    $position=max(0,(int)($_POST['position_seconds']??0));
    $total=max(0,(int)($_POST['total_seconds']??0));
    $completedRequested=((string)($_POST['completed']??'0'))==='1';
    if($lessonId<1) throw new RuntimeException('invalid_lesson');

    $pdo=progressDb();ensureAcademicModel($pdo);ensureStudentPortal($pdo);
    $stmt=$pdo->prepare('SELECT l.id FROM enrollments e INNER JOIN modules m ON m.course_id=e.course_id INNER JOIN lessons l ON l.module_id=m.id WHERE e.id=? AND l.id=? LIMIT 1');
    $stmt->execute([$enrollmentId,$lessonId]);
    if(!$stmt->fetchColumn()) throw new RuntimeException('lesson_not_in_enrollment');

    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT * FROM lesson_progress WHERE enrollment_id=? AND lesson_id=? FOR UPDATE');
    $stmt->execute([$enrollmentId,$lessonId]);
    $current=$stmt->fetch();

    $watched=(int)($current['watched_seconds']??0);
    $lastPosition=(int)($current['last_position_seconds']??0);
    $knownTotal=max($total,(int)($current['total_seconds']??0));

    $delta=$position-$lastPosition;
    if($delta>0 && $delta<=75){
        $watched += $delta;
    }
    if($knownTotal>0) $watched=min($watched,$knownTotal);

    $percent=$knownTotal>0?round(min(100,($watched/$knownTotal)*100),2):0.0;
    $isCompleted=($completedRequested && $percent>=90) || $percent>=100;
    $status=$isCompleted?'concluida':($watched>0||$position>0?'em_andamento':'nao_iniciada');
    $completedAt=$isCompleted?date('Y-m-d H:i:s'):null;

    if($current){
        $stmt=$pdo->prepare('UPDATE lesson_progress SET status=?,watched_seconds=?,total_seconds=?,percent_complete=?,last_position_seconds=?,started_at=COALESCE(started_at,NOW()),completed_at=?,last_seen_at=NOW() WHERE id=?');
        $stmt->execute([$status,$watched,$knownTotal,$percent,$position,$completedAt,$current['id']]);
    }else{
        $stmt=$pdo->prepare('INSERT INTO lesson_progress(enrollment_id,lesson_id,status,watched_seconds,total_seconds,percent_complete,last_position_seconds,started_at,completed_at,last_seen_at) VALUES(?,?,?,?,?,?,?,NOW(),?,NOW())');
        $stmt->execute([$enrollmentId,$lessonId,$status,$watched,$knownTotal,$percent,$position,$completedAt]);
    }

    $pdo->prepare("UPDATE enrollments SET status=CASE WHEN status='matriculado' THEN 'em_andamento' ELSE status END,started_at=COALESCE(started_at,NOW()),last_seen_at=NOW() WHERE id=?")->execute([$enrollmentId]);
    $academicState=academicSyncCompletion($pdo,$enrollmentId);
    $pdo->commit();

    echo json_encode([
        'ok'=>true,
        'status'=>$status,
        'watched_seconds'=>$watched,
        'total_seconds'=>$knownTotal,
        'percent_complete'=>$percent,
        'last_position_seconds'=>$position,
        'academic_status'=>$academicState['status'],
        'academic_eligible'=>$academicState['eligible'],
        'academic_pending'=>$academicState['pending'],
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e){
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(in_array($e->getMessage(),['unauthorized'],true)?401:400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
