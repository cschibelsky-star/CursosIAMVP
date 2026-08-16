<?php
declare(strict_types=1);

require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/academic_eligibility.php';

if((getenv('APP_ENV') ?: '') !== 'homologation'){
    fwrite(STDERR,"academic_hml_smoke: ambiente invalido\n");
    exit(2);
}

$pdo = new PDO(
    'mysql:host='.(getenv('DB_HOST')?:'db').';port='.(getenv('DB_PORT')?:'3306').';dbname='.(getenv('DB_DATABASE')?:'cursos_ia_mvp').';charset=utf8mb4',
    getenv('DB_USERNAME')?:'cursos_ia',
    getenv('DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);

$pdo->exec("CREATE TABLE IF NOT EXISTS courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    audience VARCHAR(180) NOT NULL,
    objective TEXT NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'rascunho',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

ensureAcademicModel($pdo);
$tag='HML_SMOKE_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
$courseId=0;

try{
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO courses(title,audience,objective,status) VALUES(?,?,?,'rascunho')")->execute([$tag,'Teste automatizado de homologacao','Validar criterios academicos']);
    $courseId=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO students(name,email,active) VALUES(?,?,1)")->execute([$tag.' Aluno',strtolower($tag).'@teste.local']);
    $studentId=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO enrollments(student_id,course_id,enrollment_type,status,payment_status,amount) VALUES(?,?,'individual','em_andamento','isento',0)")->execute([$studentId,$courseId]);
    $enrollmentId=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO course_academic_rules(course_id,minimum_attendance_percent,minimum_grade,require_all_lessons,require_all_attendance_records,active) VALUES(?,NULL,70,0,0,1)")->execute([$courseId]);
    $pdo->prepare("INSERT INTO assessments(course_id,title,assessment_type,max_score,weight,required,active) VALUES(?,?,'avaliacao_final',100,1,1,1)")->execute([$courseId,$tag.' Avaliacao']);
    $assessmentId=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO assessment_results(assessment_id,enrollment_id,score,status,evaluated_at) VALUES(?,?,60,'avaliado',NOW())")->execute([$assessmentId,$enrollmentId]);
    $low=academicEligibilityState($pdo,$enrollmentId);
    if($low['eligible']) throw new RuntimeException('nota baixa deveria bloquear elegibilidade');
    if((float)($low['final_grade']??0)!==60.0) throw new RuntimeException('nota final baixa inesperada');

    $pdo->prepare("UPDATE assessment_results SET score=80,status='avaliado',evaluated_at=NOW() WHERE assessment_id=? AND enrollment_id=?")->execute([$assessmentId,$enrollmentId]);
    $high=academicSyncCompletion($pdo,$enrollmentId);
    if(!$high['eligible']) throw new RuntimeException('nota suficiente deveria liberar elegibilidade: '.implode(' ',$high['pending']));
    if($high['status']!=='concluido') throw new RuntimeException('matricula deveria concluir automaticamente');
    if((float)($high['final_grade']??0)!==80.0) throw new RuntimeException('nota final alta inesperada');

    $pdo->rollBack();
    fwrite(STDOUT,"ACADEMIC_SMOKE_OK low=60 blocked high=80 concluded\n");
    exit(0);
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'ACADEMIC_SMOKE_FAIL '.$e->getMessage()."\n");
    exit(1);
}
