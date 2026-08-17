<?php
declare(strict_types=1);

require_once __DIR__ . '/academic_model.php';

function academicEligibilityState(PDO $pdo, int $enrollmentId): array
{
    $stmt=$pdo->prepare('SELECT id,status,course_id,cohort_id FROM enrollments WHERE id=? LIMIT 1');
    $stmt->execute([$enrollmentId]);
    $enrollment=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$enrollment) throw new RuntimeException('Matrícula não encontrada.');

    $courseId=(int)$enrollment['course_id'];
    $cohortId=(int)($enrollment['cohort_id']??0);
    $rules=academicCourseRules($pdo,$courseId);
    $metrics=academicEnrollmentMetrics($pdo,$enrollmentId);
    $pending=[];

    $totalLessons=(int)($metrics['total_lessons']??0);
    $completedLessons=(int)($metrics['completed_lessons']??0);
    if((int)($rules['require_all_lessons']??1)===1 && $totalLessons>0 && $completedLessons<$totalLessons){
        $pending[]='Concluir '.($totalLessons-$completedLessons).' aula(s) online.';
    }

    $totalSessions=0;
    $registeredSessions=0;
    $plannedMinutes=(int)($metrics['planned_minutes']??0);
    $attendedMinutes=(int)($metrics['attended_minutes']??0);
    $attendancePercent=$plannedMinutes>0?min(100,round(($attendedMinutes/$plannedMinutes)*100,2)):0.0;

    if($cohortId>0){
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM attendance_sessions WHERE cohort_id=?');
        $stmt->execute([$cohortId]);
        $totalSessions=(int)$stmt->fetchColumn();

        $stmt=$pdo->prepare('SELECT COUNT(*) FROM attendance a INNER JOIN attendance_sessions s ON s.id=a.session_id WHERE a.enrollment_id=? AND s.cohort_id=?');
        $stmt->execute([$enrollmentId,$cohortId]);
        $registeredSessions=(int)$stmt->fetchColumn();

        if((int)($rules['require_all_attendance_records']??1)===1 && $registeredSessions<$totalSessions){
            $pending[]='Lançar presença em '.($totalSessions-$registeredSessions).' encontro(s) presencial(is).';
        }

        $minimumAttendance=$rules['minimum_attendance_percent']??null;
        if($minimumAttendance!==null && $minimumAttendance!=='' && $plannedMinutes>0){
            $minimumAttendance=(float)$minimumAttendance;
            if($attendancePercent<$minimumAttendance){
                $pending[]='Atingir frequência mínima de '.academicRuleNumber($minimumAttendance).'% (atual: '.academicRuleNumber($attendancePercent).'%).';
            }
        }
    }

    $requiredAssessments=(int)($metrics['required_assessments']??0);
    $requiredGraded=(int)($metrics['required_graded']??0);
    $finalGrade=$metrics['final_grade']===null?null:(float)$metrics['final_grade'];
    $minimumGrade=$rules['minimum_grade']??null;

    if($requiredAssessments>0 && $requiredGraded<$requiredAssessments){
        $pending[]='Lançar resultado de '.($requiredAssessments-$requiredGraded).' avaliação(ões) obrigatória(s).';
    }

    if($minimumGrade!==null && $minimumGrade!==''){
        $minimumGrade=(float)$minimumGrade;
        if($requiredAssessments<1){
            $pending[]='Cadastrar ao menos uma avaliação obrigatória para aplicar a nota mínima.';
        } elseif($requiredGraded===$requiredAssessments && ($finalGrade===null || $finalGrade<$minimumGrade)){
            $current=$finalGrade===null?'sem nota':academicRuleNumber($finalGrade);
            $pending[]='Atingir nota mínima de '.academicRuleNumber($minimumGrade).' (atual: '.$current.').';
        }
    }

    return [
        'eligible'=>empty($pending),
        'pending'=>$pending,
        'status'=>(string)$enrollment['status'],
        'course_id'=>$courseId,
        'cohort_id'=>$cohortId,
        'rules'=>$rules,
        'total_lessons'=>$totalLessons,
        'completed_lessons'=>$completedLessons,
        'total_sessions'=>$totalSessions,
        'registered_sessions'=>$registeredSessions,
        'attendance_percent'=>$attendancePercent,
        'required_assessments'=>$requiredAssessments,
        'required_graded'=>$requiredGraded,
        'final_grade'=>$finalGrade,
    ];
}

function academicRuleNumber(float $value): string
{
    return rtrim(rtrim(number_format($value,2,'.',''),'0'),'.');
}

function academicSyncCompletion(PDO $pdo, int $enrollmentId): array
{
    $state=academicEligibilityState($pdo,$enrollmentId);
    if($state['eligible'] && !in_array($state['status'],['cancelado','trancado'],true)){
        $pdo->prepare("UPDATE enrollments SET status='concluido',completed_at=COALESCE(completed_at,NOW()) WHERE id=?")->execute([$enrollmentId]);
        $state['status']='concluido';
    }
    return $state;
}

function academicIssueCertificate(PDO $pdo, int $enrollmentId, string $type='certificado'): array
{
    if(!in_array($type,['certificado','diploma'],true)) throw new RuntimeException('Tipo de certificado inválido.');

    $state=academicEligibilityState($pdo,$enrollmentId);
    if(!$state['eligible']) throw new RuntimeException('Existem pendências acadêmicas: '.implode(' ',$state['pending']));
    if($state['status']!=='concluido') $state=academicSyncCompletion($pdo,$enrollmentId);
    if($state['status']!=='concluido') throw new RuntimeException('A matrícula ainda não está concluída.');

    $code='CURSO-'.date('Ymd').'-'.str_pad((string)$enrollmentId,6,'0',STR_PAD_LEFT).'-'.strtoupper(substr(hash('sha256',$enrollmentId.'|'.microtime(true)),0,8));
    $hash=hash('sha256',$code.'|'.$enrollmentId);
    $stmt=$pdo->prepare("INSERT INTO certificates(enrollment_id,certificate_type,certificate_code,status,issued_at,validation_hash) VALUES(?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE certificate_type=VALUES(certificate_type),certificate_code=VALUES(certificate_code),status='emitido',issued_at=NOW(),validation_hash=VALUES(validation_hash)");
    $stmt->execute([$enrollmentId,$type,$code,'emitido',$hash]);

    $stmt=$pdo->prepare('SELECT * FROM certificates WHERE enrollment_id=? LIMIT 1');
    $stmt->execute([$enrollmentId]);
    $certificate=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$certificate) throw new RuntimeException('Falha ao carregar certificado emitido.');
    return $certificate;
}
