<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';

function arDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}
function arh(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function argo(int $courseId): never { header('Location: academic_rules.php?course='.$courseId); exit; }

$pdo=arDb();
ensureAcademicModel($pdo);
$courses=$pdo->query('SELECT id,title,status FROM courses ORDER BY title')->fetchAll();
$courseId=(int)($_GET['course'] ?? $_POST['course_id'] ?? ($courses[0]['id'] ?? 0));
$flash=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if($courseId<1) throw new RuntimeException('Selecione um curso.');
        $rawAttendance=trim((string)($_POST['minimum_attendance_percent']??''));
        $minimumAttendance=$rawAttendance===''?null:(float)$rawAttendance;
        if($minimumAttendance!==null && ($minimumAttendance<0 || $minimumAttendance>100)) throw new RuntimeException('A frequência mínima deve ficar entre 0 e 100%.');

        $rawGrade=trim((string)($_POST['minimum_grade']??''));
        $minimumGrade=$rawGrade===''?null:(float)$rawGrade;
        if($minimumGrade!==null && ($minimumGrade<0 || $minimumGrade>100)) throw new RuntimeException('A nota mínima deve ficar entre 0 e 100.');

        $requireLessons=isset($_POST['require_all_lessons'])?1:0;
        $requireAttendance=isset($_POST['require_all_attendance_records'])?1:0;
        $stmt=$pdo->prepare("INSERT INTO course_academic_rules(course_id,minimum_attendance_percent,minimum_grade,require_all_lessons,require_all_attendance_records,active)
            VALUES(?,?,?,?,?,1)
            ON DUPLICATE KEY UPDATE minimum_attendance_percent=VALUES(minimum_attendance_percent),minimum_grade=VALUES(minimum_grade),require_all_lessons=VALUES(require_all_lessons),require_all_attendance_records=VALUES(require_all_attendance_records),active=1");
        $stmt->execute([$courseId,$minimumAttendance,$minimumGrade,$requireLessons,$requireAttendance]);
        $_SESSION['academic_rules_flash']='Critérios acadêmicos salvos.';
        argo($courseId);
    }catch(Throwable $e){$flash=['message'=>$e->getMessage(),'type'=>'error'];}
}

if(!$flash && isset($_SESSION['academic_rules_flash'])){
    $flash=['message'=>(string)$_SESSION['academic_rules_flash'],'type'=>'ok'];
    unset($_SESSION['academic_rules_flash']);
}
$rules=$courseId>0?academicCourseRules($pdo,$courseId):academicCourseRules($pdo,0);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cursos IA — Critérios Acadêmicos</title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;gap:12px}.wrap{max-width:920px;margin:auto;padding:24px}.card{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:20px;margin-bottom:16px}.btn{display:inline-block;background:#182a52;color:#fff;border:0;border-radius:9px;padding:10px 14px;font-weight:700;text-decoration:none;cursor:pointer}.secondary{background:#eef2f8;color:#182a52}select,input[type=number]{width:100%;padding:10px;border:1px solid #cfd6e4;border-radius:8px;margin:6px 0 14px}.check{display:flex;gap:10px;align-items:flex-start;margin:12px 0}.muted{color:#667085;font-size:13px}.flash{padding:12px 14px;border-radius:9px;background:#eaf7ee;color:#185f34;margin-bottom:16px}.flash.error{background:#fff0f0;color:#9b1c1c}.rule{padding:14px;border:1px solid #e2e7f0;border-radius:10px;margin:10px 0}</style></head><body>
<div class="top"><strong>Cursos IA MVP · Critérios Acadêmicos</strong><div><a class="btn secondary" href="assessments.php<?= $courseId?'?course='.$courseId:'' ?>">Avaliações</a> <a class="btn secondary" href="academic.php">Controle Acadêmico</a></div></div>
<div class="wrap">
<?php if($flash):?><div class="flash <?=arh($flash['type'])?>"><?=arh($flash['message'])?></div><?php endif;?>
<div class="card"><h1>Regras de conclusão por curso</h1><p class="muted">Configure apenas regras oficialmente definidas. Campo vazio significa que o critério não será exigido.</p><form method="get"><label>Curso</label><select name="course" onchange="this.form.submit()"><?php foreach($courses as $course):?><option value="<?=$course['id']?>" <?=$courseId===(int)$course['id']?'selected':''?>><?=arh($course['title'])?></option><?php endforeach;?></select></form></div>
<?php if($courseId>0):?><div class="card"><form method="post"><input type="hidden" name="course_id" value="<?=$courseId?>">
<label><strong>Frequência mínima presencial (%)</strong></label><input type="number" name="minimum_attendance_percent" min="0" max="100" step="0.01" value="<?=($rules['minimum_attendance_percent']===null||$rules['minimum_attendance_percent']==='')?'':arh((string)$rules['minimum_attendance_percent'])?>" placeholder="Sem exigência">
<p class="muted">A frequência só é aplicada quando há carga presencial planejada.</p>
<label><strong>Nota mínima final (0 a 100)</strong></label><input type="number" name="minimum_grade" min="0" max="100" step="0.01" value="<?=($rules['minimum_grade']===null||$rules['minimum_grade']==='')?'':arh((string)$rules['minimum_grade'])?>" placeholder="Sem exigência">
<p class="muted">Quando definida, exige avaliação obrigatória com resultado lançado e média final igual ou superior ao valor configurado.</p>
<label class="check"><input type="checkbox" name="require_all_lessons" value="1" <?=((int)($rules['require_all_lessons']??1)===1)?'checked':''?>><span><strong>Exigir conclusão de todas as aulas online</strong><br><span class="muted">Mantém o aluno pendente enquanto houver aula online não concluída.</span></span></label>
<label class="check"><input type="checkbox" name="require_all_attendance_records" value="1" <?=((int)($rules['require_all_attendance_records']??1)===1)?'checked':''?>><span><strong>Exigir lançamento de todos os encontros presenciais</strong><br><span class="muted">Todos os encontros precisam ter situação registrada.</span></span></label>
<div class="rule"><strong>Avaliações e resultados</strong><p class="muted">A nota mínima participa diretamente da elegibilidade de conclusão e certificação pelo motor acadêmico central.</p><a class="btn secondary" href="assessments.php?course=<?=$courseId?>">Gerenciar avaliações</a></div>
<button class="btn" type="submit">Salvar critérios</button></form></div><?php else:?><div class="card"><p>Nenhum curso disponível.</p></div><?php endif;?>
</div></body></html>
