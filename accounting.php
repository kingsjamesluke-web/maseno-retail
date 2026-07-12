<?php
/**
 * Maseno Retail ERP - Accounting & Financial Reports
 *
 * Manages:
 *   - General ledger journal entries
 *   - Expense tracking
 *   - Daily P&L (margin calculations)
 *   - Financial reports (trial balance, income statement)
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/auth.php';

// ──────────────────────────────────────────────────────────────
// 1. EXPENSE MANAGEMENT
// ──────────────────────────────────────────────────────────────

/**
 * Record a new expense with automatic journal entry.
 */
function record_expense(array $data, int $userId): array
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO expenses
                (expense_category, description, amount, payment_method, receipt_ref, gl_account_id, recorded_by, entry_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([
            $data['expense_category'],
            $data['description'],
            (float) $data['amount'],
            $data['payment_method'] ?? 'cash',
            $data['receipt_ref'] ?? '',
            !empty($data['gl_account_id']) ? (int) $data['gl_account_id'] : null,
            $userId,
            $data['entry_date'] ?? date('Y-m-d'),
        ]);
        $expenseId = (int) $stmt->fetchColumn();

        // Create journal entry
        $jeStmt = $db->prepare("
            INSERT INTO journal_entries (entry_date, reference_type, reference_id, description, created_by)
            VALUES (?, 'expense', ?, ?, ?)
            RETURNING id
        ");
        $jeStmt->execute([
            $data['entry_date'] ?? date('Y-m-d'),
            $expenseId,
            $data['description'],
            $userId,
        ]);
        $journalId = (int) $jeStmt->fetchColumn();

        // Determine GL account for debit (expense)
        $expenseGlMap = [
            'rent'        => '6100',
            'utilities'   => '6100',
            'salaries'    => '6000',
            'restocking'  => '5000',
            'transport'   => '6200',
            'marketing'   => '6200',
            'maintenance' => '6200',
            'supplies'    => '6200',
            'tax'         => '6200',
            'other'       => '6200',
        ];
        $debitCode = $expenseGlMap[$data['expense_category']] ?? '6200';

        // Debit expense account
        $db->prepare("
            INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
            SELECT ?, id, ?, 0 FROM gl_accounts WHERE account_code = ?
        ")->execute([$journalId, $data['amount'], $debitCode]);

        // Credit cash/bank
        $cashCode = ($data['payment_method'] === 'mpesa') ? '1000' : '1100';
        $db->prepare("
            INSERT INTO journal_lines (journal_id, account_id, debit_amount, credit_amount)
            SELECT ?, id, 0, ? FROM gl_accounts WHERE account_code = ?
        ")->execute([$journalId, $data['amount'], $cashCode]);

        $db->commit();
        return ['success' => true, 'expense_id' => $expenseId, 'message' => 'Expense recorded.'];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'Expense recording failed: ' . $e->getMessage()];
    }
}

/**
 * List expenses with date range and category filters.
 */
function list_expenses(string $fromDate = '', string $toDate = '', string $category = '', int $page = 1, int $perPage = 50): array
{
    $db = getDB();
    $offset = ($page - 1) * $perPage;
    $where = "WHERE 1=1";
    $params = [];

    if ($fromDate) {
        $where .= " AND e.entry_date >= ?";
        $params[] = $fromDate;
    }
    if ($toDate) {
        $where .= " AND e.entry_date <= ?";
        $params[] = $toDate;
    }
    if ($category) {
        $where .= " AND e.expense_category = ?";
        $params[] = $category;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM expenses e {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT e.*, u.full_name AS recorded_by_name
        FROM expenses e
        LEFT JOIN users u ON u.id = e.recorded_by
        {$where}
        ORDER BY e.entry_date DESC, e.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $perPage;
    $params[] = $offset;
    $stmt->execute($params);

    return [
        'data'       => $stmt->fetchAll(),
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'total_pages'=> (int) ceil($total / $perPage),
    ];
}

// ──────────────────────────────────────────────────────────────
// 2. FINANCIAL REPORTS
// ──────────────────────────────────────────────────────────────

/**
 * Daily Profit & Loss (margin) calculation.
 *
 * Formula: Gross Margin = Sales Revenue - COGS (cost of goods sold)
 *           Net Margin   = Gross Margin - Expenses
 *
 * COGS is calculated from sale_items × supplier_price at time of sale.
 */
function daily_pnl(string $date): array
{
    $db = getDB();

    // Total sales revenue for the day
    $salesStmt = $db->prepare("
        SELECT
            COUNT(*)::int              AS transaction_count,
            COALESCE(SUM(total), 0)    AS total_revenue,
            COALESCE(SUM(subtotal), 0) AS subtotal,
            COALESCE(SUM(tax_amount), 0) AS tax_collected,
            COALESCE(SUM(discount_amount), 0) AS total_discounts
        FROM sales
        WHERE created_at::date = ?
          AND sale_status = 'complete'
    ");
    $salesStmt->execute([$date]);
    $sales = $salesStmt->fetch();

    // COGS: sum of (sale_item quantity × product supplier_price at time of sale)
    $cogsStmt = $db->prepare("
        SELECT COALESCE(SUM(si.quantity * p.supplier_price), 0) AS cogs
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN products p ON p.id = si.product_id
        WHERE s.created_at::date = ?
          AND s.sale_status = 'complete'
    ");
    $cogsStmt->execute([$date]);
    $cogs = (float) $cogsStmt->fetchColumn();

    // Total expenses for the day
    $expStmt = $db->prepare("
        SELECT
            COUNT(*)::int              AS expense_count,
            COALESCE(SUM(amount), 0)   AS total_expenses
        FROM expenses
        WHERE entry_date = ?
    ");
    $expStmt->execute([$date]);
    $expenses = $expStmt->fetch();

    $revenue     = (float) $sales['total_revenue'];
    $grossMargin = round($revenue - $cogs, 2);
    $netMargin   = round($grossMargin - (float) $expenses['total_expenses'], 2);
    $marginPct   = $revenue > 0 ? round(($grossMargin / $revenue) * 100, 2) : 0;

    return [
        'date'             => $date,
        'revenue'          => $revenue,
        'subtotal'         => (float) $sales['subtotal'],
        'tax_collected'    => (float) $sales['tax_collected'],
        'discounts_given'  => (float) $sales['total_discounts'],
        'transaction_count'=> (int) $sales['transaction_count'],
        'cogs'             => $cogs,
        'gross_margin'     => $grossMargin,
        'gross_margin_pct' => $marginPct,
        'expenses'         => (float) $expenses['total_expenses'],
        'expense_count'    => (int) $expenses['expense_count'],
        'net_margin'       => $netMargin,
    ];
}

/**
 * Trial Balance as of a given date.
 */
function trial_balance(string $asOfDate): array
{
    $db = getDB();

    // Get all active GL accounts with their balances from journal lines
    $stmt = $db->prepare("
        SELECT
            ga.id,
            ga.account_code,
            ga.account_name,
            ga.account_type,
            COALESCE(SUM(jl.debit_amount), 0)  AS total_debits,
            COALESCE(SUM(jl.credit_amount), 0) AS total_credits
        FROM gl_accounts ga
        LEFT JOIN journal_lines jl ON jl.account_id = ga.id
        LEFT JOIN journal_entries je ON je.id = jl.journal_id AND je.entry_date <= ?
        WHERE ga.is_active = TRUE
        GROUP BY ga.id, ga.account_code, ga.account_name, ga.account_type
        ORDER BY ga.account_code
    ");
    $stmt->execute([$asOfDate]);
    $accounts = $stmt->fetchAll();

    $totalDebits  = 0;
    $totalCredits = 0;
    foreach ($accounts as &$acc) {
        $balance = (float) $acc['total_debits'] - (float) $acc['total_credits'];
        $acc['balance'] = $balance;
        $totalDebits  += (float) $acc['total_debits'];
        $totalCredits += (float) $acc['total_credits'];
    }
    unset($acc);

    return [
        'as_of_date'    => $asOfDate,
        'accounts'      => $accounts,
        'total_debits'  => round($totalDebits, 2),
        'total_credits' => round($totalCredits, 2),
    ];
}

/**
 * Income Statement for a date range.
 */
function income_statement(string $fromDate, string $toDate): array
{
    $db = getDB();

    // Revenue accounts (type = 'income')
    $revenue = $db->prepare("
        SELECT
            ga.account_code, ga.account_name,
            COALESCE(SUM(jl.credit_amount - jl.debit_amount), 0) AS balance
        FROM gl_accounts ga
        JOIN journal_lines jl ON jl.account_id = ga.id
        JOIN journal_entries je ON je.id = jl.journal_id
            AND je.entry_date BETWEEN ? AND ?
        WHERE ga.account_type = 'income' AND ga.is_active = TRUE
        GROUP BY ga.id, ga.account_code, ga.account_name
        ORDER BY ga.account_code
    ");
    $revenue->execute([$fromDate, $toDate]);
    $revenueAccounts = $revenue->fetchAll();

    // Expense accounts (type = 'expense' or 'cost_of_sales')
    $expenses = $db->prepare("
        SELECT
            ga.account_code, ga.account_name, ga.account_type,
            COALESCE(SUM(jl.debit_amount - jl.credit_amount), 0) AS balance
        FROM gl_accounts ga
        JOIN journal_lines jl ON jl.account_id = ga.id
        JOIN journal_entries je ON je.id = jl.journal_id
            AND je.entry_date BETWEEN ? AND ?
        WHERE ga.account_type IN ('expense', 'cost_of_sales') AND ga.is_active = TRUE
        GROUP BY ga.id, ga.account_code, ga.account_name, ga.account_type
        ORDER BY ga.account_type, ga.account_code
    ");
    $expenses->execute([$fromDate, $toDate]);
    $expenseAccounts = $expenses->fetchAll();

    $totalRevenue = 0;
    foreach ($revenueAccounts as $r) $totalRevenue += (float) $r['balance'];

    $totalCogs   = 0;
    $totalExp    = 0;
    foreach ($expenseAccounts as $e) {
        if ($e['account_type'] === 'cost_of_sales') {
            $totalCogs += (float) $e['balance'];
        } else {
            $totalExp += (float) $e['balance'];
        }
    }

    $grossProfit = round($totalRevenue - $totalCogs, 2);
    $netIncome   = round($grossProfit - $totalExp, 2);

    return [
        'period'          => ['from' => $fromDate, 'to' => $toDate],
        'revenue'         => $revenueAccounts,
        'total_revenue'   => round($totalRevenue, 2),
        'cost_of_sales'   => $expenseAccounts,
        'total_cogs'      => round($totalCogs, 2),
        'gross_profit'    => $grossProfit,
        'gross_margin_pct'=> $totalRevenue > 0 ? round(($grossProfit / $totalRevenue) * 100, 2) : 0,
        'expenses'        => $expenseAccounts,
        'total_expenses'  => round($totalExp, 2),
        'net_income'      => $netIncome,
        'net_margin_pct'  => $totalRevenue > 0 ? round(($netIncome / $totalRevenue) * 100, 2) : 0,
    ];
}

/**
 * Sales vs Expenses summary for a date range (for charts).
 */
function sales_vs_expenses_chart(string $fromDate, string $toDate): array
{
    $db = getDB();

    // Daily sales
    $sales = $db->prepare("
        SELECT created_at::date AS day, SUM(total) AS amount
        FROM sales
        WHERE created_at::date BETWEEN ? AND ? AND sale_status = 'complete'
        GROUP BY created_at::date
        ORDER BY day
    ");
    $sales->execute([$fromDate, $toDate]);
    $salesData = $sales->fetchAll();

    // Daily expenses
    $exp = $db->prepare("
        SELECT entry_date AS day, SUM(amount) AS amount
        FROM expenses
        WHERE entry_date BETWEEN ? AND ?
        GROUP BY entry_date
        ORDER BY day
    ");
    $exp->execute([$fromDate, $toDate]);
    $expData = $exp->fetchAll();

    // Merge into a single timeline
    $timeline = [];
    foreach ($salesData as $s) {
        $day = $s['day'];
        $timeline[$day] = ['day' => $day, 'sales' => (float) $s['amount'], 'expenses' => 0];
    }
    foreach ($expData as $e) {
        $day = $e['day'];
        if (!isset($timeline[$day])) {
            $timeline[$day] = ['day' => $day, 'sales' => 0, 'expenses' => 0];
        }
        $timeline[$day]['expenses'] = (float) $e['amount'];
    }
    ksort($timeline);

    return array_values($timeline);
}

/**
 * Get summary stats for the dashboard.
 */
function dashboard_financial_summary(): array
{
    $today = date('Y-m-d');
    $pnl   = daily_pnl($today);

    // Month to date
    $monthStart = date('Y-m-01');
    $monthPnl   = daily_pnl($monthStart); // We'll aggregate manually
    $db = getDB();

    $monthSales = $db->prepare("
        SELECT COALESCE(SUM(total), 0) FROM sales
        WHERE created_at::date BETWEEN ? AND ? AND sale_status = 'complete'
    ");
    $monthSales->execute([$monthStart, $today]);
    $monthRevenue = (float) $monthSales->fetchColumn();

    $monthExp = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM expenses
        WHERE entry_date BETWEEN ? AND ?
    ");
    $monthExp->execute([$monthStart, $today]);
    $monthExpenses = (float) $monthExp->fetchColumn();

    return [
        'today' => $pnl,
        'month' => [
            'revenue'  => $monthRevenue,
            'expenses' => $monthExpenses,
            'net'      => round($monthRevenue - $monthExpenses, 2),
        ],
    ];
}