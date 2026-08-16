<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/student_portal_model.php';

function hvDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function hvh(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }
function hvgo(): never { header('Location: homologacao_visual.php'); exit; }

$pdo=hvDb();
ensureAcademicModel($pdo);
ensureStudentPortal($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS visual_homologation_checks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    screen_key VARCHAR(80) NOT NULL UNIQUE,
    status VARCHAR(20) NOT NULL DEFAULT 'pendente',
    notes TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if(empty($_SESSION['hv_csrf'])) $_SESSION['hv_csrf']=bin2hex(random_bytes(24));

$screens=[
    'dashboard'=>['title'=>'Dashboard','url'=>'dashboard.php','group'=>'Admin','criteria'=>['Hierarquia visual clara','Cards e métricas sem estouro','Atalhos operacionais evidentes','Menu lateral legível','Responsivo em desktop e celular']],
    'criador'=>['title'=>'Criador de Cursos','url'=>'index.php','group'=>'Produção','criteria'=>['Fluxo Curso → Fontes → Programa → Revisão → Fábrica compreensível','Lista de cursos legível','Upload e fonte textual bem separados','Programa do curso destacado','Cidades Inclusivas preserva estrutura oficial']],
    'cidades'=>['title'=>'Cidades Inclusivas','url'=>'cidades_inclusivas.php','group'=>'Produção','criteria'=>['Identidade do curso piloto clara','5 módulos visíveis','Carga total de 20h compreensível','Referências legais apresentadas com cautela','Aula 1 e estrutura pedagógica legíveis']],
    'fabrica'=>['title'=>'Fábrica de Materiais','url'=>'factory.php','group'=>'Produção','criteria'=>['Contador do pacote 0–6 coerente','Cards dos seis materiais padronizados','Prévia do conteúdo legível','Gerar material vs pacote completo sem ambiguidade','Próxima etapa Vídeo/HeyGen claramente posterior']],
    'academico'=>['title'=>'Controle Acadêmico','url'=>'academic.php','group'=>'Admin','criteria'=>['Matrícula como ação central','Aluno e entidade separados','Online/híbrido separado de presencial','Tabela de acompanhamento legível','Indicadores mudam conforme modalidade']],
    'presencial'=>['title'=>'Turmas Presenciais','url'=>'turmas_presenciais.php','group'=>'Admin','criteria'=>['Turma representa oferta integral do curso','Contratante, período, local e carga claros','Encontros aparecem como calendário da turma','Participantes e horas calendarizadas visíveis','Sem linguagem de aula presencial avulsa']],
    'acessos'=>['title'=>'Acessos do Portal','url'=>'portal_access_admin.php','group'=>'Aluno','criteria'=>['Código HML fácil de localizar','Aluno/curso/turma identificados','Último acesso visível','Status acadêmico e financeiro claros','Aviso de autenticação provisória visível']],
    'login'=>['title'=>'Login do Portal do Aluno','url'=>'aluno_login.php','group'=>'Aluno','criteria'=>['Tela limpa e institucional','Campo de código evidente','Mensagem sobre modalidades coerente','Boa leitura em celular','Sem elementos administrativos em destaque']],
    'portal'=>['title'=>'Meu Curso / Portal do Aluno','url'=>'aluno.php','group'=>'Aluno','criteria'=>['Programa aparece em todas as modalidades','Online mostra aulas e progresso','Presencial mostra turma, calendário e frequência','Híbrido combina os dois','Certificação aparece sem promessa indevida']],
    'aula'=>['title'=>'Tela da Aula Online','url'=>'aluno.php','group'=>'Aluno','criteria'=>['Navegação de retorno clara','Título, objetivo e progresso bem hierarquizados','Player/placeholder visualmente adequado','Conteúdo da aula legível','Responsivo no celular']],
];

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)$_SESSION['hv_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('CSRF inválido.');}
    $key=(string)($_POST['screen_key']??'');
    $status=(string)($_POST['status']??'pendente');
    $notes=trim((string)($_POST['notes']??''));
    if(!isset($screens[$key])){http_response_code(400);exit('Tela inválida.');}
    if(!in_array($status,['pendente','ajustes','aprovado'],true))$status='pendente';
    $stmt=$pdo->prepare("INSERT INTO visual_homologation_checks(screen_key,status,notes) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),notes=VALUES(notes),updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$key,$status,$notes?:null]);
    hvgo();
}

$rows=$pdo->query('SELECT screen_key,status,notes,updated_at FROM visual_homologation_checks')->fetchAll();
$checks=[];foreach($rows as $row)$checks[$row['screen_key']]=$row;
$approved=0;$adjustments=0;
foreach(array_keys($screens) as $key){$st=$checks[$key]['status']??'pendente';if($st==='aprovado')$approved++;if($st==='ajustes')$adjustments++;}
$total=count($screens);$progress=$total?round(($approved/$total)*100):0;
$stmt=$pdo->prepare("SELECT id FROM courses WHERE title='Cidades Inclusivas' ORDER BY id DESC LIMIT 1");$stmt->execute();$cidadesId=(int)($stmt->fetchColumn()?:0);
if($cidadesId){$screens['criador']['url']='index.php?course='.$cidadesId;$screens['fabrica']['url']='factory.php?course='.$cidadesId;}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Homologação Visual — Cursos IA</title><link rel="stylesheet" href="assets/app.css"><style>.qa-list{display:grid;gap:8px;margin:12px 0 16px;padding:0;list-style:none}.qa-list li{padding:8px 10px;border-radius:8px;background:#f8fafc;border:1px solid #edf0f4;font-size:13px}.qa-card{display:grid;grid-template-columns:1fr 320px;gap:18px}.qa-status{display:grid;gap:10px;align-content:start}.qa-status textarea{min-height:100px}.qa-actions{display:flex;gap:8px;flex-wrap:wrap}.qa-number{font-size:32px;font-weight:850}.qa-summary{display:grid;grid-template-columns:220px 1fr;gap:18px;align-items:center}@media(max-width:900px){.qa-card,.qa-summary{grid-template-columns:1fr}}</style></head><body>
<div class="app-shell"><aside class="sidebar"><div class="brand">Cursos IA <small>Gestão acadêmica e fábrica de cursos</small></div><nav><div class="nav-title">Operação</div><a class="nav-link" href="dashboard.php"><span class="dot"></span>Dashboard</a><a class="nav-link" href="index.php"><span class="dot"></span>Criador de Cursos</a><a class="nav-link" href="academic.php"><span class="dot"></span>Controle Acadêmico</a><a class="nav-link" href="turmas_presenciais.php"><span class="dot"></span>Turmas Presenciais</a><div class="nav-title">Homologação</div><a class="nav-link active" href="homologacao_visual.php"><span class="dot"></span>Homologação Visual</a><div class="nav-title">Aluno</div><a class="nav-link" href="portal_access_admin.php"><span class="dot"></span>Acessos do Portal</a><a class="nav-link" href="aluno_login.php"><span class="dot"></span>Portal do Aluno</a></nav></aside>
<div class="main"><header class="topbar"><strong>Homologação Visual · Cursos IA</strong><span class="env">HML · v0.9</span></header><main class="content">
<div class="page-title"><div><h1>Homologação Visual</h1><p>Roteiro oficial para revisar o sistema ponta a ponta antes das próximas integrações.</p></div><div class="actions"><a class="btn secondary" href="dashboard.php">Voltar ao Dashboard</a></div></div>
<section class="card qa-summary" style="margin-bottom:18px"><div><span class="muted">Aprovado visualmente</span><div class="qa-number"><?=$approved?>/<?=$total?></div><div class="progress"><span style="width:<?=$progress?>%"></span></div></div><div><div class="info-strip"><span class="item">Progresso <?=$progress?>%</span><span class="item"><?=$adjustments?> com ajustes</span><span class="item"><?=($total-$approved-$adjustments)?> pendentes</span></div><p class="muted" style="margin-bottom:0">A homologação só deve ser encerrada após revisar desktop e celular. Para o fluxo do aluno, copie um código em <strong>Acessos do Portal</strong>, entre no portal e abra uma aula online.</p></div></section>
<div class="notice" style="margin-bottom:18px"><strong>Curso oficial de referência:</strong> Cidades Inclusivas. O programa deve ser visível no online e no presencial; a diferença entre modalidades está na forma de entrega e acompanhamento.</div>
<?php foreach($screens as $key=>$screen):$row=$checks[$key]??['status'=>'pendente','notes'=>'','updated_at'=>''];$status=$row['status'];?>
<section class="card qa-card" style="margin-bottom:16px"><div><div class="section-title"><div><span class="pill"><?=hvh($screen['group'])?></span><h2 style="margin-top:8px"><?=hvh($screen['title'])?></h2></div><span class="pill <?=$status==='aprovado'?'ok':($status==='ajustes'?'warn':'')?>"><?=hvh($status)?></span></div><ul class="qa-list"><?php foreach($screen['criteria'] as $criterion):?><li>✓ <?=hvh($criterion)?></li><?php endforeach;?></ul><a class="btn secondary" href="<?=hvh($screen['url'])?>" target="_blank" rel="noopener">Abrir tela para revisar</a><?php if($key==='aula'):?><p class="form-help">Entre primeiro pelo Portal do Aluno e abra uma aula do programa.</p><?php endif;?></div><form class="qa-status" method="post"><input type="hidden" name="csrf" value="<?=hvh($_SESSION['hv_csrf'])?>"><input type="hidden" name="screen_key" value="<?=hvh($key)?>"><div class="form-group"><label class="form-label">Resultado</label><select name="status"><option value="pendente" <?=$status==='pendente'?'selected':''?>>Pendente</option><option value="ajustes" <?=$status==='ajustes'?'selected':''?>>Precisa de ajustes</option><option value="aprovado" <?=$status==='aprovado'?'selected':''?>>Aprovado</option></select></div><div class="form-group"><label class="form-label">Observações</label><textarea name="notes" placeholder="Ex.: botão muito pequeno no celular, título quebrando, ajustar nomenclatura..."><?=hvh($row['notes']??'')?></textarea></div><button class="btn">Salvar homologação</button><?php if(!empty($row['updated_at'])):?><span class="form-help">Última revisão: <?=hvh($row['updated_at'])?></span><?php endif;?></form></section>
<?php endforeach;?>
</main></div></div></body></html>