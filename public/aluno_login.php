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
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Acesso do Aluno — Cursos IA EAD</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    body {
      display: grid;
      place-items: center;
      min-height: 100vh;
      background: radial-gradient(circle at 50% 20%, #1e1b4b 0%, #0b0f19 80%);
      padding: 20px;
    }
    .auth-card {
      width: min(440px, 100%);
      background: rgba(17, 24, 39, 0.95);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: var(--radius-lg);
      padding: 36px 32px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
    }
    .auth-header {
      text-align: center;
      margin-bottom: 28px;
    }
    .auth-brand {
      font-size: 1.5rem;
      font-weight: 800;
      letter-spacing: -0.03em;
      background: linear-gradient(135deg, #a5b4fc, #6366f1);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      display: inline-block;
      margin-bottom: 8px;
    }
    .auth-title {
      font-size: 1.35rem;
      font-weight: 700;
      color: #fff;
      margin-bottom: 6px;
    }
    .auth-subtitle {
      font-size: 0.875rem;
      color: var(--text-secondary);
    }
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-bottom: 20px;
    }
    .form-label {
      font-size: 0.8rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-secondary);
    }
    .form-input {
      width: 100%;
      padding: 14px 16px;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      color: #fff;
      font-size: 1.05rem;
      font-weight: 600;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      transition: border-color 0.2s, box-shadow 0.2s;
      outline: none;
    }
    .form-input:focus {
      border-color: var(--brand-primary);
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }
    .btn-submit {
      width: 100%;
      padding: 14px;
      font-size: 1rem;
      font-weight: 700;
      border-radius: var(--radius-sm);
      background: var(--brand-gradient);
      border: none;
      color: #fff;
      cursor: pointer;
      transition: filter 0.2s, transform 0.1s;
    }
    .btn-submit:hover {
      filter: brightness(1.1);
      transform: translateY(-1px);
    }
    .error-alert {
      background: var(--danger-bg);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #fca5a5;
      padding: 12px 14px;
      border-radius: var(--radius-sm);
      font-size: 0.875rem;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .auth-footer {
      margin-top: 28px;
      text-align: center;
      font-size: 0.85rem;
      color: var(--text-muted);
      border-top: 1px solid var(--border-light);
      padding-top: 20px;
    }
    .auth-footer a {
      color: var(--text-secondary);
      text-decoration: underline;
    }
  </style>
</head>
<body>
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-brand">🎓 Cursos IA MVP</div>
      <h1 class="auth-title">Ambiente Virtual do Aluno</h1>
      <p class="auth-subtitle">Insira seu código individual para acessar suas aulas e certificações.</p>
    </div>

    <?php if($error):?>
      <div class="error-alert">
        <span>⚠️</span>
        <span><?=lh($error)?></span>
      </div>
    <?php endif;?>

    <form method="post">
      <div class="form-group">
        <label class="form-label" for="access_code">Código de Matrícula</label>
        <input id="access_code" class="form-input" name="access_code" autocomplete="one-time-code" maxlength="64" required autofocus placeholder="Ex: MATR-7X89">
      </div>
      <button type="submit" class="btn-submit">Entrar na Plataforma →</button>
    </form>

    <div class="auth-footer">
      <a href="index.php">Ir para Painel Administrativo</a>
    </div>
  </div>
</body>
</html>
