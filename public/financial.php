<?php
declare(strict_types=1);

require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/finance_model.php';

function financeDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}

function fh(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function moneyBr(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

$pdo = financeDb();
ensureAcademicModel($pdo);
ensureFinanceModel($pdo);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $type = ($_POST['transaction_type'] ?? 'expense') === 'income' ? 'income' : 'expense';
        $description = trim((string)($_POST['description'] ?? ''));
        $amountRaw = str_replace(['.', ','], ['', '.'], trim((string)($_POST['amount'] ?? '0')));
        $amount = (float)$amountRaw;
        $status = (string)($_POST['status'] ?? 'pending');
        $allowedStatus = ['pending','paid','overdue','cancelled'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'pending';
        }
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $dueDate = trim((string)($_POST['due_date'] ?? '')) ?: null;
        $paymentMethod = trim((string)($_POST['payment_method'] ?? '')) ?: null;
        $reference = trim((string)($_POST['reference'] ?? '')) ?: null;
        $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
        $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;

        if ($description === '' || $amount <= 0) {
            throw new RuntimeException('Informe descrição e valor maior que zero.');
        }

        $stmt = $pdo->prepare("INSERT INTO financial_transactions
            (category_id,transaction_type,description,amount,status,due_date,paid_at,payment_method,reference,notes)
            VALUES(?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $categoryId > 0 ? $categoryId : null,
            $type,
            $description,
            $amount,
            $status,
            $dueDate,
            $paidAt,
            $paymentMethod,
            $reference,
            $notes,
        ]);
        $message = 'Lançamento financeiro registrado.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$metrics = financeMetrics($pdo);
$transactions = financeRecentTransactions($pdo, 30);
$receivables = financeEnrollmentReceivables($pdo, 30);
$categories = $pdo->query("SELECT id,name,type FROM financial_categories WHERE active=1 ORDER BY type,name")->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Financeiro — Cursos IA</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
<aside class="sidebar">
    <div class="brand">Cursos IA <small>Gestão acadêmica e fábrica de cursos</small></div>
    <nav>
        <div class="nav-title">Operação</div>
        <a class="nav-link" href="dashboard.php"><span class="dot"></span>Dashboard</a>
        <a class="nav-link" href="index.php"><span class="dot"></span>Criador de Cursos</a>
        <a class="nav-link" href="academic.php"><span class="dot"></span>Controle Acadêmico</a>
        <a class="nav-link" href="turmas_presenciais.php"><span class="dot"></span>Turmas Presenciais</a>
        <a class="nav-link active" href="financial.php"><span class="dot"></span>Financeiro</a>
        <div class="nav-title">Homologação</div>
        <a class="nav-link" href="homologacao_visual.php"><span class="dot"></span>Homologação Visual</a>
        <div class="nav-title">Aluno</div>
        <a class="nav-link" href="portal_access_admin.php"><span class="dot"></span>Acessos do Portal</a>
        <a class="nav-link" href="aluno_login.php"><span class="dot"></span>Portal do Aluno</a>
        <div class="nav-title">Produção</div>
        <a class="nav-link" href="cidades_inclusivas.php"><span class="dot"></span>Cidades Inclusivas</a>
        <a class="nav-link" href="diagnostic.php"><span class="dot"></span>Diagnóstico</a>
    </nav>
</aside>
<div class="main">
<header class="topbar"><strong>Centro Acadêmico · Cursos IA</strong><span class="env">HML · financeiro</span></header>
<main class="content">
<div class="page-title">
    <div><h1>Financeiro</h1><p>Receitas, despesas, matrículas e visão de caixa em um único painel.</p></div>
    <div class="actions"><a class="btn secondary" href="dashboard.php">Voltar ao dashboard</a></div>
</div>

<?php if ($message): ?><div class="card" style="margin-bottom:16px"><span class="pill ok"><?=fh($message)?></span></div><?php endif; ?>
<?php if ($error): ?><div class="card" style="margin-bottom:16px"><span class="pill warn"><?=fh($error)?></span></div><?php endif; ?>

<div class="grid grid-6" style="margin-bottom:18px">
    <div class="metric"><span class="value"><?=moneyBr((float)$metrics['received_total'])?></span><span class="label">Recebido</span><span class="hint">Matrículas + outras receitas</span></div>
    <div class="metric"><span class="value"><?=moneyBr((float)$metrics['open_enrollment_revenue'] + (float)$metrics['other_income_open'])?></span><span class="label">A receber</span><span class="hint">Valores em aberto</span></div>
    <div class="metric"><span class="value"><?=moneyBr((float)$metrics['overdue_enrollment_revenue'])?></span><span class="label">Em atraso</span><span class="hint">Matrículas atrasadas</span></div>
    <div class="metric"><span class="value"><?=moneyBr((float)$metrics['expenses_total'])?></span><span class="label">Despesas pagas</span><span class="hint">Saídas realizadas</span></div>
    <div class="metric"><span class="value"><?=moneyBr((float)$metrics['expenses_open'])?></span><span class="label">A pagar</span><span class="hint">Despesas pendentes</span></div>
    <div class="metric"><span class="value"><?=moneyBr((float)$metrics['cash_result'])?></span><span class="label">Resultado de caixa</span><span class="hint">Recebido menos despesas</span></div>
</div>

<div class="grid grid-2">
<section class="card">
<div class="section-title"><h2>Novo lançamento</h2><span class="pill">Manual</span></div>
<form method="post">
    <div class="grid grid-2">
        <label>Tipo<br><select name="transaction_type" class="input"><option value="income">Receita</option><option value="expense" selected>Despesa</option></select></label>
        <label>Categoria<br><select name="category_id" class="input"><option value="0">Sem categoria</option><?php foreach($categories as $cat):?><option value="<?=(int)$cat['id']?>"><?=fh($cat['name'])?> · <?=fh($cat['type'])?></option><?php endforeach;?></select></label>
    </div>
    <label>Descrição<br><input class="input" name="description" required></label>
    <div class="grid grid-2">
        <label>Valor<br><input class="input" name="amount" placeholder="0,00" required></label>
        <label>Status<br><select class="input" name="status"><option value="pending">Pendente</option><option value="paid">Pago</option><option value="overdue">Atrasado</option><option value="cancelled">Cancelado</option></select></label>
    </div>
    <div class="grid grid-2">
        <label>Vencimento<br><input class="input" type="date" name="due_date"></label>
        <label>Forma de pagamento<br><input class="input" name="payment_method" placeholder="Pix, boleto, cartão..."></label>
    </div>
    <label>Referência<br><input class="input" name="reference" placeholder="Contrato, NF, pedido..."></label>
    <label>Observações<br><textarea class="input" name="notes" rows="3"></textarea></label>
    <div class="actions" style="margin-top:12px"><button class="btn" type="submit">Registrar lançamento</button></div>
</form>
</section>

<section class="card">
<div class="section-title"><h2>Contas de matrículas</h2><span class="pill"><?=count($receivables)?> registros</span></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Aluno</th><th>Curso</th><th>Valor</th><th>Status</th></tr></thead><tbody>
<?php foreach($receivables as $r):?><tr>
<td><strong><?=fh($r['student_name'])?></strong><br><span class="muted"><?=fh($r['organization_name'] ?: 'Individual')?></span></td>
<td><?=fh($r['course_title'])?><br><span class="muted"><?=fh($r['cohort_name'] ?: 'Sem turma')?></span></td>
<td><?=moneyBr((float)$r['amount'])?></td>
<td><span class="pill <?=($r['payment_status']==='pago'?'ok':($r['payment_status']==='atrasado'?'warn':''))?>"><?=fh($r['payment_status'])?></span></td>
</tr><?php endforeach; if(!$receivables):?><tr><td colspan="4" class="empty">Nenhuma matrícula com valor financeiro.</td></tr><?php endif;?>
</tbody></table></div>
</section>
</div>

<section class="card" style="margin-top:18px">
<div class="section-title"><h2>Lançamentos recentes</h2><span class="pill"><?=count($transactions)?> registros</span></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Descrição</th><th>Categoria</th><th>Tipo</th><th>Vencimento</th><th>Valor</th><th>Status</th></tr></thead><tbody>
<?php foreach($transactions as $t):?><tr>
<td><strong><?=fh($t['description'])?></strong><?php if($t['reference']):?><br><span class="muted"><?=fh($t['reference'])?></span><?php endif;?></td>
<td><?=fh($t['category_name'] ?: 'Sem categoria')?></td>
<td><span class="pill"><?=fh($t['transaction_type'])?></span></td>
<td><?=fh($t['due_date'] ?: '-')?></td>
<td><?=moneyBr((float)$t['amount'])?></td>
<td><span class="pill <?=($t['status']==='paid'?'ok':($t['status']==='overdue'?'warn':''))?>"><?=fh($t['status'])?></span></td>
</tr><?php endforeach;if(!$transactions):?><tr><td colspan="6" class="empty">Nenhum lançamento manual registrado.</td></tr><?php endif;?>
</tbody></table></div>
</section>
</main>
</div>
</div>
</body>
</html>