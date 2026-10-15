<?php
declare(strict_types=1);

/**
 * Oftaplus End-to-End API Integration Test Suite
 * Tests REAL HTTP endpoints dispatched through dev-router.php and api/index.php:
 * 1. Database environment safeguard (blocks non-local or production DBs)
 * 2. Role-based authorization (403 for viewer and seller where appropriate, allowed for cashier/admin)
 * 3. 404 Not Found on invalid cashRegisterId in POST /cash-closures/close
 * 4. Future payment date rejection (422)
 * 5. Retroactive payment rejection for closed cash shifts (409) scoped to branch
 * 6. Cross-branch isolation: closed shift in branch A does NOT block branch B
 * 7. Global cash register closure: closed shift in branch_id IS NULL blocks all branches (409)
 * 8. Payment cancellation preserves document status (cancelled status not revived)
 * 9. Audit logging of payment cancellation in audit_logs table
 * 10. Cash closure calculation and closing consistency on dedicated test registers
 * 11. Strict and fail-safe database cleanup
 */

date_default_timezone_set('America/Santiago');

echo "=====================================================\n";
echo " OFTAPLUS REAL HTTP API INTEGRATION TEST SUITE\n";
echo "=====================================================\n\n";

$projectRoot = dirname(__DIR__);
$testPort = 8899;
$testBaseUrl = "http://127.0.0.1:{$testPort}";

// 1. Database Environment Safeguard
$envFile = $projectRoot . '/../.oftaplus-db.env';
$cfg = [];
if (is_readable($envFile)) {
    $raw = trim((string) file_get_contents($envFile));
    foreach (explode(';', $raw) as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        if ($k !== '') $cfg[$k] = $v;
    }
}
$dbHost = $cfg['DB_HOST'] ?? '127.0.0.1';
$dbName = $cfg['DB_NAME'] ?? 'oftaplus_local';
$dbUser = $cfg['DB_USER'] ?? 'oftaplus_app';
$dbPass = $cfg['DB_PASS'] ?? '';

// Strict verification: only run tests on local database
$allowedHosts = ['127.0.0.1', 'localhost'];
if (!in_array(strtolower($dbHost), $allowedHosts, true)) {
    fwrite(STDERR, "FATAL SECURITY SAFEGUARD: DB_HOST '$dbHost' is not local (127.0.0.1/localhost). Aborting test suite immediately.\n");
    exit(1);
}
if (!str_contains(strtolower($dbName), 'local') && !str_contains(strtolower($dbName), 'test')) {
    fwrite(STDERR, "FATAL SECURITY SAFEGUARD: DB_NAME '$dbName' does not contain 'local' or 'test'. Aborting test suite immediately.\n");
    exit(1);
}

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $offset = date('P');
    $pdo->exec("SET time_zone = " . $pdo->quote($offset));
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: Could not connect to test database: " . $e->getMessage() . "\n");
    exit(1);
}

// Global cleanup tracking
$cleanupBranchIds = [];
$cleanupRegisterIds = [];
$cleanupUserIds = [];
$cleanupDocIds = [];
$cleanupPaymentIds = [];
$cleanupClosureIds = [];
$cleanupProductIds = [];
$cleanupPriceIds = [];
$cookieFiles = [];
$serverProcess = null;
$pipes = [];
$cleanupHasFailed = false;

function performCleanup(): void {
    global $pdo, $cleanupBranchIds, $cleanupRegisterIds, $cleanupUserIds, $cleanupDocIds, $cleanupPaymentIds, $cleanupClosureIds, $cleanupProductIds, $cleanupPriceIds, $cookieFiles, $serverProcess, $pipes, $cleanupHasFailed;
    echo "\nCleaning up all test artifacts from database...\n";
    try {
        if (!empty($cleanupPaymentIds)) {
            $in = implode(',', array_map('intval', $cleanupPaymentIds));
            $pdo->exec("DELETE FROM audit_logs WHERE entity_type = 'payments' AND entity_id IN ($in)");
            $pdo->exec("DELETE FROM payments WHERE id IN ($in)");
        }
        if (!empty($cleanupDocIds)) {
            $in = implode(',', array_map('intval', $cleanupDocIds));
            $pdo->exec("DELETE FROM stock_movements WHERE document_id IN ($in)");
            $pdo->exec("DELETE FROM document_lines WHERE document_id IN ($in)");
            $pdo->exec("DELETE FROM payments WHERE document_id IN ($in)");
            $pdo->exec("DELETE FROM documents WHERE id IN ($in)");
        }
        if (!empty($cleanupPriceIds)) {
            $in = implode(',', array_map('intval', $cleanupPriceIds));
            $pdo->exec("DELETE FROM product_prices WHERE id IN ($in)");
        }
        if (!empty($cleanupProductIds)) {
            $in = implode(',', array_map('intval', $cleanupProductIds));
            $pdo->exec("DELETE FROM product_prices WHERE product_id IN ($in)");
            $pdo->exec("DELETE FROM products WHERE id IN ($in)");
        }
        if (!empty($cleanupClosureIds)) {
            $in = implode(',', array_map('intval', $cleanupClosureIds));
            $pdo->exec("DELETE FROM cash_closures WHERE id IN ($in)");
        }
        if (!empty($cleanupRegisterIds)) {
            $in = implode(',', array_map('intval', $cleanupRegisterIds));
            $pdo->exec("DELETE FROM cash_closures WHERE cash_register_id IN ($in)");
            $pdo->exec("DELETE FROM cash_registers WHERE id IN ($in)");
        }
        if (!empty($cleanupUserIds)) {
            $in = implode(',', array_map('intval', $cleanupUserIds));
            $pdo->exec("DELETE FROM users WHERE id IN ($in)");
        }
        if (!empty($cleanupBranchIds)) {
            $in = implode(',', array_map('intval', $cleanupBranchIds));
            $pdo->exec("DELETE FROM branches WHERE id IN ($in)");
        }

        echo "  [DONE] Database cleanup completed successfully.\n";
    } catch (Throwable $e) {
        $cleanupHasFailed = true;
        fwrite(STDERR, "  [CRITICAL ERROR] Cleanup failed: " . $e->getMessage() . "\n");
    }

    foreach ($cookieFiles as $cFile) {
        if (is_file($cFile)) @unlink($cFile);
    }

    if (is_array($pipes)) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
    }
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
        $serverProcess = null;
    }

    if ($cleanupHasFailed) {
        exit(1);
    }
}
register_shutdown_function('performCleanup');

// 2. Start embedded PHP server on port 8899
echo "Starting test PHP server on $testBaseUrl...\n";
$phpBinary = PHP_BINARY ?: 'C:\\xampp\\php\\php.exe';
$cmd = sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s/dev-router.php"', $phpBinary, $testPort, $projectRoot, $projectRoot);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$serverProcess = proc_open($cmd, $descriptors, $pipes, $projectRoot);
if (!is_resource($serverProcess)) {
    fwrite(STDERR, "FATAL: Could not start embedded test server.\n");
    exit(1);
}
usleep(800000);

// Helper for HTTP requests
function httpRequest(string $method, string $path, ?array $payload = null, ?string $cookieFile = null): array {
    global $testBaseUrl;
    $url = $testBaseUrl . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Origin: ' . $testBaseUrl,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    $rawResponse = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        return ['status' => 0, 'error' => $error, 'body' => null];
    }
    $decoded = json_decode((string) $rawResponse, true);
    return ['status' => $statusCode, 'body' => $decoded, 'raw' => $rawResponse];
}

$passed = 0;
$failed = 0;
function assertHttp(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] $description\n";
        $passed++;
    } else {
        echo "  [FAIL] $description" . ($detail ? " ($detail)" : '') . "\n";
        $failed++;
    }
}

// 3. Set up isolated test branches, cash registers and test users in DB
$company = $pdo->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetch();
$companyId = (int) $company['id'];

// Preflight cleanup of stranded suite artifacts from prior interrupted runs (strictly scoped to company and test range)
$strandedDocsStmt = $pdo->prepare('SELECT id FROM documents WHERE company_id = ? AND number BETWEEN 888801 AND 888899');
$strandedDocsStmt->execute([$companyId]);
$strandedDocs = $strandedDocsStmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($strandedDocs)) {
    $in = implode(',', array_map('intval', $strandedDocs));
    $pdo->exec("DELETE FROM stock_movements WHERE document_id IN ($in)");
    $pdo->exec("DELETE FROM document_lines WHERE document_id IN ($in)");
    $pdo->exec("DELETE FROM payments WHERE document_id IN ($in)");
    $pdo->exec("DELETE FROM documents WHERE id IN ($in)");
}
$strandedRegsStmt = $pdo->prepare('SELECT id FROM cash_registers WHERE company_id = ? AND name LIKE "Caja Test Suite%"');
$strandedRegsStmt->execute([$companyId]);
$strandedRegs = $strandedRegsStmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($strandedRegs)) {
    $in = implode(',', array_map('intval', $strandedRegs));
    $pdo->exec("DELETE FROM cash_closures WHERE cash_register_id IN ($in)");
    $pdo->exec("DELETE FROM cash_registers WHERE id IN ($in)");
}
$pdo->prepare('DELETE FROM users WHERE company_id = ? AND email LIKE "%-suite@oftaplus.local"')->execute([$companyId]);
$strandedProdsStmt = $pdo->prepare('SELECT id FROM products WHERE company_id = ? AND sku IN ("SKU-ZERO-TEST", "SKU-NOPRICE-TEST")');
$strandedProdsStmt->execute([$companyId]);
$strandedProds = $strandedProdsStmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($strandedProds)) {
    $in = implode(',', array_map('intval', $strandedProds));
    $pdo->exec("DELETE FROM product_prices WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM products WHERE id IN ($in)");
}
$strandedBranchesStmt = $pdo->prepare('SELECT id FROM branches WHERE company_id = ? AND name LIKE "Sucursal Test Suite%"');
$strandedBranchesStmt->execute([$companyId]);
$strandedBranches = $strandedBranchesStmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($strandedBranches)) {
    $in = implode(',', array_map('intval', $strandedBranches));
    $pdo->exec("DELETE FROM branches WHERE id IN ($in)");
}

// Dedicated branches A and B
$pdo->prepare('INSERT INTO branches (company_id, name, address, active) VALUES (?, "Sucursal Test Suite A", "Calle Test A 100", 1)')->execute([$companyId]);
$branchAId = (int) $pdo->lastInsertId();
$cleanupBranchIds[] = $branchAId;

$pdo->prepare('INSERT INTO branches (company_id, name, address, active) VALUES (?, "Sucursal Test Suite B", "Calle Test B 200", 1)')->execute([$companyId]);
$branchBId = (int) $pdo->lastInsertId();
$cleanupBranchIds[] = $branchBId;

// Dedicated registers: Register A (Branch A), Register B (Branch B), Register Global (branch_id = NULL)
$pdo->prepare('INSERT INTO cash_registers (company_id, branch_id, name, active) VALUES (?, ?, "Caja Test Suite A", 1)')->execute([$companyId, $branchAId]);
$cashRegAId = (int) $pdo->lastInsertId();
$cleanupRegisterIds[] = $cashRegAId;

$pdo->prepare('INSERT INTO cash_registers (company_id, branch_id, name, active) VALUES (?, ?, "Caja Test Suite B", 1)')->execute([$companyId, $branchBId]);
$cashRegBId = (int) $pdo->lastInsertId();
$cleanupRegisterIds[] = $cashRegBId;

$pdo->prepare('INSERT INTO cash_registers (company_id, branch_id, name, active) VALUES (?, NULL, "Caja Test Suite Global", 1)')->execute([$companyId]);
$cashRegGlobalId = (int) $pdo->lastInsertId();
$cleanupRegisterIds[] = $cashRegGlobalId;

$passwordPlain = 'TestPass123!';
$passwordHash = password_hash($passwordPlain, PASSWORD_BCRYPT);

// Insert test users: admin, viewer, seller, cashier
$stmt = $pdo->prepare('INSERT INTO users (company_id, branch_id, display_name, email, role, password_hash, active) VALUES (?, ?, "Admin Test Suite", "test-admin-suite@oftaplus.local", "admin", ?, 1)');
$stmt->execute([$companyId, $branchAId, $passwordHash]);
$adminId = (int) $pdo->lastInsertId();
$cleanupUserIds[] = $adminId;

$stmt = $pdo->prepare('INSERT INTO users (company_id, branch_id, display_name, email, role, password_hash, active) VALUES (?, ?, "Viewer Test Suite", "test-viewer-suite@oftaplus.local", "viewer", ?, 1)');
$stmt->execute([$companyId, $branchAId, $passwordHash]);
$viewerId = (int) $pdo->lastInsertId();
$cleanupUserIds[] = $viewerId;

$stmt = $pdo->prepare('INSERT INTO users (company_id, branch_id, display_name, email, role, password_hash, active) VALUES (?, ?, "Seller Test Suite", "test-seller-suite@oftaplus.local", "seller", ?, 1)');
$stmt->execute([$companyId, $branchAId, $passwordHash]);
$sellerId = (int) $pdo->lastInsertId();
$cleanupUserIds[] = $sellerId;

$stmt = $pdo->prepare('INSERT INTO users (company_id, branch_id, display_name, email, role, password_hash, active) VALUES (?, ?, "Cashier Test Suite", "test-cashier-suite@oftaplus.local", "cashier", ?, 1)');
$stmt->execute([$companyId, $branchAId, $passwordHash]);
$cashierId = (int) $pdo->lastInsertId();
$cleanupUserIds[] = $cashierId;

$adminCookies = tempnam(sys_get_temp_dir(), 'ofta_admin_');
$viewerCookies = tempnam(sys_get_temp_dir(), 'ofta_viewer_');
$sellerCookies = tempnam(sys_get_temp_dir(), 'ofta_seller_');
$cashierCookies = tempnam(sys_get_temp_dir(), 'ofta_cashier_');
$cookieFiles = [$adminCookies, $viewerCookies, $sellerCookies, $cashierCookies];

// 4. Authenticate all sessions via real HTTP
echo "\n1. Authenticating real user sessions over HTTP...\n";
$adminLogin = httpRequest('POST', '/api/auth/login', ['email' => 'test-admin-suite@oftaplus.local', 'password' => $passwordPlain], $adminCookies);
assertHttp("Admin login returns HTTP 200 and authenticated=true", $adminLogin['status'] === 200 && ($adminLogin['body']['authenticated'] ?? false) === true);

$viewerLogin = httpRequest('POST', '/api/auth/login', ['email' => 'test-viewer-suite@oftaplus.local', 'password' => $passwordPlain], $viewerCookies);
assertHttp("Viewer login returns HTTP 200 and authenticated=true", $viewerLogin['status'] === 200 && ($viewerLogin['body']['authenticated'] ?? false) === true);

$sellerLogin = httpRequest('POST', '/api/auth/login', ['email' => 'test-seller-suite@oftaplus.local', 'password' => $passwordPlain], $sellerCookies);
assertHttp("Seller login returns HTTP 200 and authenticated=true", $sellerLogin['status'] === 200 && ($sellerLogin['body']['authenticated'] ?? false) === true);

$cashierLogin = httpRequest('POST', '/api/auth/login', ['email' => 'test-cashier-suite@oftaplus.local', 'password' => $passwordPlain], $cashierCookies);
assertHttp("Cashier login returns HTTP 200 and authenticated=true", $cashierLogin['status'] === 200 && ($cashierLogin['body']['authenticated'] ?? false) === true);

// 5. Test Role-Based Restrictions
echo "\n2. Testing HTTP 403 Role Restrictions on Viewer and Seller...\n";

// Viewer: 403 on write endpoints
$viewerPayment = httpRequest('POST', '/api/payments', ['documentId' => 999999, 'paymentMethodId' => 1, 'amount' => 5000], $viewerCookies);
assertHttp("POST /api/payments with viewer role rejected with HTTP 403", $viewerPayment['status'] === 403);

$viewerCancel = httpRequest('POST', '/api/payments/999999/cancel', [], $viewerCookies);
assertHttp("POST /api/payments/{id}/cancel with viewer role rejected with HTTP 403", $viewerCancel['status'] === 403);

$viewerClose = httpRequest('POST', '/api/cash-closures/close', ['countedAmount' => 10000], $viewerCookies);
assertHttp("POST /api/cash-closures/close with viewer role rejected with HTTP 403", $viewerClose['status'] === 403);

$viewerDraft = httpRequest('POST', '/api/documents/draft', ['documentTypeCode' => 'BOL', 'lines' => []], $viewerCookies);
assertHttp("POST /api/documents/draft with viewer role rejected with HTTP 403", $viewerDraft['status'] === 403);

$viewerCustomer = httpRequest('POST', '/api/customers', ['name' => 'Cliente Prohibido'], $viewerCookies);
assertHttp("POST /api/customers with viewer role rejected with HTTP 403", $viewerCustomer['status'] === 403);

// Seller: 403 on payments (only owner, admin, cashier allowed)
$sellerPayment = httpRequest('POST', '/api/payments', ['documentId' => 999999, 'paymentMethodId' => 1, 'amount' => 5000], $sellerCookies);
assertHttp("POST /api/payments with seller role rejected with HTTP 403", $sellerPayment['status'] === 403);

// 6. Test 404 on Invalid Cash Register in POST /cash-closures/close
echo "\n3. Testing HTTP 404 for non-existent cash register in POST /cash-closures/close...\n";
$invalidRegisterClose = httpRequest('POST', '/api/cash-closures/close', [
    'cashRegisterId' => 99999999,
    'countedAmount' => 1000.00,
], $adminCookies);
assertHttp("POST /api/cash-closures/close with invalid cashRegisterId returns HTTP 404", $invalidRegisterClose['status'] === 404);

// 7. Test Payment Date Validation (Future date rejection)
echo "\n4. Testing Payment Date Validation (Future Date Rejection)...\n";

$docTypeStmt = $pdo->query('SELECT id FROM document_types WHERE code = "BOL" LIMIT 1');
$bolTypeId = (int) $docTypeStmt->fetchColumn();
$pmStmt = $pdo->query('SELECT id FROM payment_methods WHERE active = 1 ORDER BY id LIMIT 1');
$validPmId = (int) $pmStmt->fetchColumn();

// Create test document in Branch A
$pdo->prepare('INSERT INTO documents (company_id, branch_id, document_type_id, number, status, issue_date, total, paid_total) VALUES (?, ?, ?, 888801, "issued", "2026-01-01", 30000.00, 0.00)')
    ->execute([$companyId, $branchAId, $bolTypeId]);
$docBranchAId = (int) $pdo->lastInsertId();
$cleanupDocIds[] = $docBranchAId;

// Attempt payment with future date
$futurePayment = httpRequest('POST', '/api/payments', [
    'documentId' => $docBranchAId,
    'paymentMethodId' => $validPmId,
    'amount' => 5000.00,
    'paidAt' => '2099-01-01',
], $adminCookies);
assertHttp("Payment with future date rejected with HTTP 422", $futurePayment['status'] === 422);

// 8. Test Retroactive Payment Rejection Scoped to Branch & Cross-Branch Isolation
echo "\n5. Testing Closed Cash Shift Branch Scoping and Isolation...\n";

// Preflight check: verify no pre-existing closed shifts conflict with test dates
$preflightShiftStmt = $pdo->prepare('
    SELECT cc.id, cr.name AS register_name, cc.opened_at, cc.closed_at
    FROM cash_closures cc
    JOIN cash_registers cr ON cr.id = cc.cash_register_id
    WHERE cr.company_id = ? AND cc.status = "closed"
      AND (
        (cc.opened_at <= "2026-01-05 23:59:59" AND cc.closed_at >= "2026-01-05 00:00:00")
        OR (cc.opened_at <= "2026-01-10 23:59:59" AND cc.closed_at >= "2026-01-10 00:00:00")
      )
');
$preflightShiftStmt->execute([$companyId]);
$conflictingShifts = $preflightShiftStmt->fetchAll();
if (!empty($conflictingShifts)) {
    fwrite(STDERR, "FATAL PREFLIGHT: Found " . count($conflictingShifts) . " conflicting pre-existing closed shift(s) covering test dates. Aborting.\n");
    exit(1);
}
assertHttp("Preflight: No pre-existing closed shifts conflict with test dates (2026-01-05 / 2026-01-10)", true);

// Shift in Caja Test Suite A (Branch A) covering 2026-01-05
$closedOpenedAt = '2026-01-05 08:00:00';
$closedClosedAt = '2026-01-05 20:00:00';
$pdo->prepare('INSERT INTO cash_closures (cash_register_id, opened_at, closed_at, opening_amount, closing_amount, status, closed_by) VALUES (?, ?, ?, 50000.00, 150000.00, "closed", ?)')
    ->execute([$cashRegAId, $closedOpenedAt, $closedClosedAt, $adminId]);
$cleanupClosureIds[] = (int) $pdo->lastInsertId();

// Document in Branch B
$pdo->prepare('INSERT INTO documents (company_id, branch_id, document_type_id, number, status, issue_date, total, paid_total) VALUES (?, ?, ?, 888802, "issued", "2026-01-01", 30000.00, 0.00)')
    ->execute([$companyId, $branchBId, $bolTypeId]);
$docBranchBId = (int) $pdo->lastInsertId();
$cleanupDocIds[] = $docBranchBId;

// Payment on Branch A document on 2026-01-05 -> Blocked (409)
$retroPaymentA = httpRequest('POST', '/api/payments', [
    'documentId' => $docBranchAId,
    'paymentMethodId' => $validPmId,
    'amount' => 10000.00,
    'paidAt' => '2026-01-05',
], $adminCookies);
assertHttp("Payment in Branch A within closed shift is rejected with HTTP 409", $retroPaymentA['status'] === 409);

// Payment on Branch B document on 2026-01-05 as Cashier -> Succeeded (201) (Branch B shift is open and Cashier role allowed)
$paymentB = httpRequest('POST', '/api/payments', [
    'documentId' => $docBranchBId,
    'paymentMethodId' => $validPmId,
    'amount' => 10000.00,
    'paidAt' => '2026-01-05',
], $cashierCookies);
assertHttp("Cashier records payment in Branch B on same date with HTTP 201 (branch isolation & cashier allowed)", $paymentB['status'] === 201);
if (!empty($paymentB['body']['payment']['id'])) {
    $cleanupPaymentIds[] = (int) $paymentB['body']['payment']['id'];
}

// 9. Test Global Cash Register (branch_id IS NULL) Blocking
echo "\n6. Testing Global Cash Register Shift Closure Blocking (branch_id IS NULL)...\n";

// Shift in Caja Test Suite Global (branch_id IS NULL) covering 2026-01-10
$globalOpenedAt = '2026-01-10 08:00:00';
$globalClosedAt = '2026-01-10 20:00:00';
$pdo->prepare('INSERT INTO cash_closures (cash_register_id, opened_at, closed_at, opening_amount, closing_amount, status, closed_by) VALUES (?, ?, ?, 20000.00, 80000.00, "closed", ?)')
    ->execute([$cashRegGlobalId, $globalOpenedAt, $globalClosedAt, $adminId]);
$cleanupClosureIds[] = (int) $pdo->lastInsertId();

// Payment on Branch B document on 2026-01-10 -> Global shift MUST block it with HTTP 409
$retroGlobalPayment = httpRequest('POST', '/api/payments', [
    'documentId' => $docBranchBId,
    'paymentMethodId' => $validPmId,
    'amount' => 5000.00,
    'paidAt' => '2026-01-10',
], $adminCookies);
assertHttp("Payment in branch is rejected with HTTP 409 when global cash register shift is closed", $retroGlobalPayment['status'] === 409);

// Create document WITHOUT branch (global sale / central document)
$pdo->prepare('INSERT INTO documents (company_id, branch_id, document_type_id, number, status, issue_date, total, paid_total) VALUES (?, NULL, ?, 888803, "issued", "2026-01-01", 20000.00, 0.00)')
    ->execute([$companyId, $bolTypeId]);
$docGlobalId = (int) $pdo->lastInsertId();
$cleanupDocIds[] = $docGlobalId;

// Document without branch on 2026-01-10 (global shift closed) -> MUST be blocked with HTTP 409
$globalDocBlocked = httpRequest('POST', '/api/payments', [
    'documentId' => $docGlobalId,
    'paymentMethodId' => $validPmId,
    'amount' => 5000.00,
    'paidAt' => '2026-01-10',
], $adminCookies);
assertHttp("Document without branch is rejected with HTTP 409 when global cash register shift is closed", $globalDocBlocked['status'] === 409);

// Document without branch on 2026-01-05 (Branch A shift closed, but global shift OPEN) -> MUST succeed with HTTP 201
$globalDocAllowed = httpRequest('POST', '/api/payments', [
    'documentId' => $docGlobalId,
    'paymentMethodId' => $validPmId,
    'amount' => 5000.00,
    'paidAt' => '2026-01-05',
], $adminCookies);
assertHttp("Document without branch succeeds with HTTP 201 when global shift is open (branch-specific shifts do not block global documents)", $globalDocAllowed['status'] === 201);
if (!empty($globalDocAllowed['body']['payment']['id'])) {
    $cleanupPaymentIds[] = (int) $globalDocAllowed['body']['payment']['id'];
}

// 10. Test Payment Cancellation, Cancelled Status Preservation, and Audit Log
echo "\n7. Testing Payment Void, Status Preservation & Audit Logging...\n";

$nowDate = date('Y-m-d');
$validPaymentA = httpRequest('POST', '/api/payments', [
    'documentId' => $docBranchAId,
    'paymentMethodId' => $validPmId,
    'amount' => 15000.00,
    'paidAt' => $nowDate,
    'reference' => 'SUITE-REF-A',
], $adminCookies);
assertHttp("Admin records valid payment on Branch A document returning HTTP 201", $validPaymentA['status'] === 201);
$createdPaymentId = (int) ($validPaymentA['body']['payment']['id'] ?? 0);
if ($createdPaymentId) $cleanupPaymentIds[] = $createdPaymentId;

// Mark document status as 'cancelled' to verify preservation bugfix
$pdo->prepare('UPDATE documents SET status = "cancelled" WHERE id = ?')->execute([$docBranchAId]);

// Void payment
$cancelResponse = httpRequest('POST', "/api/payments/{$createdPaymentId}/cancel", [], $adminCookies);
assertHttp("Admin voids payment via HTTP returning HTTP 200", $cancelResponse['status'] === 200);

// Verify in DB that document status remained 'cancelled'
$docCheck = $pdo->prepare('SELECT status, paid_total FROM documents WHERE id = ?');
$docCheck->execute([$docBranchAId]);
$docRow = $docCheck->fetch();
assertHttp("Cancelled document preserved status 'cancelled' after payment void", ($docRow['status'] ?? '') === 'cancelled');
assertHttp("Document paid_total updated to 0", (float) ($docRow['paid_total'] ?? -1) === 0.0);

// Verify audit_logs table entry
$auditCheck = $pdo->prepare('SELECT id, user_id, action, changes_json FROM audit_logs WHERE company_id = ? AND entity_type = "payments" AND entity_id = ? AND action = "cancel" ORDER BY id DESC LIMIT 1');
$auditCheck->execute([$companyId, $createdPaymentId]);
$auditRow = $auditCheck->fetch();
assertHttp("audit_logs record exists for cancelled payment", !empty($auditRow['id']));
assertHttp("audit_logs recorded correct user_id who executed the void", (int) ($auditRow['user_id'] ?? 0) === $adminId);

$auditData = json_decode((string) ($auditRow['changes_json'] ?? ''), true);
assertHttp("audit_logs changes_json contains previous and new paid totals", isset($auditData['previous_paid_total']) && $auditData['previous_paid_total'] == 15000.00 && $auditData['new_paid_total'] == 0.0);

// 11. Test Cash Closure Endpoints on Dedicated Test Register
echo "\n8. Testing Real HTTP Cash Closure Endpoints on Dedicated Test Register...\n";

$currentClosure = httpRequest('GET', "/api/cash-closures/current?cashRegisterId={$cashRegAId}", null, $adminCookies);
assertHttp("GET /api/cash-closures/current for test register returns HTTP 200", $currentClosure['status'] === 200);
assertHttp("Current cash closure returns calculatedAmount number", isset($currentClosure['body']['calculatedAmount']));
assertHttp("Current cash closure points to Caja Test Suite A", ($currentClosure['body']['cashRegister']['id'] ?? 0) === $cashRegAId);

$closeAction = httpRequest('POST', '/api/cash-closures/close', [
    'cashRegisterId' => $cashRegAId,
    'countedAmount' => 25000.00,
], $adminCookies);
assertHttp("POST /api/cash-closures/close on test register returns HTTP 201", $closeAction['status'] === 201);
assertHttp("Closed shift status is 'closed'", ($closeAction['body']['closure']['status'] ?? '') === 'closed');
assertHttp("Closed shift matches test cash register ID", ($closeAction['body']['closure']['cashRegisterId'] ?? 0) === $cashRegAId);
if (!empty($closeAction['body']['closure']['id'])) {
    $cleanupClosureIds[] = (int) $closeAction['body']['closure']['id'];
}

// 12. Test Zero-Amount Sale Handling via POST /api/documents/charge
// Note: This test verifies that sales with valid product prices and 100% discount ($0 total) are accepted
// without requiring payments or credit terms. This operation increments the next_number in document_series (BOL)
// on the local test database as part of issuing an official document folio.
echo "\n9. Testing Zero-Amount Sale Handling via POST /api/documents/charge...\n";

$pdo->prepare('INSERT INTO products (company_id, sku, name, product_type, tax_rate, active) VALUES (?, "SKU-ZERO-TEST", "Servicio Suite Test", "service", 19.00, 1)')->execute([$companyId]);
$testProdId = (int) $pdo->lastInsertId();
$cleanupProductIds[] = $testProdId;

$pdo->prepare('INSERT INTO product_prices (product_id, price_list, amount, valid_from) VALUES (?, "general", 10000.00, CURRENT_DATE)')->execute([$testProdId]);
$testPriceId = (int) $pdo->lastInsertId();
$cleanupPriceIds[] = $testPriceId;

$zeroChargeResponse = httpRequest('POST', '/api/documents/charge', [
    'type' => 'BOL',
    'lines' => [
        [
            'productId' => $testProdId,
            'quantity' => 1,
            'discountPercent' => 100,
        ],
    ],
    'payments' => [],
], $adminCookies);

assertHttp("POST /api/documents/charge with $0 total and empty payments returns HTTP 201", $zeroChargeResponse['status'] === 201);
assertHttp("Zero-amount document status is 'paid'", ($zeroChargeResponse['body']['document']['status'] ?? '') === 'paid');
assertHttp("Zero-amount document total is 0.0", (float) ($zeroChargeResponse['body']['document']['total'] ?? -1) === 0.0);
assertHttp("Zero-amount document paidTotal is 0.0", (float) ($zeroChargeResponse['body']['document']['paidTotal'] ?? -1) === 0.0);
assertHttp("Zero-amount document pending balance is 0.0", (float) ($zeroChargeResponse['body']['document']['pending'] ?? -1) === 0.0);

if (!empty($zeroChargeResponse['body']['document']['id'])) {
    $cleanupDocIds[] = (int) $zeroChargeResponse['body']['document']['id'];
}

// Verify that a product without a configured price (price <= 0) is strictly rejected with HTTP 422
$pdo->prepare('INSERT INTO products (company_id, sku, name, product_type, tax_rate, active) VALUES (?, "SKU-NOPRICE-TEST", "Servicio Sin Precio", "service", 19.00, 1)')->execute([$companyId]);
$noPriceProdId = (int) $pdo->lastInsertId();
$cleanupProductIds[] = $noPriceProdId;

$noPriceResponse = httpRequest('POST', '/api/documents/charge', [
    'type' => 'BOL',
    'lines' => [
        [
            'productId' => $noPriceProdId,
            'quantity' => 1,
            'discountPercent' => 0,
        ],
    ],
    'payments' => [],
], $adminCookies);
assertHttp("POST /api/documents/charge with unpriced product rejected with HTTP 422", $noPriceResponse['status'] === 422);

// 13. Summary
echo "\n=====================================================\n";
echo " FINAL HTTP TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "=====================================================\n";

exit($failed > 0 ? 1 : 0);
