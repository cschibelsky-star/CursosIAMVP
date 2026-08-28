<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/finance_model.php';

function efDb(): PDO
{
    return new PDO(
        'mysql:host='.(getenv('DB_HOST')?:'db').';port='.(getenv('DB_PORT')?:'3306').';dbname='.(getenv('DB_DATABASE')?:'cursos_ia_mvp').';charset=utf8mb4',
        getenv('DB_USERNAME')?:'cursos_ia',
        getenv('DB_PASSWORD')?:'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function efh(?string $v): string { return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8'); }
function efmoney(float $v): string { return 'R$ '.number_format($v,2,',','.'); }
function efgo(int $id): never { header('Location: enrollment_finance.php?enrollment='.$id); exit; }

$pdo=efDb();
ensureAcademicModel($pdo);
ensureFinanceModel($pdo);
$enrollmentId=(int)($_GET['enrollment']??$_POST['enrollment_id']??0);
if($enrollmentId<1){http_response_code(400);echo 'Matrícula inválida.';exit;}

$stmt=$pdo->prepare("SELECT e.*,s.name student_name,s.email student_email,c.title course_title,ch.name cohort_name,o.name organization_name
    FROM enrollments e
    INNER JOIN students s ON s.id=e.student_id
    INNER JOIN courses c ON c.id=e.course_id
    LEFT JOIN cohorts ch ON ch.id=e.cohort_id
    LEFT JOIN organizations o ON o.id=ch.organization_id
    WHERE e.id=?");
$stmt->execute([$enrollmentId]);
$enrollment=$stmt->fetch();
if(!$enrollment){http_response_code(404);echo 'Matrícula não encontrada.';exit;}

$flash=$_SESSION['finance_flash']??null; unset($_SESSION['finance_flash']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'');
        if($action==='create_charge'){
            $description=trim((string)($_POST['description']??''));
            $amount=(float)str_replace(',','.',str_replace('.','',trim((string)($_POST['amount']??'0'))));
            $dueDate=trim((string)($_POST['due_date']??''))?:null;
            $notes=trim((string)($_POST['notes']??''))?:null;
            if($description===''||$amount<=0) throw new RuntimeException('Informe descrição e valor da cobrança.');
            $stmt=$pdo->prepare('INSERT INTO enrollment_charges(enrollment_id,description,amount,due_date,status,notes) VALUES(?,?,?,?,?,?)');
            $stmt->execute([$enrollmentId,$description,$amount,$dueDate,'pendente',$notes]);
            financeSyncEnrollmentStatus($pdo,$enrollmentId);
            $_SESSION['finance_flash']=['type'=>'ok','message'=>'Cobrança registrada.'];
        } elseif($action==='register_payment'){
            $chargeId=(int)($_POST['charge_id']??0);
            $amount=(float)str_replace(',','.',str_replace('.','',trim((string)($_POST['amount']??'0'))));
            $method=trim((string)($_POST['payment_method']??''))?:null;
            $reference=trim((string)($_POST['reference']??''))?:null;
            $notes=trim((string)($_POST['notes']??''))?:null;
            if($amount<=0) throw new RuntimeException('Informe um valor de pagamento maior que zero.');
            if($chargeId>0){
                $stmt=$pdo->prepare('SELECT id FROM enrollment_charges WHERE id=? AND enrollment_id=?');
                $stmt->execute([$chargeId,$enrollmentId]);
                if(!$stmt->fetchColumn()) throw new RuntimeException('Cobrança inválida para esta matrícula.');
            }
            $stmt=$pdo->prepare('INSERT INTO enrollment_payments(enrollment_id,charge_id,amount,payment_method,paid_at,status,reference,notes) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->execute([$enrollmentId,$chargeId?:null,$amount,$method,date('Y-m-d H:i:s'),'confirmado',$reference,$notes]);
            if($chargeId>0) financeReconcileCharge($pdo,$chargeId); else financeSyncEnrollmentStatus($pdo,$enrollmentId);
            $_SESSION['finance_flash']=['type'=>'ok','message'=>'Pagamento registrado.'];
        } elseif($action==='cancel_charge'){
            $chargeId=(int)($_POST['charge_id']??0);
            $stmt=$pdo->prepare("UPDATE enrollment_charges SET status='cancelada' WHERE id=? AND enrollment_id=?");
            $stmt->execute([$chargeId,$enrollmentId]);
            financeSyncEnrollmentStatus($pdo,$enrollmentId);
            $_SESSION['finance_flash']=['type'=>'ok','message'=>'Cobrança cancelada.'];
        }
        efgo($enrollmentId);
    }catch(Throwable $e){
        $_SESSION['finance_flash']=['type'=>'error','message'=>$e->getMessage()];
        efgo($enrollmentId);
    }
}

financeSyncEnrollmentStatus($pdo,$enrollmentId);
$stmt=$pdo->prepare('SELECT payment_status,paid_at FROM enrollments WHERE id=?');$stmt->execute([$enrollmentId]);$current=$stmt->fetch();
$enrollment['payment_status']=$current['payment_status'];$enrollment['paid_at']=$current['paid_at'];
$summary=financeEnrollmentSummary($pdo,$enrollmentId);
$charges=financeEnrollmentCharges($pdo,$enrollmentId);
$payments=financeEnrollmentPayments($pdo,$enrollmentId);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Financeiro da Matrícula — Cursos IA</title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="app-shell"><aside class="sidebar"><div class="brand">Cursos IA <small>Financeiro da matrícula</small></div><nav><a class="nav-link" href="dashboard.php"><span class="dot"></span>Dashboard</a><a class="nav-link" href="academic.php"><span class="dot"></span>Controle Acadêmico</a><a class="nav-link" href="financial.php"><span class="dot"></span>Financeiro</a></nav></aside>
<div class="main"><header class="topbar"><strong>Financeiro da Matrícula</strong><span class="env">HML</span></header><main class="content">
<?php if($flash):?><div class="flash <?=efh($flash['type'])?>"><?=efh($flash['message'])?></div><?php endif;?>
<div class="page-title"><div><h1><?=efh($enrollment['student_name'])?></h1><p><?=efh($enrollment['course_title'])?><?= $enrollment['cohort_name']?' · '.efh($enrollment['cohort_name']):'' ?><?= $enrollment['organization_name']?' · '.efh($enrollment['organization_name']):'' ?></p></div><div class="actions"><a class="btn secondary" href="academic.php">Voltar às matrículas</a></div></div>
<div class="grid grid-4" style="margin-bottom:18px"><div class="metric"><span class="value"><?=efmoney((float)$summary['total_charged'])?></span><span class="label">Cobrado</span></div><div class="metric"><span class="value"><?=efmoney((float)$summary['total_paid'])?></span><span class="label">Pago</span></div><div class="metric"><span class="value"><?=efmoney((float)$summary['balance'])?></span><span class="label">Saldo</span></div><div class="metric"><span class="value"><?=efh($enrollment['payment_status'])?></span><span class="label">Status financeiro</span></div></div>
<div class="grid grid-2">
<section class="card"><div class="section-title"><h2>Nova cobrança</h2></div><form method="post"><input type="hidden" name="action" value="create_charge"><input type="hidden" name="enrollment_id" value="<?=$enrollmentId?>"><label>Descrição<br><input class="input" name="description" required></label><div class="grid grid-2"><label>Valor<br><input class="input" name="amount" placeholder="0,00" required></label><label>Vencimento<br><input class="input" type="date" name="due_date"></label></div><label>Observações<br><textarea class="input" name="notes" rows="3"></textarea></label><div class="actions" style="margin-top:12px"><button class="btn" type="submit">Registrar cobrança</button></div></form></section>
<section class="card"><div class="section-title"><h2>Registrar pagamento</h2></div><form method="post"><input type="hidden" name="action" value="register_payment"><input type="hidden" name="enrollment_id" value="<?=$enrollmentId?>"><label>Cobrança<br><select class="input" name="charge_id"><option value="0">Pagamento sem vínculo específico</option><?php foreach($charges as $c):if($c['status']==='cancelada')continue;?><option value="<?=(int)$c['id']?>"><?=efh($c['description'])?> · <?=efmoney((float)$c['amount'])?> · <?=efh($c['status'])?></option><?php endforeach;?></select></label><div class="grid grid-2"><label>Valor pago<br><input class="input" name="amount" placeholder="0,00" required></label><label>Forma de pagamento<br><input class="input" name="payment_method" placeholder="Pix, boleto, cartão..."></label></div><label>Referência<br><input class="input" name="reference" placeholder="NSU, ID Pix, recibo..."></label><label>Observações<br><textarea class="input" name="notes" rows="3"></textarea></label><div class="actions" style="margin-top:12px"><button class="btn" type="submit">Registrar pagamento</button></div></form></section>
</div>
<section class="card" style="margin-top:18px"><div class="section-title"><h2>Cobranças</h2><span class="pill"><?=count($charges)?> registros</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Descrição</th><th>Vencimento</th><th>Valor</th><th>Pago</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($charges as $c):?><tr><td><strong><?=efh($c['description'])?></strong></td><td><?=efh($c['due_date']?:'-')?></td><td><?=efmoney((float)$c['amount'])?></td><td><?=efmoney((float)$c['paid_amount'])?></td><td><span class="pill <?=($c['status']==='paga'?'ok':($c['status']==='pendente'?'warn':''))?>"><?=efh($c['status'])?></span></td><td><?php if($c['status']==='pendente'):?><form method="post"><input type="hidden" name="action" value="cancel_charge"><input type="hidden" name="enrollment_id" value="<?=$enrollmentId?>"><input type="hidden" name="charge_id" value="<?=(int)$c['id']?>"><button class="btn ghost" type="submit">Cancelar</button></form><?php endif;?></td></tr><?php endforeach;if(!$charges):?><tr><td colspan="6" class="empty">Nenhuma cobrança registrada.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card" style="margin-top:18px"><div class="section-title"><h2>Histórico de pagamentos</h2><span class="pill"><?=count($payments)?> registros</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Cobrança</th><th>Forma</th><th>Referência</th><th>Valor</th></tr></thead><tbody><?php foreach($payments as $p):?><tr><td><?=efh($p['paid_at'])?></td><td><?=efh($p['charge_description']?:'Sem vínculo específico')?></td><td><?=efh($p['payment_method']?:'-')?></td><td><?=efh($p['reference']?:'-')?></td><td><?=efmoney((float)$p['amount'])?></td></tr><?php endforeach;if(!$payments):?><tr><td colspan="5" class="empty">Nenhum pagamento registrado.</td></tr><?php endif;?></tbody></table></div></section>
</main></div></div></body></html>