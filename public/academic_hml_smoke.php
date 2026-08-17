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

try{
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO courses(title,audience,objective,status) VALUES(?,?,?,'rascunho')")->execute([$tag,'Teste automatizado de homologacao','Validar criterios academicos e certificacao']);
    $courseId=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO students(name,email,active) VALUES(?,?,1)")->execute([$tag.' Aluno',strtolower($tag).'@teste.local']);
    $studentId=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO enrollments(student_id,course_id,enrollment_type,status,payment_status,amount) VALUES(?,?,'individual','em_andamento','isento',0)")->execute([$studentId,$courseId]);
    $enrollmentId=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO course_academic_rules(course_id,minimum_attendance_percent,minimum_grade,require_all_lessons,require_all_attendance_records,active) VALUES(?,NULL,NULL,0,0,1)")->execute([$courseId]);

    $pdo->prepare("INSERT INTO assessments(course_id,title,assessment_type,max_score,weight,required,active) VALUES(?,?,'atividade',100,1,0,1)")->execute([$courseId,$tag.' Opcional']);
    $optionalOnly=academicEligibilityState($pdo,$enrollmentId);
    if(!$optionalOnly['eligible']) throw new RuntimeException('avaliacao opcional sem resultado nao deveria bloquear: '.implode(' ',$optionalOnly['pending']));
    if((int)$optionalOnly['required_assessments']!==0 || (int)$optionalOnly['required_graded']!==0) throw new RuntimeException('avaliacao opcional foi contada como obrigatoria');

    $pdo->prepare("UPDATE course_academic_rules SET minimum_grade=70 WHERE course_id=?")->execute([$courseId]);
    $minimumWithoutRequired=academicEligibilityState($pdo,$enrollmentId);
    if($minimumWithoutRequired['eligible']) throw new RuntimeException('nota minima sem avaliacao obrigatoria deveria bloquear');
    $minimumPending=implode(' ',$minimumWithoutRequired['pending']);
    if(stripos($minimumPending,'avaliação obrigatória')===false && stripos($minimumPending,'avaliacao obrigatoria')===false){
        throw new RuntimeException('pendencia deveria orientar cadastro de avaliacao obrigatoria');
    }

    $pdo->prepare("UPDATE course_academic_rules SET minimum_grade=NULL WHERE course_id=?")->execute([$courseId]);
    $pdo->prepare("INSERT INTO assessments(course_id,title,assessment_type,max_score,weight,required,active) VALUES(?,?,'avaliacao_final',100,1,1,1)")->execute([$courseId,$tag.' Obrigatoria']);
    $assessmentId=(int)$pdo->lastInsertId();

    $ungraded=academicEligibilityState($pdo,$enrollmentId);
    if($ungraded['eligible']) throw new RuntimeException('avaliacao obrigatoria sem resultado deveria bloquear mesmo sem nota minima');
    if((int)$ungraded['required_assessments']!==1 || (int)$ungraded['required_graded']!==0) throw new RuntimeException('contagem de avaliacao obrigatoria sem resultado inesperada');

    $pdo->prepare("UPDATE course_academic_rules SET minimum_grade=70 WHERE course_id=?")->execute([$courseId]);
    $pdo->prepare("INSERT INTO assessment_results(assessment_id,enrollment_id,score,status,evaluated_at) VALUES(?,?,60,'avaliado',NOW())")->execute([$assessmentId,$enrollmentId]);
    $low=academicEligibilityState($pdo,$enrollmentId);
    if($low['eligible']) throw new RuntimeException('nota baixa deveria bloquear elegibilidade');
    if((float)($low['final_grade']??0)!==60.0) throw new RuntimeException('nota final baixa inesperada');

    $blocked=false;
    try{
        academicIssueCertificate($pdo,$enrollmentId,'certificado');
    }catch(RuntimeException $e){
        $blocked=true;
    }
    if(!$blocked) throw new RuntimeException('certificado deveria ser bloqueado com pendencia academica');

    $pdo->prepare("UPDATE assessment_results SET score=80,status='avaliado',evaluated_at=NOW() WHERE assessment_id=? AND enrollment_id=?")->execute([$assessmentId,$enrollmentId]);
    $high=academicSyncCompletion($pdo,$enrollmentId);
    if(!$high['eligible']) throw new RuntimeException('nota suficiente deveria liberar elegibilidade: '.implode(' ',$high['pending']));
    if($high['status']!=='concluido') throw new RuntimeException('matricula deveria concluir automaticamente');
    if((float)($high['final_grade']??0)!==80.0) throw new RuntimeException('nota final alta inesperada');

    $certificate=academicIssueCertificate($pdo,$enrollmentId,'certificado');
    if(($certificate['status']??'')!=='emitido') throw new RuntimeException('certificado deveria ser emitido apos conclusao');
    if(trim((string)($certificate['certificate_code']??''))==='') throw new RuntimeException('codigo do certificado nao foi gerado');
    if(trim((string)($certificate['validation_hash']??''))==='') throw new RuntimeException('hash de validacao do certificado nao foi gerado');

    $pdo->rollBack();
    fwrite(STDOUT,"ACADEMIC_SMOKE_OK optional_ungraded_allowed min_without_required_blocked required_ungraded_blocked low=60 blocked certificate_blocked high=80 concluded certificate_issued\n");
    exit(0);
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'ACADEMIC_SMOKE_FAIL '.$e->getMessage()."\n");
    exit(1);
}
