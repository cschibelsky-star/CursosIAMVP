<?php
declare(strict_types=1);

require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/student_portal_model.php';

if((getenv('APP_ENV') ?: '') !== 'homologation'){
    fwrite(STDERR,"portal_hml_smoke: ambiente invalido\n");
    exit(2);
}

function portalSmokeDb(): PDO
{
    return new PDO(
        'mysql:host='.(getenv('DB_HOST')?:'db').';port='.(getenv('DB_PORT')?:'3306').';dbname='.(getenv('DB_DATABASE')?:'cursos_ia_mvp').';charset=utf8mb4',
        getenv('DB_USERNAME')?:'cursos_ia',
        getenv('DB_PASSWORD')?:'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}

$pdo=portalSmokeDb();
ensureAcademicModel($pdo);
ensureStudentPortal($pdo);
$tag='PORTAL_SMOKE_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
$ids=['course'=>0,'module'=>0,'lesson'=>0,'student'=>0,'enrollment'=>0];

try{
    $pdo->prepare("INSERT INTO courses(title,audience,objective,status) VALUES(?,?,?,'rascunho')")->execute([$tag,'Teste automatizado portal','Validar sessão, progresso e conclusão']);
    $ids['course']=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO modules(course_id,position,title,objective) VALUES(?,1,?,?)")->execute([$ids['course'],$tag.' Modulo','Teste']);
    $ids['module']=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO lessons(module_id,position,title,objective,script,review_status) VALUES(?,1,?,?,?,'aprovada')")->execute([$ids['module'],$tag.' Aula','Teste de progresso','Conteudo de homologacao']);
    $ids['lesson']=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO students(name,email,active) VALUES(?,?,1)")->execute([$tag.' Aluno',strtolower($tag).'@teste.local']);
    $ids['student']=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO enrollments(student_id,course_id,enrollment_type,status,payment_status,amount) VALUES(?,?,'individual','matriculado','isento',0)")->execute([$ids['student'],$ids['course']]);
    $ids['enrollment']=(int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO course_academic_rules(course_id,minimum_attendance_percent,minimum_grade,require_all_lessons,require_all_attendance_records,active) VALUES(?,NULL,NULL,1,0,1)")->execute([$ids['course']]);

    $sessionId='portal-smoke-'.bin2hex(random_bytes(8));
    session_id($sessionId);
    session_start();
    $_SESSION['student_enrollment_id']=$ids['enrollment'];
    $_SESSION['student_csrf']='portal-smoke-csrf';
    session_write_close();

    $_SERVER['REQUEST_METHOD']='POST';
    $_POST=[
        'csrf'=>'portal-smoke-csrf',
        'lesson_id'=>(string)$ids['lesson'],
        'position_seconds'=>'60',
        'total_seconds'=>'60',
        'completed'=>'1',
    ];

    ob_start();
    include __DIR__ . '/progress_api.php';
    $raw=trim((string)ob_get_clean());
    $response=json_decode($raw,true);
    if(!is_array($response) || !($response['ok']??false)) throw new RuntimeException('progress_api falhou: '.$raw);
    if(($response['status']??'')!=='concluida') throw new RuntimeException('aula deveria concluir via progress_api');
    if((float)($response['percent_complete']??0)!==100.0) throw new RuntimeException('percentual deveria ser 100');
    if(($response['academic_status']??'')!=='concluido') throw new RuntimeException('matricula deveria concluir via motor academico');
    if(!($response['academic_eligible']??false)) throw new RuntimeException('matricula deveria estar elegivel');

    $stmt=$pdo->prepare('SELECT status,percent_complete FROM lesson_progress WHERE enrollment_id=? AND lesson_id=? LIMIT 1');
    $stmt->execute([$ids['enrollment'],$ids['lesson']]);
    $progress=$stmt->fetch();
    if(!$progress || $progress['status']!=='concluida' || (float)$progress['percent_complete']!==100.0) throw new RuntimeException('lesson_progress persistido inesperado');

    $stmt=$pdo->prepare('SELECT status,completed_at FROM enrollments WHERE id=?');
    $stmt->execute([$ids['enrollment']]);
    $enrollment=$stmt->fetch();
    if(!$enrollment || $enrollment['status']!=='concluido' || empty($enrollment['completed_at'])) throw new RuntimeException('conclusao da matricula nao persistida');

    fwrite(STDOUT,"PORTAL_SMOKE_OK session csrf progress=100 lesson=concluida enrollment=concluido\n");
}catch(Throwable $e){
    fwrite(STDERR,'PORTAL_SMOKE_FAIL '.$e->getMessage()."\n");
    $failed=true;
}finally{
    try{
        if($ids['enrollment']>0){
            $pdo->prepare('DELETE FROM certificates WHERE enrollment_id=?')->execute([$ids['enrollment']]);
            $pdo->prepare('DELETE FROM assessment_results WHERE enrollment_id=?')->execute([$ids['enrollment']]);
            $pdo->prepare('DELETE FROM lesson_progress WHERE enrollment_id=?')->execute([$ids['enrollment']]);
            $pdo->prepare('DELETE FROM attendance WHERE enrollment_id=?')->execute([$ids['enrollment']]);
            $pdo->prepare('DELETE FROM enrollments WHERE id=?')->execute([$ids['enrollment']]);
        }
        if($ids['student']>0) $pdo->prepare('DELETE FROM students WHERE id=?')->execute([$ids['student']]);
        if($ids['course']>0){
            $pdo->prepare('DELETE FROM course_academic_rules WHERE course_id=?')->execute([$ids['course']]);
            $pdo->prepare('DELETE FROM assessments WHERE course_id=?')->execute([$ids['course']]);
            $pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$ids['course']]);
        }
    }catch(Throwable $cleanup){
        fwrite(STDERR,'PORTAL_SMOKE_CLEANUP_WARN '.$cleanup->getMessage()."\n");
    }
}

exit(isset($failed)?1:0);
