<?php
declare(strict_types=1);

function ensureFinanceModel(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS financial_categories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'expense',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_financial_category(name,type),
        INDEX idx_financial_category_type(type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS financial_transactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED NULL,
        enrollment_id INT UNSIGNED NULL,
        cohort_id INT UNSIGNED NULL,
        organization_id INT UNSIGNED NULL,
        transaction_type VARCHAR(20) NOT NULL DEFAULT 'expense',
        description VARCHAR(220) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        due_date DATE NULL,
        paid_at DATETIME NULL,
        payment_method VARCHAR(60) NULL,
        reference VARCHAR(140) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_financial_type(transaction_type),
        INDEX idx_financial_status(status),
        INDEX idx_financial_due_date(due_date),
        INDEX idx_financial_enrollment(enrollment_id),
        INDEX idx_financial_cohort(cohort_id),
        INDEX idx_financial_organization(organization_id),
        CONSTRAINT fk_financial_category FOREIGN KEY(category_id) REFERENCES financial_categories(id) ON DELETE SET NULL,
        CONSTRAINT fk_financial_enrollment FOREIGN KEY(enrollment_id) REFERENCES enrollments(id) ON DELETE SET NULL,
        CONSTRAINT fk_financial_cohort FOREIGN KEY(cohort_id) REFERENCES cohorts(id) ON DELETE SET NULL,
        CONSTRAINT fk_financial_organization FOREIGN KEY(organization_id) REFERENCES organizations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaults = [
        ['Mensalidades e matrículas', 'income'],
        ['Contratos institucionais', 'income'],
        ['Outras receitas', 'income'],
        ['Instrutores e tutores', 'expense'],
        ['Plataforma e tecnologia', 'expense'],
        ['Marketing e vendas', 'expense'],
        ['Material didático', 'expense'],
        ['Deslocamento e logística', 'expense'],
        ['Impostos e taxas', 'expense'],
        ['Outras despesas', 'expense'],
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO financial_categories(name,type) VALUES(?,?)");
    foreach ($defaults as [$name, $type]) {
        $stmt->execute([$name, $type]);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS enrollment_charges (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT UNSIGNED NOT NULL,
        description VARCHAR(220) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        due_date DATE NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pendente',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_enrollment_charges_enrollment(enrollment_id),
        INDEX idx_enrollment_charges_status(status),
        INDEX idx_enrollment_charges_due(due_date),
        CONSTRAINT fk_enrollment_charges_enrollment FOREIGN KEY(enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS enrollment_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        enrollment_id INT UNSIGNED NOT NULL,
        charge_id INT UNSIGNED NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(60) NULL,
        paid_at DATETIME NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'confirmado',
        reference VARCHAR(180) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_enrollment_payments_enrollment(enrollment_id),
        INDEX idx_enrollment_payments_charge(charge_id),
        INDEX idx_enrollment_payments_status(status),
        CONSTRAINT fk_enrollment_payments_enrollment FOREIGN KEY(enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
        CONSTRAINT fk_enrollment_payments_charge FOREIGN KEY(charge_id) REFERENCES enrollment_charges(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function financeMetrics(PDO $pdo): array
{
    $receivables = $pdo->query("SELECT
        COALESCE(SUM(amount),0) gross_enrollment_revenue,
        COALESCE(SUM(CASE WHEN payment_status='pago' THEN amount ELSE 0 END),0) paid_enrollment_revenue,
        COALESCE(SUM(CASE WHEN payment_status IN ('pendente','atrasado') THEN amount ELSE 0 END),0) open_enrollment_revenue,
        COALESCE(SUM(CASE WHEN payment_status='atrasado' THEN amount ELSE 0 END),0) overdue_enrollment_revenue
        FROM enrollments")->fetch(PDO::FETCH_ASSOC) ?: [];

    $transactions = $pdo->query("SELECT
        COALESCE(SUM(CASE WHEN transaction_type='income' AND status='paid' THEN amount ELSE 0 END),0) other_income_paid,
        COALESCE(SUM(CASE WHEN transaction_type='income' AND status IN ('pending','overdue') THEN amount ELSE 0 END),0) other_income_open,
        COALESCE(SUM(CASE WHEN transaction_type='expense' AND status='paid' THEN amount ELSE 0 END),0) expenses_paid,
        COALESCE(SUM(CASE WHEN transaction_type='expense' AND status IN ('pending','overdue') THEN amount ELSE 0 END),0) expenses_open
        FROM financial_transactions")->fetch(PDO::FETCH_ASSOC) ?: [];

    $received = (float)($receivables['paid_enrollment_revenue'] ?? 0) + (float)($transactions['other_income_paid'] ?? 0);
    $expensesPaid = (float)($transactions['expenses_paid'] ?? 0);

    return array_merge($receivables, $transactions, [
        'received_total' => $received,
        'expenses_total' => $expensesPaid,
        'cash_result' => $received - $expensesPaid,
    ]);
}

function financeRecentTransactions(PDO $pdo, int $limit = 20): array
{
    $limit = max(1, min($limit, 100));
    $sql = "SELECT ft.*,fc.name category_name,s.name student_name,c.title course_title,ch.name cohort_name,o.name organization_name
        FROM financial_transactions ft
        LEFT JOIN financial_categories fc ON fc.id=ft.category_id
        LEFT JOIN enrollments e ON e.id=ft.enrollment_id
        LEFT JOIN students s ON s.id=e.student_id
        LEFT JOIN courses c ON c.id=e.course_id
        LEFT JOIN cohorts ch ON ch.id=ft.cohort_id
        LEFT JOIN organizations o ON o.id=ft.organization_id
        ORDER BY COALESCE(ft.paid_at, CONCAT(ft.due_date,' 00:00:00'), ft.created_at) DESC, ft.id DESC
        LIMIT {$limit}";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function financeEnrollmentReceivables(PDO $pdo, int $limit = 30): array
{
    $limit = max(1, min($limit, 100));
    $sql = "SELECT e.id,e.amount,e.payment_status,e.payment_method,e.paid_at,e.enrolled_at,
        s.name student_name,c.title course_title,ch.name cohort_name,o.name organization_name
        FROM enrollments e
        INNER JOIN students s ON s.id=e.student_id
        INNER JOIN courses c ON c.id=e.course_id
        LEFT JOIN cohorts ch ON ch.id=e.cohort_id
        LEFT JOIN organizations o ON o.id=ch.organization_id
        WHERE e.amount>0
        ORDER BY CASE e.payment_status WHEN 'atrasado' THEN 0 WHEN 'pendente' THEN 1 WHEN 'pago' THEN 2 ELSE 3 END,e.id DESC
        LIMIT {$limit}";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function financeEnrollmentSummary(PDO $pdo, int $enrollmentId): array
{
    $stmt=$pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN status<>'cancelada' THEN amount ELSE 0 END),0) total_charged,
        COALESCE(SUM(CASE WHEN status='pendente' THEN amount ELSE 0 END),0) pending_charged,
        COALESCE(SUM(CASE WHEN status='pendente' AND due_date IS NOT NULL AND due_date<CURDATE() THEN amount ELSE 0 END),0) overdue_charged
        FROM enrollment_charges WHERE enrollment_id=?");
    $stmt->execute([$enrollmentId]);
    $charges=$stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='confirmado' THEN amount ELSE 0 END),0) total_paid FROM enrollment_payments WHERE enrollment_id=?");
    $stmt->execute([$enrollmentId]);
    $payments=$stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $charged=(float)($charges['total_charged']??0);
    $paid=(float)($payments['total_paid']??0);
    return [
        'total_charged'=>$charged,
        'total_paid'=>$paid,
        'balance'=>max(0,$charged-$paid),
        'pending_charged'=>(float)($charges['pending_charged']??0),
        'overdue_charged'=>(float)($charges['overdue_charged']??0),
    ];
}

function financeReconcileCharge(PDO $pdo, int $chargeId): void
{
    $stmt=$pdo->prepare('SELECT id,enrollment_id,amount,status FROM enrollment_charges WHERE id=?');
    $stmt->execute([$chargeId]);
    $charge=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$charge || $charge['status']==='cancelada') return;

    $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM enrollment_payments WHERE charge_id=? AND status='confirmado'");
    $stmt->execute([$chargeId]);
    $paid=(float)$stmt->fetchColumn();
    $status=$paid+0.009 >= (float)$charge['amount'] ? 'paga' : 'pendente';
    $stmt=$pdo->prepare('UPDATE enrollment_charges SET status=? WHERE id=?');
    $stmt->execute([$status,$chargeId]);
    financeSyncEnrollmentStatus($pdo,(int)$charge['enrollment_id']);
}

function financeSyncEnrollmentStatus(PDO $pdo, int $enrollmentId): string
{
    $stmt=$pdo->prepare('SELECT payment_status FROM enrollments WHERE id=?');
    $stmt->execute([$enrollmentId]);
    $current=(string)$stmt->fetchColumn();
    if($current==='') throw new RuntimeException('Matrícula não encontrada.');
    if(in_array($current,['isento','contrato_institucional'],true)) return $current;

    $summary=financeEnrollmentSummary($pdo,$enrollmentId);
    if($summary['total_charged']<=0) $status='pendente';
    elseif($summary['balance']<=0.009) $status='pago';
    elseif($summary['overdue_charged']>0) $status='atrasado';
    else $status='pendente';

    $paidAt=$status==='pago'?date('Y-m-d H:i:s'):null;
    $stmt=$pdo->prepare('UPDATE enrollments SET payment_status=?,paid_at=? WHERE id=?');
    $stmt->execute([$status,$paidAt,$enrollmentId]);
    return $status;
}

function financeEnrollmentCharges(PDO $pdo, int $enrollmentId): array
{
    $stmt=$pdo->prepare("SELECT ec.*,
        COALESCE((SELECT SUM(ep.amount) FROM enrollment_payments ep WHERE ep.charge_id=ec.id AND ep.status='confirmado'),0) paid_amount
        FROM enrollment_charges ec WHERE ec.enrollment_id=? ORDER BY COALESCE(ec.due_date,'9999-12-31'),ec.id");
    $stmt->execute([$enrollmentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function financeEnrollmentPayments(PDO $pdo, int $enrollmentId): array
{
    $stmt=$pdo->prepare("SELECT ep.*,ec.description charge_description FROM enrollment_payments ep LEFT JOIN enrollment_charges ec ON ec.id=ep.charge_id WHERE ep.enrollment_id=? ORDER BY ep.paid_at DESC,ep.id DESC");
    $stmt->execute([$enrollmentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}