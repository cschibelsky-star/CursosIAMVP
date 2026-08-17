<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/student_portal_model.php';

function portalLoginDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function lh(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }

$pdo=portalLoginDb();
ensureAcademicModel($pdo);
ensureStudentPortal($pdo);
$error='';

if(isset($_GET['logout'])){
    $_SESSION=[];
    if(ini_get('session.use_cookies')){
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();
    header('Location: aluno_login.php');
    exit;
}

if(!empty($_SESSION['student_enrollment_id'])){
    header('Location: aluno.php');
    exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $code=(string)($_POST['access_code']??'');
    if(trim($code)===''){
        $error='Informe o código de acesso da matrícula.';
    }else{
        $enrollment=portalAuthenticateByCode($pdo,$code);
        if(!$enrollment){
            $error='Código de acesso inválido ou matrícula indisponível.';
        }else{
            header('Location: aluno.php');
            exit;
        }
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Portal do Aluno — Cursos IA</title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#eef2f7}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center}.box{width:min(440px,calc(100% - 28px));background:#fff;border:1px solid #dfe5ef;border-radius:18px;padding:30px;box-shadow:0 16px 50px rgba(16,24,40,.08)}.brand{font-weight:800;color:#182a52;margin-bottom:28px}.tag{display:inline-block;background:#edf2ff;color:#294273;border-radius:999px;padding:6px 9px;font-size:12px;margin-bottom:12px}h1{margin:0 0 8px}p{color:#667085;line-height:1.55}label{display:block;font-size:13px;font-weight:700;margin:18px 0 6px}input{width:100%;padding:13px;border:1px solid #cfd6e4;border-radius:9px;font:inherit;text-transform:uppercase;letter-spacing:.08em}.btn{width:100%;border:0;background:#182a52;color:#fff;border-radius:9px;padding:13px;font-weight:800;margin-top:14px;cursor:pointer}.error{background:#fff0f0;color:#9b1c1c;padding:11px 12px;border-radius:9px;margin:12px 0}.muted{font-size:12px}.admin{margin-top:24px;text-align:center}.admin a{color:#667085;text-decoration:none}</style></head><body>
<div class="box"><div class="brand">Cursos IA</div><span class="tag">Portal do Aluno · HML v0.7</span><h1>Acesse seu curso</h1><p>Use o código individual vinculado à sua matrícula. Seu progresso, aulas e certificação ficam reunidos neste portal.</p><?php if($error):?><div class="error"><?=lh($error)?></div><?php endif;?><form method="post"><label>Código de acesso</label><input name="access_code" autocomplete="one-time-code" maxlength="64" required autofocus placeholder="EX.: A1B2C3D4E5F6"><button class="btn">Entrar no curso</button></form><p class="muted">Este acesso por código é o mecanismo de homologação. A produção receberá autenticação forte e recuperação segura de acesso.</p><div class="admin"><a href="academic.php">Área administrativa</a></div></div>
</body></html>