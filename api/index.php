<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

date_default_timezone_set('America/Santiago');

$requestIsHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('oftaplus_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $requestIsHttps, 'samesite' => 'Strict']);
session_start();

require_once __DIR__ . '/reports.php';

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['ok' => false, 'error' => $message], $status);
}

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) fail('JSON inválido.');
    return $decoded;
}

function config(): array
{
    $values = [];
    $envFile = getenv('OFTAPLUS_DB_ENV') ?: dirname(__DIR__, 2) . '/.oftaplus-db.env';
    if (is_readable($envFile)) {
        $raw = trim((string) file_get_contents($envFile));
        foreach (explode(';', $raw) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key !== '') $values[$key] = $value;
        }
    }
    return [
        'host' => getenv('DB_HOST') ?: ($values['DB_HOST'] ?? 'localhost'),
        'name' => getenv('DB_DATABASE') ?: ($values['DB_NAME'] ?? ''),
        'user' => getenv('DB_USERNAME') ?: ($values['DB_USER'] ?? ''),
        'pass' => getenv('DB_PASSWORD') ?: ($values['DB_PASS'] ?? ''),
        'setup_token' => getenv('OFTAPLUS_SETUP_TOKEN') ?: ($values['SETUP_TOKEN'] ?? ''),
    ];
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = config();
    if ($cfg['name'] === '' || $cfg['user'] === '') fail('La configuración de base de datos no está disponible.', 503);
    try {
        $pdo = new PDO(
            'mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4',
            $cfg['user'],
            $cfg['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $offset = date('P');
        $pdo->exec("SET time_zone = " . $pdo->quote($offset));
        return $pdo;
    } catch (Throwable $error) {
        fail('No se pudo conectar a la base de datos.', 503);
    }
}

function tenant(PDO $pdo): array
{
    if (!empty($_SESSION['company_id'])) {
        $statement = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $statement->execute([(int) $_SESSION['company_id']]);
        $company = $statement->fetch();
        if ($company) return $company;
        fail('La empresa de esta sesión ya no existe.', 401);
    }
    $company = $pdo->query('SELECT * FROM companies ORDER BY id LIMIT 1')->fetch();
    if (!$company) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO companies (name, currency, timezone) VALUES (?, ?, ?)')
                ->execute(['Oftaplus', 'CLP', 'America/Santiago']);
            $companyId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO branches (company_id, name) VALUES (?, ?)')->execute([$companyId, 'Casa matriz']);
            $types = [
                ['BOL', 'Boleta', 'sale', 1, 1],
                ['FAC', 'Factura emitida', 'sale', 1, 1],
                ['GUI', 'Guía emitida', 'sale', 1, 0],
                ['PED', 'Pedido de cliente', 'sale', 0, 0],
                ['PRE', 'Presupuesto', 'sale', 0, 0],
                ['GRI', 'Guía recibida', 'purchase', 1, 0],
                ['FAR', 'Factura recibida', 'purchase', 0, 1],
            ];
            $typeStatement = $pdo->prepare('INSERT INTO document_types (company_id, code, name, direction, affects_stock, affects_receivables) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($types as $type) $typeStatement->execute([$companyId, ...$type]);
            $pdo->prepare('INSERT INTO payment_methods (company_id, name, code) VALUES (?, ?, ?)')->execute([$companyId, 'Efectivo', 'cash']);
            $pdo->prepare('INSERT INTO payment_terms (company_id, name, days) VALUES (?, ?, ?)')->execute([$companyId, 'Pago inmediato', 0]);
            $pdo->commit();
            $company = $pdo->query('SELECT * FROM companies WHERE id = ' . $companyId)->fetch();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    return $company;
}

function salesStockLocation(PDO $pdo, int $companyId, ?int $branchId): int
{
    $statement = $pdo->prepare('SELECT id FROM stock_locations WHERE company_id = ? AND active = 1 AND (branch_id <=> ? OR branch_id IS NULL) ORDER BY (branch_id IS NULL), id LIMIT 1');
    $statement->execute([$companyId, $branchId]);
    $locationId = $statement->fetchColumn();
    if ($locationId !== false) return (int) $locationId;

    $pdo->prepare('INSERT INTO stock_locations (company_id, branch_id, name, active) VALUES (?, ?, ?, 1)')
        ->execute([$companyId, $branchId, 'Bodega principal']);
    return (int) $pdo->lastInsertId();
}

function recordDocumentStockOut(PDO $pdo, int $companyId, int $documentId, ?int $branchId): void
{
    $locationId = salesStockLocation($pdo, $companyId, $branchId);
    $lines = $pdo->prepare('SELECT dl.product_id, SUM(dl.quantity) AS quantity FROM document_lines dl JOIN products p ON p.id = dl.product_id WHERE dl.document_id = ? AND p.company_id = ? AND p.product_type = "product" GROUP BY dl.product_id ORDER BY dl.product_id');
    $lines->execute([$documentId, $companyId]);
    $balance = $pdo->prepare('SELECT COALESCE(SUM(CASE movement_type WHEN "out" THEN -ABS(quantity) WHEN "adjustment" THEN quantity ELSE quantity END), 0) FROM stock_movements WHERE company_id = ? AND product_id = ? AND location_id = ?');
    $insert = $pdo->prepare('INSERT INTO stock_movements (company_id, product_id, location_id, document_id, quantity, movement_type, occurred_at) VALUES (?, ?, ?, ?, ?, "out", NOW())');
    foreach ($lines->fetchAll() as $line) {
        $productId = (int) $line['product_id'];
        $lock = $pdo->prepare('SELECT id FROM products WHERE id = ? AND company_id = ? FOR UPDATE');
        $lock->execute([$productId, $companyId]);
        $balance->execute([$companyId, $productId, $locationId]);
        $available = (float) $balance->fetchColumn();
        $quantity = (float) $line['quantity'];
        if ($quantity > $available) fail('Stock insuficiente para el producto ' . $productId . '. Disponible: ' . number_format($available, 3, ',', '.') . '.', 422);
        $insert->execute([$companyId, $productId, $locationId, $documentId, $quantity]);
    }
}

function dateParam(string $name, string $default): string
{
    $value = trim((string) ($_GET[$name] ?? ''));
    if ($value === '') return $default;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) fail('Fecha inválida en "' . $name . '". Usa AAAA-MM-DD.', 422);
    return $value;
}

function validRut(string $rut): bool
{
    $clean = strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    if (strlen($clean) < 2) return false;
    $body = substr($clean, 0, -1);
    $digit = substr($clean, -1);
    if (!ctype_digit($body)) return false;
    $sum = 0; $factor = 2;
    for ($i = strlen($body) - 1; $i >= 0; $i--) {
        $sum += (int) $body[$i] * $factor;
        $factor = $factor === 7 ? 2 : $factor + 1;
    }
    $expected = 11 - ($sum % 11);
    $expectedDigit = $expected === 11 ? '0' : ($expected === 10 ? 'K' : (string) $expected);
    return $digit === $expectedDigit;
}

function customerInput(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') fail('El nombre es obligatorio.', 422);
    $taxId = trim((string) ($input['taxId'] ?? ''));
    if ($taxId !== '' && !validRut($taxId)) fail('El RUT no es válido.', 422);
    $email = trim((string) ($input['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('El correo no es válido.', 422);
    return [
        'name' => mb_substr($name, 0, 180),
        'tax_id' => $taxId !== '' ? $taxId : null,
        'email' => $email !== '' ? $email : null,
        'phone' => trim((string) ($input['phone'] ?? '')) ?: null,
        'address' => trim((string) ($input['address'] ?? '')) ?: null,
        'active' => array_key_exists('active', $input) ? ((bool) $input['active'] ? 1 : 0) : 1,
    ];
}

function productInput(array $input): array
{
    $sku = trim((string) ($input['sku'] ?? ''));
    if ($sku === '') fail('El código es obligatorio.', 422);
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') fail('El nombre es obligatorio.', 422);
    $taxRate = (float) ($input['taxRate'] ?? 19);
    if ($taxRate < 0 || $taxRate > 100) fail('El IVA debe estar entre 0 y 100.', 422);
    $cost = round((float) ($input['costAmount'] ?? 0), 2);
    $price = round((float) ($input['priceAmount'] ?? 0), 2);
    if ($cost < 0 || $price < 0) fail('Los montos no pueden ser negativos.', 422);
    return [
        'sku' => mb_substr($sku, 0, 80),
        'name' => mb_substr($name, 0, 180),
        'description' => trim((string) ($input['description'] ?? '')) ?: null,
        'tax_rate' => $taxRate,
        'cost_amount' => $cost,
        'price_amount' => $price,
        'active' => array_key_exists('active', $input) ? ((bool) $input['active'] ? 1 : 0) : 1,
    ];
}

function route(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $apiPosition = strpos($path, '/api');
    return $apiPosition === false ? '/' : (substr($path, $apiPosition + 4) ?: '/');
}

function authSetupRequired(PDO $pdo): bool
{
    return (int) $pdo->query('SELECT COUNT(*) FROM users WHERE password_hash IS NOT NULL AND password_hash <> ""')->fetchColumn() === 0;
}

// Intentos de login por IP guardados fuera de la sesión, para que borrar la cookie no sirva para saltarse el límite.
function loginLimitFile(): string
{
    return sys_get_temp_dir() . '/oftaplus-login-' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local')) . '.json';
}

function loginLimitState(): array
{
    $raw = @file_get_contents(loginLimitFile());
    $state = $raw ? json_decode($raw, true) : null;
    return is_array($state) ? $state : ['attempts' => 0, 'blocked_until' => 0];
}

function saveLoginLimitState(array $state): void
{
    @file_put_contents(loginLimitFile(), json_encode($state), LOCK_EX);
}

function assertSameOrigin(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') $origin = $referer;
    }
    if ($origin === '') return;
    $originHost = parse_url($origin, PHP_URL_HOST);
    $originScheme = parse_url($origin, PHP_URL_SCHEME);
    $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $requestScheme = ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
    if (!$originHost || strtolower($originHost . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '')) !== $requestHost || $originScheme !== $requestScheme) {
        fail('Origen de solicitud no permitido.', 403);
    }
}

function currentUser(PDO $pdo): array
{
    static $cachedUser = null;
    if ($cachedUser !== null) return $cachedUser;

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $companyId = (int) ($_SESSION['company_id'] ?? 0);
    if (!$userId || !$companyId) fail('Inicia sesión para continuar.', 401);
    $statement = $pdo->prepare('SELECT id, company_id, branch_id, role, display_name, email FROM users WHERE id = ? AND company_id = ? AND active = 1 AND password_hash IS NOT NULL AND password_hash <> ""');
    $statement->execute([$userId, $companyId]);
    $user = $statement->fetch();
    if (!$user) {
        session_unset();
        session_destroy();
        fail('La sesión ya no está activa. Inicia sesión nuevamente.', 401);
    }
    $cachedUser = $user;
    return $user;
}

function requireAuthenticated(PDO $pdo): array
{
    return currentUser($pdo);
}

function requireRole(PDO $pdo, array $allowedRoles): array
{
    $user = currentUser($pdo);
    if (!in_array($user['role'], $allowedRoles, true)) {
        fail('No tienes permisos suficientes para realizar esta acción.', 403);
    }
    return $user;
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = route();

    if ($method === 'GET' && $path === '/auth/status') {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId) {
            $statement = $pdo->prepare('SELECT u.id,u.company_id,u.display_name,u.email,u.role,c.name AS company_name FROM users u JOIN companies c ON c.id=u.company_id WHERE u.id=? AND u.active=1 AND u.password_hash IS NOT NULL AND u.password_hash<>""');
            $statement->execute([$userId]);
            $user = $statement->fetch();
            if ($user) respond(['ok' => true, 'authenticated' => true, 'setupRequired' => false, 'user' => $user]);
            session_unset();
            session_destroy();
        }
        respond(['ok' => true, 'authenticated' => false, 'setupRequired' => authSetupRequired($pdo)]);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        assertSameOrigin();
        $now = time();
        $limit = loginLimitState();
        if ((int) $limit['blocked_until'] > $now) fail('Demasiados intentos. Espera un minuto e inténtalo otra vez.', 429);
        $input = body();
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Correo o contraseña incorrectos.', 401);
        $statement = $pdo->prepare('SELECT u.id,u.company_id,u.display_name,u.email,u.role,u.password_hash,c.name AS company_name FROM users u JOIN companies c ON c.id=u.company_id WHERE LOWER(u.email)=? AND u.active=1 AND u.password_hash IS NOT NULL AND u.password_hash<>"" ORDER BY u.company_id,u.id');
        $statement->execute([$email]);
        $user = null;
        foreach ($statement->fetchAll() as $candidate) if (password_verify($password, (string) $candidate['password_hash'])) { $user = $candidate; break; }
        if (!$user) {
            $limit['attempts'] = (int) $limit['attempts'] + 1;
            if ($limit['attempts'] >= 7) {
                $limit = ['attempts' => 0, 'blocked_until' => $now + 60];
            }
            saveLoginLimitState($limit);
            fail('Correo o contraseña incorrectos.', 401);
        }
        @unlink(loginLimitFile());
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['company_id'] = (int) $user['company_id'];
        unset($user['password_hash']);
        respond(['ok' => true, 'authenticated' => true, 'user' => $user]);
    }

    if ($method === 'POST' && $path === '/auth/setup') {
        assertSameOrigin();
        if (!authSetupRequired($pdo)) fail('El acceso inicial ya fue configurado. Inicia sesión.', 409);
        $input = body();
        $setupToken = config()['setup_token'];
        if ($setupToken === '') fail('El acceso inicial no está habilitado en el servidor. Define OFTAPLUS_SETUP_TOKEN.', 403);
        if (!hash_equals($setupToken, (string) ($input['setupToken'] ?? ''))) fail('Token de instalación incorrecto.', 403);
        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if ($name === '' || mb_strlen($name) > 160) fail('Ingresa un nombre válido.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) fail('Ingresa un correo válido.', 422);
        if (mb_strlen($password) < 10 || mb_strlen($password) > 128) fail('La contraseña debe tener entre 10 y 128 caracteres.', 422);
        $company = tenant($pdo);
        $companyId = (int) $company['id'];
        $branch = $pdo->prepare('SELECT id FROM branches WHERE company_id=? AND active=1 ORDER BY id LIMIT 1');
        $branch->execute([$companyId]);
        $branchId = $branch->fetchColumn();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');
            $lock->execute([$companyId]);
            if (!authSetupRequired($pdo)) fail('El acceso inicial ya fue configurado. Inicia sesión.', 409);
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $existing = $pdo->prepare('SELECT id FROM users WHERE company_id=? AND LOWER(email)=? LIMIT 1');
            $existing->execute([$companyId, $email]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                $pdo->prepare('UPDATE users SET display_name=?,role="owner",active=1,password_hash=? WHERE id=?')->execute([$name, $passwordHash, (int) $existingId]);
                $userId = (int) $existingId;
            } else {
                $pdo->prepare('INSERT INTO users (company_id,branch_id,display_name,email,role,active,password_hash) VALUES (?,?,?,?,"owner",1,?)')->execute([$companyId, $branchId ?: null, $name, $email, $passwordHash]);
                $userId = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['company_id'] = $companyId;
        respond(['ok' => true, 'authenticated' => true, 'user' => ['id' => $userId, 'company_id' => $companyId, 'display_name' => $name, 'email' => $email, 'role' => 'owner', 'company_name' => $company['name']]], 201);
    }

    if ($method === 'POST' && $path === '/auth/logout') {
        assertSameOrigin();
        session_unset();
        session_destroy();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'secure' => $requestIsHttps, 'samesite' => 'Strict']);
        respond(['ok' => true]);
    }

    if (!in_array($path, ['/health', '/auth/status', '/auth/login', '/auth/setup', '/auth/logout'], true)) requireAuthenticated($pdo);
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        assertSameOrigin();
        if (!in_array($path, ['/auth/login', '/auth/setup', '/auth/logout'], true)) {
            requireRole($pdo, ['owner', 'admin', 'seller', 'cashier']);
        }
    }

    if ($method === 'GET' && $path === '/health') {
        respond(['ok' => true, 'database' => true]);
    }

    // Indicadores del dashboard: documentos emitidos, boletas importadas (sin anuladas) y pagos registrados.
    if ($method === 'GET' && $path === '/dashboard/summary') {
        $company = tenant($pdo);
        $companyId = (int) $company['id'];
        $period = ($_GET['period'] ?? 'day') === 'month' ? 'month' : 'day';
        $to = date('Y-m-d');
        $from = $period === 'month' ? date('Y-m-01') : $to;
        $salesStatement = $pdo->prepare('SELECT d.issue_date AS day, COUNT(*) AS documents, COALESCE(SUM(d.total),0) AS sales FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.company_id = ? AND t.direction = "sale" AND d.status IN ("issued","partially_paid","paid","delivered") AND d.issue_date BETWEEN ? AND ? GROUP BY d.issue_date');
        $salesStatement->execute([$companyId, $from, $to]);
        $receiptStatement = $pdo->prepare('SELECT DATE(created_at) AS day, COUNT(*) AS documents, COALESCE(SUM(total),0) AS sales, COALESCE(SUM(paid_total),0) AS collected, SUM(paid_total > 0) AS payments FROM sales_receipts WHERE company_id = ? AND DATE(created_at) BETWEEN ? AND ? AND status NOT LIKE "%ANUL%" GROUP BY DATE(created_at)');
        $receiptStatement->execute([$companyId, $from, $to]);
        $paymentStatement = $pdo->prepare('SELECT COUNT(*) AS payments, COALESCE(SUM(amount),0) AS collected FROM payments WHERE company_id = ? AND DATE(paid_at) BETWEEN ? AND ?');
        $paymentStatement->execute([$companyId, $from, $to]);
        $paid = $paymentStatement->fetch();
        // Cobros importados desde el listado de Gesvision (tabla imported_payments): cuentan como cobrados en el período.
        $importedStatement = $pdo->prepare('SELECT COUNT(*) AS payments, COALESCE(SUM(amount),0) AS collected FROM imported_payments WHERE company_id = ? AND DATE(paid_at) BETWEEN ? AND ?');
        $importedStatement->execute([$companyId, $from, $to]);
        $imported = $importedStatement->fetch();

        $summary = ['sales' => 0.0, 'documents' => 0, 'activeDays' => 0, 'collected' => (float) $paid['collected'] + (float) $imported['collected'], 'payments' => (int) $paid['payments'] + (int) $imported['payments']];
        $receiptRows = $receiptStatement->fetchAll();
        $daysWithSales = [];
        foreach ([...$salesStatement->fetchAll(), ...$receiptRows] as $row) {
            $summary['sales'] += (float) $row['sales'];
            $summary['documents'] += (int) $row['documents'];
            if ((int) $row['documents'] > 0) $daysWithSales[$row['day']] = true;
        }
        $summary['activeDays'] = count($daysWithSales);
        foreach ($receiptRows as $row) {
            $summary['collected'] += (float) $row['collected'];
            $summary['payments'] += (int) $row['payments'];
        }
        respond(['ok' => true, 'period' => $period, 'from' => $from, 'to' => $to, 'summary' => $summary]);
    }

    if ($method === 'GET' && $path === '/reports/summary') {
        $company = tenant($pdo);
        $from = dateParam('from', date('Y-m-d'));
        $to = dateParam('to', date('Y-m-d'));
        if ($from > $to) fail('La fecha inicial no puede ser posterior a la final.', 422);
        $statement = $pdo->prepare('SELECT COUNT(*) AS documents, COALESCE(SUM(d.total),0) AS sales, COALESCE(SUM(d.paid_total),0) AS collected, COALESCE(SUM(d.total-d.paid_total),0) AS pending, COALESCE(AVG(d.total),0) AS average FROM documents d JOIN document_types t ON t.id=d.document_type_id WHERE d.company_id=? AND t.direction="sale" AND d.status IN ("issued","partially_paid","paid","delivered") AND d.issue_date BETWEEN ? AND ?');
        $statement->execute([(int)$company['id'],$from,$to]);
        $summary=$statement->fetch();
        $daily=$pdo->prepare('SELECT d.issue_date AS date, COUNT(*) AS documents, COALESCE(SUM(d.total),0) AS sales, COALESCE(SUM(d.paid_total),0) AS collected FROM documents d JOIN document_types t ON t.id=d.document_type_id WHERE d.company_id=? AND t.direction="sale" AND d.status IN ("issued","partially_paid","paid","delivered") AND d.issue_date BETWEEN ? AND ? GROUP BY d.issue_date ORDER BY d.issue_date');
        $daily->execute([(int)$company['id'],$from,$to]);
        $top=$pdo->prepare('SELECT dl.product_id, COALESCE(p.name,dl.concept) AS name, SUM(dl.quantity) AS units, SUM(dl.total_amount) AS total FROM document_lines dl JOIN documents d ON d.id=dl.document_id JOIN document_types t ON t.id=d.document_type_id LEFT JOIN products p ON p.id=dl.product_id WHERE d.company_id=? AND t.direction="sale" AND d.status IN ("issued","partially_paid","paid","delivered") AND d.issue_date BETWEEN ? AND ? GROUP BY dl.product_id, name ORDER BY total DESC LIMIT 10');
        $top->execute([(int)$company['id'],$from,$to]);
        respond(['ok'=>true,'from'=>$from,'to'=>$to,'summary'=>$summary,'daily'=>$daily->fetchAll(),'topProducts'=>$top->fetchAll()]);
    }

    if ($method === 'GET' && $path === '/crm/templates') {
        $company=tenant($pdo);$statement=$pdo->prepare('SELECT id,name,body,active FROM crm_templates WHERE company_id=? ORDER BY name,id');$statement->execute([(int)$company['id']]);respond(['ok'=>true,'items'=>$statement->fetchAll()]);
    }
    if ($method === 'POST' && $path === '/crm/templates') {
        $company=tenant($pdo);$input=body();$name=trim((string)($input['name']??''));$content=trim((string)($input['body']??''));if($name===''||mb_strlen($name)>120||$content==='')fail('Indica un nombre y contenido para la plantilla.',422);
        $pdo->prepare('INSERT INTO crm_templates (company_id,name,body,active) VALUES (?,?,?,1)')->execute([(int)$company['id'],$name,$content]);respond(['ok'=>true,'item'=>['id'=>(int)$pdo->lastInsertId(),'name'=>$name,'body'=>$content,'active'=>1]],201);
    }
    if ($method === 'GET' && $path === '/crm/campaigns') {
        $company=tenant($pdo);$statement=$pdo->prepare('SELECT c.id,c.name,c.status,c.created_at,t.name AS template_name,COUNT(m.id) AS recipients FROM crm_campaigns c LEFT JOIN crm_templates t ON t.id=c.template_id LEFT JOIN crm_messages m ON m.campaign_id=c.id WHERE c.company_id=? GROUP BY c.id,c.name,c.status,c.created_at,t.name ORDER BY c.created_at DESC,c.id DESC LIMIT 200');$statement->execute([(int)$company['id']]);respond(['ok'=>true,'items'=>$statement->fetchAll()]);
    }
    if ($method === 'POST' && $path === '/crm/campaigns') {
        $company=tenant($pdo);$input=body();$name=trim((string)($input['name']??''));$templateId=(int)($input['templateId']??0);$segment=(string)($input['segment']??'all');if($name===''||mb_strlen($name)>160)fail('El nombre de campaña es obligatorio (máximo 160 caracteres).',422);if(!in_array($segment,['all','with_phone'],true))fail('Segmento no válido.',422);
        $template=null;if($templateId){$check=$pdo->prepare('SELECT id FROM crm_templates WHERE id=? AND company_id=? AND active=1');$check->execute([$templateId,(int)$company['id']]);if(!$check->fetchColumn())fail('Plantilla no válida.',422);$templateId=(int)$templateId;}
        $customers=$pdo->prepare('SELECT id,phone FROM customers WHERE company_id=? AND active=1 AND phone IS NOT NULL AND phone<>"" ORDER BY id');$customers->execute([(int)$company['id']]);$recipients=$customers->fetchAll();
        $pdo->beginTransaction();try{$pdo->prepare('INSERT INTO crm_campaigns (company_id,template_id,name,status) VALUES (?,?,?,"draft")')->execute([(int)$company['id'],$templateId?:null,$name]);$campaignId=(int)$pdo->lastInsertId();$message=$pdo->prepare('INSERT INTO crm_messages (campaign_id,customer_id,destination_masked,status) VALUES (?,?,?,"queued")');foreach($recipients as $recipient){$phone=(string)$recipient['phone'];$masked=strlen($phone)>4?str_repeat('•',max(0,strlen($phone)-4)).substr($phone,-4):$phone;$message->execute([$campaignId,(int)$recipient['id'],$masked]);}$pdo->commit();respond(['ok'=>true,'campaign'=>['id'=>$campaignId,'name'=>$name,'status'=>'draft','recipients'=>count($recipients)]],201);}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    if ($method === 'GET' && $path === '/products') {
        $company = tenant($pdo);
        $companyId = (int) $company['id'];
        $q = trim((string) ($_GET['q'] ?? ''));
        $kind = (string) ($_GET['kind'] ?? 'products');
        $priceList = trim((string) ($_GET['priceList'] ?? 'general')) ?: 'general';
        if (!in_array($kind, ['products', 'models'], true)) fail('Tipo de catálogo no válido.', 422);
        $limit = min(max((int) ($_GET['limit'] ?? 20), 1), $kind === 'models' ? 2000 : 50);
        $typeFilter = $kind === 'models' ? 'p.product_type = "model"' : 'p.product_type IN ("product", "service")';
        $statement = $pdo->prepare('SELECT p.id, p.sku, p.name, p.description, p.category, p.product_type, b.name AS brand_name, JSON_UNQUOTE(JSON_EXTRACT(p.attributes_json, "$.modelSku")) AS model_sku, p.tax_rate, p.cost_amount, COALESCE((SELECT amount FROM product_prices pp WHERE pp.product_id = p.id AND pp.price_list = ? ORDER BY pp.valid_from DESC, pp.id DESC LIMIT 1), (SELECT amount FROM product_prices pp WHERE pp.product_id = p.id ORDER BY pp.valid_from DESC, pp.id DESC LIMIT 1), 0) AS price_amount, COALESCE((SELECT SUM(CASE sm.movement_type WHEN "out" THEN -ABS(sm.quantity) ELSE sm.quantity END) FROM stock_movements sm WHERE sm.product_id = p.id AND sm.company_id = p.company_id), 0) AS stock_quantity FROM products p LEFT JOIN brands b ON b.id = p.brand_id WHERE p.company_id = ? AND p.active = 1 AND ' . $typeFilter . ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.description LIKE ?) ORDER BY p.name LIMIT ' . $limit);
        $like = '%' . $q . '%';
        $statement->execute([$priceList, $companyId, $like, $like, $like]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $path === '/locations') {
        $company = tenant($pdo);
        $statement = $pdo->prepare('SELECT sl.id, sl.name, sl.branch_id, b.name AS branch FROM stock_locations sl LEFT JOIN branches b ON b.id = sl.branch_id WHERE sl.company_id = ? AND sl.active = 1 ORDER BY sl.name, sl.id');
        $statement->execute([(int) $company['id']]);
        $items = $statement->fetchAll();
        if (!$items) {
            $items[] = ['id' => salesStockLocation($pdo, (int) $company['id'], null), 'name' => 'Bodega principal', 'branch_id' => null, 'branch' => null];
        }
        respond(['ok' => true, 'items' => $items]);
    }

    if ($method === 'POST' && $path === '/locations') {
        $company = tenant($pdo);
        $input = body();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) fail('El nombre de la ubicación debe tener entre 1 y 120 caracteres.', 422);
        $branchId = !empty($input['branchId']) ? (int) $input['branchId'] : null;
        if ($branchId) {
            $branchCheck = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND company_id = ? AND active = 1');
            $branchCheck->execute([$branchId, (int) $company['id']]);
            if (!$branchCheck->fetchColumn()) fail('Sucursal no válida.', 422);
        }
        $duplicate = $pdo->prepare('SELECT id FROM stock_locations WHERE company_id = ? AND active = 1 AND LOWER(name) = LOWER(?) LIMIT 1');
        $duplicate->execute([(int) $company['id'], $name]);
        if ($duplicate->fetchColumn()) fail('Ya existe una ubicación con ese nombre.', 409);
        $pdo->prepare('INSERT INTO stock_locations (company_id, branch_id, name, active) VALUES (?, ?, ?, 1)')->execute([(int) $company['id'], $branchId, $name]);
        respond(['ok' => true, 'location' => ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'branch_id' => $branchId]], 201);
    }

    if ($method === 'GET' && $path === '/sale-options') {
        $company = tenant($pdo);
        $users = $pdo->prepare('SELECT id, display_name AS name FROM users WHERE company_id = ? AND active = 1 AND role IN ("owner", "admin", "seller", "cashier") ORDER BY display_name');
        $users->execute([(int) $company['id']]);
        $terms = $pdo->prepare('SELECT id, name, days FROM payment_terms WHERE company_id = ? AND active = 1 ORDER BY days, name');
        $terms->execute([(int) $company['id']]);
        $prices = $pdo->prepare('SELECT DISTINCT pp.price_list AS code, pp.price_list AS name FROM product_prices pp JOIN products p ON p.id = pp.product_id WHERE p.company_id = ? ORDER BY pp.price_list');
        $prices->execute([(int) $company['id']]);
        $docTypes = $pdo->prepare('SELECT id, code, name, affects_stock, affects_receivables FROM document_types WHERE company_id = ? AND direction = "sale" AND active = 1 ORDER BY id');
        $docTypes->execute([(int) $company['id']]);
        respond(['ok' => true, 'sellers' => $users->fetchAll(), 'paymentTerms' => $terms->fetchAll(), 'priceLists' => $prices->fetchAll(), 'documentTypes' => $docTypes->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/payment-terms') {
        $company=tenant($pdo); $input=body();
        $name=trim((string)($input['name']??'')); $days=(int)($input['days']??-1);
        if($name===''||mb_strlen($name)>100)fail('El nombre de la condición debe tener entre 1 y 100 caracteres.',422);
        if($days<0||$days>3650)fail('Los días deben estar entre 0 y 3650.',422);
        $exists=$pdo->prepare('SELECT id FROM payment_terms WHERE company_id=? AND LOWER(name)=LOWER(?) LIMIT 1');
        $exists->execute([(int)$company['id'],$name]);
        if($exists->fetchColumn())fail('Ya existe una condición de pago con ese nombre.',409);
        $pdo->prepare('INSERT INTO payment_terms (company_id,name,days,active) VALUES (?,?,?,1)')->execute([(int)$company['id'],$name,$days]);
        respond(['ok'=>true,'paymentTerm'=>['id'=>(int)$pdo->lastInsertId(),'name'=>$name,'days'=>$days]],201);
    }

    if ($method === 'POST' && $path === '/sellers') {
        $company=tenant($pdo); $input=body();
        $name=trim((string)($input['name']??''));
        if($name===''||mb_strlen($name)>160)fail('El nombre del agente debe tener entre 1 y 160 caracteres.',422);
        $exists=$pdo->prepare('SELECT id FROM users WHERE company_id=? AND active=1 AND role IN ("owner","admin","seller","cashier") AND LOWER(display_name)=LOWER(?) LIMIT 1');
        $exists->execute([(int)$company['id'],$name]);
        if($exists->fetchColumn())fail('Ya existe un agente con ese nombre.',409);
        $branch=$pdo->prepare('SELECT id FROM branches WHERE company_id=? AND active=1 ORDER BY id LIMIT 1');$branch->execute([(int)$company['id']]);
        $branchRaw=$branch->fetchColumn();$branchId=$branchRaw===false?null:(int)$branchRaw;
        $pdo->prepare('INSERT INTO users (company_id,branch_id,display_name,role,active) VALUES (?,?,?,"seller",1)')->execute([(int)$company['id'],$branchId,$name]);
        respond(['ok'=>true,'seller'=>['id'=>(int)$pdo->lastInsertId(),'name'=>$name]],201);
    }

    if ($method === 'GET' && $path === '/company') {
        $company=tenant($pdo);
        $branch=$pdo->prepare('SELECT id,name,address FROM branches WHERE company_id=? AND active=1 ORDER BY id LIMIT 1');
        $branch->execute([(int)$company['id']]);
        respond(['ok'=>true,'company'=>['id'=>(int)$company['id'],'name'=>$company['name'],'legal_name'=>$company['legal_name'],'tax_id'=>$company['tax_id'],'currency'=>$company['currency'],'timezone'=>$company['timezone']],'branch'=>$branch->fetch()?:null]);
    }

    if ($method === 'PUT' && $path === '/company') {
        $company=tenant($pdo); $input=body();
        $name=trim((string)($input['name']??'')); $legalName=trim((string)($input['legalName']??'')); $taxId=trim((string)($input['taxId']??'')); $address=trim((string)($input['branchAddress']??''));
        if($name===''||mb_strlen($name)>160)fail('El nombre comercial debe tener entre 1 y 160 caracteres.',422);
        if(mb_strlen($legalName)>200||mb_strlen($address)>255)fail('Revisa la longitud de los datos del emisor.',422);
        if($taxId!==''&&!validRut($taxId))fail('El RUT del emisor no es válido.',422);
        $pdo->prepare('UPDATE companies SET name=?,legal_name=?,tax_id=? WHERE id=?')->execute([$name,$legalName?:null,$taxId?:null,(int)$company['id']]);
        $branchId=(int)($input['branchId']??0);
        if($branchId){$pdo->prepare('UPDATE branches SET address=? WHERE id=? AND company_id=?')->execute([$address?:null,$branchId,(int)$company['id']]);}
        respond(['ok'=>true,'company'=>['name'=>$name,'legal_name'=>$legalName?:null,'tax_id'=>$taxId?:null]]);
    }

    if (preg_match('#^/customers/(\d+)/clinical-data$#', $path, $match) && in_array($method, ['GET','PUT'], true)) {
        $company=tenant($pdo);$customerId=(int)$match[1];$customerCheck=$pdo->prepare('SELECT id FROM customers WHERE id=? AND company_id=? AND active=1');$customerCheck->execute([$customerId,(int)$company['id']]);if(!$customerCheck->fetchColumn())fail('Cliente no encontrado.',404);
        if($method==='GET'){$statement=$pdo->prepare('SELECT data_json FROM customer_clinical_data WHERE customer_id=? ORDER BY id DESC LIMIT 1');$statement->execute([$customerId]);$value=$statement->fetchColumn();respond(['ok'=>true,'data'=>$value===false?[]:(json_decode((string)$value,true)?:[])]);}
        $input=body();$profile=$input['opticalExam']??null;if(!is_array($profile))fail('Los datos del examen óptico no son válidos.',422);$normalized=[];foreach(['right','left'] as $eye){$normalized[$eye]=[];foreach(['sphere','cylinder','axis','addition'] as $field){$value=trim((string)($profile[$eye][$field]??''));if(mb_strlen($value)>20)fail('El valor de la receta óptica es demasiado largo.',422);$normalized[$eye][$field]=$value;}}$normalized['pupillaryDistance']=mb_substr(trim((string)($profile['pupillaryDistance']??'')),0,20);$normalized['notes']=mb_substr(trim((string)($profile['notes']??'')),0,1000);$json=json_encode(['opticalExam'=>$normalized],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$existing=$pdo->prepare('SELECT id FROM customer_clinical_data WHERE customer_id=? ORDER BY id DESC LIMIT 1');$existing->execute([$customerId]);$rowId=$existing->fetchColumn();if($rowId!==false)$pdo->prepare('UPDATE customer_clinical_data SET data_json=? WHERE id=?')->execute([$json,(int)$rowId]);else $pdo->prepare('INSERT INTO customer_clinical_data (customer_id,data_json) VALUES (?,?)')->execute([$customerId,$json]);respond(['ok'=>true,'data'=>json_decode($json,true)]);
    }

    if ($method === 'GET' && $path === '/customers') {
        $company = tenant($pdo);
        $companyId = (int) $company['id'];
        $q = trim((string) ($_GET['q'] ?? ''));
        $limit = min(max((int) ($_GET['limit'] ?? 20), 1), 50);
        $offset = min(max((int) ($_GET['offset'] ?? 0), 0), 1000000);
        $where = 'FROM customers WHERE company_id = ? AND active = 1 AND (name LIKE ? OR code LIKE ? OR email LIKE ? OR tax_id LIKE ? OR phone LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$companyId, $like, $like, $like, $like, $like];
        $countStatement = $pdo->prepare('SELECT COUNT(*) ' . $where);
        $countStatement->execute($params);
        $statement = $pdo->prepare('SELECT id, code, name, tax_id, email, phone, address ' . $where . ' ORDER BY name LIMIT ' . $limit . ' OFFSET ' . $offset);
        $statement->execute($params);
        respond(['ok' => true, 'items' => $statement->fetchAll(), 'total' => (int) $countStatement->fetchColumn()]);
    }

    if ($method === 'POST' && $path === '/customers') {
        $company = tenant($pdo);
        $data = customerInput(body());
        $nextStatement = $pdo->prepare('SELECT COALESCE(MAX(CAST(code AS UNSIGNED)), 10000000000) + 1 FROM customers WHERE company_id = ? AND code REGEXP "^[0-9]+$"');
        $nextStatement->execute([(int) $company['id']]);
        $code = (string) $nextStatement->fetchColumn();
        $pdo->prepare('INSERT INTO customers (company_id, code, name, tax_id, email, phone, address, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int) $company['id'], $code, $data['name'], $data['tax_id'], $data['email'], $data['phone'], $data['address'], $data['active']]);
        respond(['ok' => true, 'customer' => ['id' => (int) $pdo->lastInsertId(), 'code' => $code] + $data], 201);
    }

    if ($method === 'PUT' && preg_match('#^/customers/(\d+)$#', $path, $match)) {
        $company = tenant($pdo);
        $customerId = (int) $match[1];
        $data = customerInput(body());
        $existsStatement = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND company_id = ?');
        $existsStatement->execute([$customerId, (int) $company['id']]);
        if (!$existsStatement->fetchColumn()) fail('Cliente no encontrado.', 404);
        $pdo->prepare('UPDATE customers SET name = ?, tax_id = ?, email = ?, phone = ?, address = ?, active = ? WHERE id = ? AND company_id = ?')
            ->execute([$data['name'], $data['tax_id'], $data['email'], $data['phone'], $data['address'], $data['active'], $customerId, (int) $company['id']]);
        respond(['ok' => true, 'customer' => ['id' => $customerId] + $data]);
    }

    if ($method === 'GET' && $path === '/suppliers') {
        $company = tenant($pdo);
        $like = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
        $statement = $pdo->prepare('SELECT id, code, name, tax_id, email, phone, address, active FROM suppliers WHERE company_id = ? AND (name LIKE ? OR code LIKE ? OR tax_id LIKE ?) ORDER BY name LIMIT 200');
        $statement->execute([(int) $company['id'], $like, $like, $like]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/suppliers') {
        $company = tenant($pdo);
        $data = customerInput(body());
        $nextStatement = $pdo->prepare('SELECT COALESCE(MAX(CAST(code AS UNSIGNED)), 4000000) + 1 FROM suppliers WHERE company_id = ? AND code REGEXP "^[0-9]+$"');
        $nextStatement->execute([(int) $company['id']]);
        $code = (string) $nextStatement->fetchColumn();
        $pdo->prepare('INSERT INTO suppliers (company_id, code, name, tax_id, email, phone, address, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int) $company['id'], $code, $data['name'], $data['tax_id'], $data['email'], $data['phone'], $data['address'], $data['active']]);
        respond(['ok' => true, 'supplier' => ['id' => (int) $pdo->lastInsertId(), 'code' => $code] + $data], 201);
    }

    if ($method === 'PUT' && preg_match('#^/suppliers/(\d+)$#', $path, $match)) {
        $company = tenant($pdo);
        $supplierId = (int) $match[1];
        $data = customerInput(body());
        $existsStatement = $pdo->prepare('SELECT id FROM suppliers WHERE id = ? AND company_id = ?');
        $existsStatement->execute([$supplierId, (int) $company['id']]);
        if (!$existsStatement->fetchColumn()) fail('Proveedor no encontrado.', 404);
        $pdo->prepare('UPDATE suppliers SET name = ?, tax_id = ?, email = ?, phone = ?, address = ?, active = ? WHERE id = ? AND company_id = ?')
            ->execute([$data['name'], $data['tax_id'], $data['email'], $data['phone'], $data['address'], $data['active'], $supplierId, (int) $company['id']]);
        respond(['ok' => true, 'supplier' => ['id' => $supplierId] + $data]);
    }

    if ($method === 'GET' && $path === '/purchase-invoices') {
        $company = tenant($pdo);
        $from = dateParam('from', date('Y-m-d', strtotime('-6 months')));
        $to = dateParam('to', date('Y-m-d'));
        if ($from > $to) fail('La fecha inicial no puede ser posterior a la final.', 422);
        $statement = $pdo->prepare('SELECT d.id, d.number, d.status, d.supplier_name, d.subtotal, d.tax_total, d.total, d.paid_total, d.issue_date, JSON_UNQUOTE(JSON_EXTRACT(d.metadata_json, "$.externalRef")) AS external_ref FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE d.company_id = ? AND t.code = "FAR" AND d.issue_date BETWEEN ? AND ? ORDER BY d.issue_date DESC, d.id DESC LIMIT 200');
        $statement->execute([(int) $company['id'], $from, $to]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/purchase-invoices') {
        $input = body();
        $company = tenant($pdo);
        $supplierStatement = $pdo->prepare('SELECT name FROM suppliers WHERE id = ? AND company_id = ? AND active = 1');
        $supplierStatement->execute([(int) ($input['supplierId'] ?? 0), (int) $company['id']]);
        $supplierName = $supplierStatement->fetchColumn();
        if (!$supplierName) fail('Proveedor no válido.', 422);
        $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
        $prepared = [];
        $net = $tax = 0.0;
        if ($lines) {
            if (count($lines) > 100) fail('La factura no puede superar 100 artículos.', 422);
            $locationId = (int) ($input['locationId'] ?? 0);
            $locationCheck = $pdo->prepare('SELECT id FROM stock_locations WHERE id = ? AND company_id = ? AND active = 1');
            $locationCheck->execute([$locationId, (int) $company['id']]);
            if (!$locationCheck->fetchColumn()) fail('Selecciona una ubicación de recepción válida.', 422);
            $productCheck = $pdo->prepare('SELECT id, name, sku, tax_rate FROM products WHERE id = ? AND company_id = ? AND active = 1 AND product_type = "product"');
            foreach ($lines as $line) {
                $productId = (int) ($line['productId'] ?? 0);
                $quantity = (float) ($line['quantity'] ?? 0);
                $unitCost = round((float) ($line['unitCost'] ?? 0), 2);
                if ($quantity <= 0 || $quantity > 100000 || $unitCost < 0) fail('Revisa las cantidades y costos de la factura.', 422);
                $productCheck->execute([$productId, (int) $company['id']]);
                $product = $productCheck->fetch();
                if (!$product) fail('Uno de los productos no está disponible en el catálogo.', 422);
                $lineNet = round($quantity * $unitCost, 2);
                $lineTax = round($lineNet * (float) $product['tax_rate'] / 100, 2);
                $prepared[] = ['product' => $product, 'quantity' => $quantity, 'unitCost' => $unitCost, 'net' => $lineNet, 'tax' => $lineTax];
                $net += $lineNet;
                $tax += $lineTax;
            }
        } else {
            $net = round((float) ($input['netAmount'] ?? 0), 2);
            if ($net <= 0) fail('El neto debe ser mayor que cero.', 422);
            $taxRate = max((float) ($input['taxRate'] ?? 19), 0);
            $tax = round($net * $taxRate / 100, 2);
        }
        if ($net <= 0) fail('El neto debe ser mayor que cero.', 422);
        $issueDate = trim((string) ($input['issueDate'] ?? date('Y-m-d')));
        $date = DateTime::createFromFormat('!Y-m-d', $issueDate);
        if (!$date || $date->format('Y-m-d') !== $issueDate) fail('Fecha inválida. Usa AAAA-MM-DD.', 422);
        $typeStatement = $pdo->prepare('SELECT id FROM document_types WHERE company_id = ? AND code = "FAR" LIMIT 1');
        $typeStatement->execute([(int) $company['id']]);
        $typeId = (int) $typeStatement->fetchColumn();
        if (!$typeId) fail('Tipo de documento no configurado.', 422);
        $metadata = json_encode(['externalRef' => trim((string) ($input['externalRef'] ?? ''))], JSON_UNESCAPED_UNICODE);
        $pdo->beginTransaction();
        try {
            $branchStatement = $pdo->prepare('SELECT branch_id FROM stock_locations WHERE id = ? AND company_id = ?');
            $branchStatement->execute([(int) ($input['locationId'] ?? 0), (int) $company['id']]);
            $branchId = $branchStatement->fetchColumn();
            $pdo->prepare('INSERT INTO documents (company_id, branch_id, document_type_id, status, supplier_name, issue_date, subtotal, tax_total, total, notes, metadata_json) VALUES (?, ?, ?, "issued", ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) $company['id'], $branchId === false ? null : $branchId, $typeId, $supplierName, $issueDate, $net, $tax, $net + $tax, (string) ($input['notes'] ?? ''), $metadata]);
            $documentId = (int) $pdo->lastInsertId();
            if ($prepared) {
                $lineInsert = $pdo->prepare('INSERT INTO document_lines (document_id, product_id, line_number, concept, reference, quantity, unit_price, tax_rate, gross_amount, net_amount, tax_amount, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $movement = $pdo->prepare('INSERT INTO stock_movements (company_id, product_id, location_id, document_id, quantity, movement_type, occurred_at) VALUES (?, ?, ?, ?, ?, "in", NOW())');
                foreach ($prepared as $index => $line) {
                    $product = $line['product'];
                    $gross = round($line['net'] + $line['tax'], 2);
                    $lineInsert->execute([$documentId, (int) $product['id'], $index + 1, $product['name'], $product['sku'], $line['quantity'], $line['unitCost'], (float) $product['tax_rate'], $line['net'], $line['net'], $line['tax'], $gross]);
                    $movement->execute([(int) $company['id'], (int) $product['id'], (int) $input['locationId'], $documentId, $line['quantity']]);
                }
            }
            $pdo->commit();
            respond(['ok' => true, 'invoice' => ['id' => $documentId, 'supplier' => $supplierName, 'net' => round($net, 2), 'tax' => round($tax, 2), 'total' => round($net + $tax, 2), 'receivedLines' => count($prepared)]], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'GET' && $path === '/transfers') {
        $company = tenant($pdo);
        $statement = $pdo->prepare('SELECT st.id, o.name AS origin, de.name AS destination, st.transfer_type, st.status, st.created_at, (SELECT COALESCE(SUM(quantity), 0) FROM stock_transfer_lines l WHERE l.transfer_id = st.id) AS quantity FROM stock_transfers st JOIN stock_locations o ON o.id = st.origin_location_id JOIN stock_locations de ON de.id = st.destination_location_id WHERE st.company_id = ? ORDER BY st.created_at DESC LIMIT 200');
        $statement->execute([(int) $company['id']]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && preg_match('#^/transfers/(\d+)$#', $path, $match)) {
        $company = tenant($pdo);
        $statement = $pdo->prepare('SELECT st.id, st.transfer_type, st.status, st.notes, st.created_at, origin.id AS origin_id, origin.name AS origin, destination.id AS destination_id, destination.name AS destination FROM stock_transfers st JOIN stock_locations origin ON origin.id = st.origin_location_id JOIN stock_locations destination ON destination.id = st.destination_location_id WHERE st.id = ? AND st.company_id = ?');
        $statement->execute([(int) $match[1], (int) $company['id']]);
        $transfer = $statement->fetch();
        if (!$transfer) fail('Traspaso no encontrado.', 404);
        $lines = $pdo->prepare('SELECT p.id AS product_id, p.sku, p.name, l.quantity FROM stock_transfer_lines l JOIN products p ON p.id = l.product_id WHERE l.transfer_id = ? ORDER BY p.name');
        $lines->execute([(int) $match[1]]);
        respond(['ok' => true, 'transfer' => $transfer, 'items' => $lines->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/transfers') {
        $input = body();
        $company = tenant($pdo);
        $origin = (int) ($input['originLocationId'] ?? 0);
        $destination = (int) ($input['destinationLocationId'] ?? 0);
        if ($origin === $destination) fail('El origen y el destino deben ser distintos.', 422);
        $locationStatement = $pdo->prepare('SELECT COUNT(*) FROM stock_locations WHERE company_id = ? AND id IN (?, ?) AND active = 1');
        $locationStatement->execute([(int) $company['id'], $origin, $destination]);
        if ((int) $locationStatement->fetchColumn() !== 2) fail('Ubicación no válida.', 422);
        $lines = is_array($input['lines'] ?? null) ? $input['lines'] : [];
        if (!$lines) fail('El traspaso no tiene productos.', 422);
        $type = ($input['type'] ?? 'direct') === 'request' ? 'request' : 'direct';
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO stock_transfers (company_id, origin_location_id, destination_location_id, transfer_type, status, notes) VALUES (?, ?, ?, ?, "requested", ?)')
                ->execute([(int) $company['id'], $origin, $destination, $type, $input['notes'] ?? null]);
            $transferId = (int) $pdo->lastInsertId();
            $lineStatement = $pdo->prepare('INSERT INTO stock_transfer_lines (transfer_id, product_id, quantity) SELECT ?, id, ? FROM products WHERE id = ? AND company_id = ?');
            foreach ($lines as $line) {
                $quantity = (float) ($line['quantity'] ?? 0);
                if ($quantity <= 0) fail('Las cantidades deben ser mayores que cero.', 422);
                $lineStatement->execute([$transferId, $quantity, (int) ($line['productId'] ?? 0), (int) $company['id']]);
                if ($lineStatement->rowCount() === 0) fail('Producto no válido.', 422);
            }
            $pdo->commit();
            respond(['ok' => true, 'transfer' => ['id' => $transferId, 'status' => 'requested']], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'POST' && preg_match('#^/transfers/(\d+)/(send|receive)$#', $path, $match)) {
        $company = tenant($pdo);
        $transferId = (int) $match[1];
        $action = $match[2];
        $pdo->beginTransaction();
        try {
            $transferStatement = $pdo->prepare('SELECT * FROM stock_transfers WHERE id = ? AND company_id = ? FOR UPDATE');
            $transferStatement->execute([$transferId, (int) $company['id']]);
            $transfer = $transferStatement->fetch();
            if (!$transfer) fail('Traspaso no encontrado.', 404);
            $expected = $action === 'send' ? 'requested' : 'sent';
            if ($transfer['status'] !== $expected) fail('El traspaso no está en el estado correcto para esta acción.', 409);
            $location = $action === 'send' ? (int) $transfer['origin_location_id'] : (int) $transfer['destination_location_id'];
            $sign = $action === 'send' ? -1 : 1;
            if ($action === 'send') {
                $lines = $pdo->prepare('SELECT product_id, SUM(quantity) AS quantity FROM stock_transfer_lines WHERE transfer_id = ? GROUP BY product_id');
                $lines->execute([$transferId]);
                $balance = $pdo->prepare('SELECT COALESCE(SUM(CASE movement_type WHEN "out" THEN -ABS(quantity) ELSE quantity END), 0) FROM stock_movements WHERE company_id = ? AND location_id = ? AND product_id = ?');
                foreach ($lines->fetchAll() as $line) {
                    $balance->execute([(int) $company['id'], $location, (int) $line['product_id']]);
                    if ((float) $balance->fetchColumn() < (float) $line['quantity']) fail('Stock insuficiente para completar el traspaso del producto ' . (int) $line['product_id'] . '.', 422);
                }
            }
            $pdo->prepare('INSERT INTO stock_movements (company_id, product_id, location_id, quantity, movement_type) SELECT ?, product_id, ?, quantity * ?, "transfer" FROM stock_transfer_lines WHERE transfer_id = ?')
                ->execute([(int) $company['id'], $location, $sign, $transferId]);
            $status = $action === 'send' ? 'sent' : 'received';
            $pdo->prepare('UPDATE stock_transfers SET status = ? WHERE id = ?')->execute([$status, $transferId]);
            $pdo->commit();
            respond(['ok' => true, 'transfer' => ['id' => $transferId, 'status' => $status]]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'GET' && $path === '/inventories') {
        $company = tenant($pdo);
        $statement = $pdo->prepare('SELECT ic.id, ic.notes, sl.name AS location, ic.status, ic.created_at, ic.closed_at, COALESCE(SUM(l.counted_quantity), 0) AS counted_quantity, COALESCE(SUM(l.counted_quantity = l.expected_quantity), 0) AS matched_lines, COUNT(l.id) AS line_count FROM inventory_counts ic JOIN stock_locations sl ON sl.id = ic.location_id LEFT JOIN inventory_count_lines l ON l.inventory_count_id = ic.id WHERE ic.company_id = ? GROUP BY ic.id ORDER BY ic.created_at DESC LIMIT 200');
        $statement->execute([(int) $company['id']]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && preg_match('#^/inventories/(\d+)$#', $path, $match)) {
        $company = tenant($pdo);
        $countId = (int) $match[1];
        $head = $pdo->prepare('SELECT ic.id, ic.notes, ic.status, ic.created_at, ic.closed_at, sl.name AS location FROM inventory_counts ic JOIN stock_locations sl ON sl.id = ic.location_id WHERE ic.id = ? AND ic.company_id = ?');
        $head->execute([$countId, (int) $company['id']]);
        $inventory = $head->fetch();
        if (!$inventory) fail('Inventario no encontrado.', 404);
        $lines = $pdo->prepare('SELECT p.id AS product_id, p.sku, p.name, l.expected_quantity, l.counted_quantity FROM inventory_count_lines l JOIN products p ON p.id = l.product_id WHERE l.inventory_count_id = ? ORDER BY p.name');
        $lines->execute([$countId]);
        respond(['ok' => true, 'inventory' => $inventory, 'items' => $lines->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/inventories') {
        $input = body();
        $company = tenant($pdo);
        $locationId = (int) ($input['locationId'] ?? 0);
        $locationStatement = $pdo->prepare('SELECT id FROM stock_locations WHERE id = ? AND company_id = ? AND active = 1');
        $locationStatement->execute([$locationId, (int) $company['id']]);
        if (!$locationStatement->fetchColumn()) fail('Ubicación no válida.', 422);
        $lines = is_array($input['lines'] ?? null) ? $input['lines'] : [];
        if (!$lines) fail('El inventario no tiene productos.', 422);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO inventory_counts (company_id, location_id, notes) VALUES (?, ?, ?)')->execute([(int) $company['id'], $locationId, $input['notes'] ?? null]);
            $countId = (int) $pdo->lastInsertId();
            $expectedStatement = $pdo->prepare('SELECT COALESCE(SUM(CASE movement_type WHEN "out" THEN -ABS(quantity) ELSE quantity END), 0) FROM stock_movements WHERE company_id = ? AND location_id = ? AND product_id = ?');
            $lineStatement = $pdo->prepare('INSERT INTO inventory_count_lines (inventory_count_id, product_id, expected_quantity, counted_quantity) SELECT ?, id, ?, ? FROM products WHERE id = ? AND company_id = ?');
            foreach ($lines as $line) {
                $productId = (int) ($line['productId'] ?? 0);
                $counted = (float) ($line['countedQuantity'] ?? -1);
                if ($counted < 0) fail('Las cantidades contadas no pueden ser negativas.', 422);
                $expectedStatement->execute([(int) $company['id'], $locationId, $productId]);
                $lineStatement->execute([$countId, (float) $expectedStatement->fetchColumn(), $counted, $productId, (int) $company['id']]);
                if ($lineStatement->rowCount() === 0) fail('Producto no válido.', 422);
            }
            $pdo->commit();
            respond(['ok' => true, 'inventory' => ['id' => $countId, 'status' => 'open']], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'POST' && preg_match('#^/inventories/(\d+)/close$#', $path, $match)) {
        $company = tenant($pdo);
        $countId = (int) $match[1];
        $pdo->beginTransaction();
        try {
            $countStatement = $pdo->prepare('SELECT id, location_id, status FROM inventory_counts WHERE id = ? AND company_id = ? FOR UPDATE');
            $countStatement->execute([$countId, (int) $company['id']]);
            $count = $countStatement->fetch();
            if (!$count) fail('Inventario no encontrado.', 404);
            if ($count['status'] !== 'open') fail('El inventario ya está cerrado.', 409);
            $pdo->prepare('INSERT INTO stock_movements (company_id, product_id, location_id, quantity, movement_type) SELECT ?, product_id, ?, counted_quantity - expected_quantity, "adjustment" FROM inventory_count_lines WHERE inventory_count_id = ? AND counted_quantity <> expected_quantity')
                ->execute([(int) $company['id'], (int) $count['location_id'], $countId]);
            $pdo->prepare('UPDATE inventory_counts SET status = "closed", closed_at = NOW() WHERE id = ?')->execute([$countId]);
            $pdo->commit();
            respond(['ok' => true, 'inventory' => ['id' => $countId, 'status' => 'closed']]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'POST' && $path === '/documents/charge') {
        $input = body();
        $company = tenant($pdo);
        $companyId = (int) $company['id'];
        $typeCode = strtoupper((string) ($input['type'] ?? 'BOL'));
        $typeStatement = $pdo->prepare('SELECT id, direction, affects_stock, affects_receivables FROM document_types WHERE company_id = ? AND code = ? AND active = 1 LIMIT 1');
        $typeStatement->execute([$companyId, $typeCode]);
        $documentType = $typeStatement->fetch();
        if (!$documentType || $documentType['direction'] !== 'sale') fail('Tipo de documento de venta no válido.', 422);

        $customerId = !empty($input['customerId']) ? (int) $input['customerId'] : null;
        if ($customerId) {
            $check = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND company_id = ? AND active = 1');
            $check->execute([$customerId, $companyId]);
            if (!$check->fetchColumn()) fail('Cliente no válido.', 422);
        }
        $sellerId = !empty($input['sellerId']) ? (int) $input['sellerId'] : null;
        if ($sellerId) {
            $check = $pdo->prepare('SELECT id FROM users WHERE id = ? AND company_id = ? AND active = 1');
            $check->execute([$sellerId, $companyId]);
            if (!$check->fetchColumn()) fail('Vendedor no válido.', 422);
        }
        $paymentTermId = !empty($input['paymentTermId']) ? (int) $input['paymentTermId'] : null;
        $dueDate = null;
        $termDays = 0;
        if ($paymentTermId) {
            $check = $pdo->prepare('SELECT days FROM payment_terms WHERE id = ? AND company_id = ? AND active = 1');
            $check->execute([$paymentTermId, $companyId]);
            $days = $check->fetchColumn();
            if ($days === false) fail('Condición de pago no válida.', 422);
            $termDays = (int) $days;
            $dueDate = date('Y-m-d', strtotime('+' . $termDays . ' days'));
        }

        $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
        if (!$lines || count($lines) > 100) fail('Agrega entre 1 y 100 artículos para cobrar.', 422);
        $preparedLines = [];
        $subtotal = $discountTotal = $taxTotal = 0.0;
        $productStatement = $pdo->prepare('SELECT p.id, p.sku, p.name, p.description, p.product_type, p.tax_rate, COALESCE((SELECT amount FROM product_prices pp WHERE pp.product_id = p.id AND pp.price_list = ? ORDER BY pp.valid_from DESC, pp.id DESC LIMIT 1), (SELECT amount FROM product_prices pp WHERE pp.product_id = p.id ORDER BY pp.valid_from DESC, pp.id DESC LIMIT 1), 0) AS price_amount FROM products p WHERE p.id = ? AND p.company_id = ? AND p.active = 1 AND p.product_type IN ("product", "service")');
        $priceList = trim((string) ($input['priceList'] ?? 'general')) ?: 'general';
        foreach ($lines as $line) {
            $quantity = (float) ($line['quantity'] ?? 0);
            $discountPercent = (float) ($line['discountPercent'] ?? 0);
            if ($quantity <= 0 || $quantity > 10000) fail('La cantidad de cada artículo debe ser mayor que cero.', 422);
            if ($discountPercent < 0 || $discountPercent > 100) fail('El descuento debe estar entre 0 y 100%.', 422);
            $productStatement->execute([$priceList, (int) ($line['productId'] ?? 0), $companyId]);
            $product = $productStatement->fetch();
            if (!$product) fail('Uno de los artículos no está disponible en el catálogo.', 422);
            $price = (float) $product['price_amount'];
            $taxRate = (float) $product['tax_rate'];
            if ($price <= 0 || $taxRate < 0 || $taxRate > 100) fail('El artículo necesita un precio e impuesto válidos.', 422);
            $unitPrice = $price / (1 + $taxRate / 100);
            $gross = round($quantity * $unitPrice, 2);
            $lineDiscount = round($gross * $discountPercent / 100, 2);
            $net = round($gross - $lineDiscount, 2);
            $tax = round($net * $taxRate / 100, 2);
            $preparedLines[] = ['product' => $product, 'quantity' => $quantity, 'unitPrice' => round($unitPrice, 2), 'discountPercent' => $discountPercent, 'taxRate' => $taxRate, 'gross' => $gross, 'discount' => $lineDiscount, 'net' => $net, 'tax' => $tax, 'total' => round($net + $tax, 2)];
            $subtotal += $net; $discountTotal += $lineDiscount; $taxTotal += $tax;
        }
        $total = round($subtotal + $taxTotal, 2);
        if ($total < 0) fail('El total no puede ser negativo.', 422);
        $payments = is_array($input['payments'] ?? null) ? array_values($input['payments']) : [];
        if (!$payments && !empty($input['paymentMethodId'])) $payments[] = ['paymentMethodId' => $input['paymentMethodId'], 'amount' => $input['amount'] ?? $total];
        if (count($payments) > 20) fail('No puedes agregar más de 20 cobros.', 422);
        $paymentTotal = 0.0;
        $methodCheck = $pdo->prepare('SELECT id FROM payment_methods WHERE id = ? AND company_id = ? AND active = 1');
        foreach ($payments as &$payment) {
            $payment['paymentMethodId'] = (int) ($payment['paymentMethodId'] ?? 0);
            $payment['amount'] = round((float) ($payment['amount'] ?? 0), 2);
            if ($payment['amount'] <= 0) fail('Cada cobro debe ser mayor que cero.', 422);
            $methodCheck->execute([$payment['paymentMethodId'], $companyId]);
            if (!$methodCheck->fetchColumn()) fail('Selecciona una forma de pago válida.', 422);
            $paymentTotal += $payment['amount'];
        }
        unset($payment);
        $paymentTotal = round($paymentTotal, 2);
        if ($paymentTotal > $total) fail('Los cobros no pueden superar el total de la venta.', 422);
        if ($total > 0 && $paymentTotal <= 0 && $termDays <= 0 && (int) ($documentType['affects_receivables'] ?? 1) === 1) fail('Para una venta sin abono debes seleccionar una condición de pago a crédito.', 422);

        $pdo->beginTransaction();
        try {
            $branchStatement = $pdo->prepare('SELECT id FROM branches WHERE company_id = ? AND active = 1 ORDER BY id LIMIT 1');
            $branchStatement->execute([$companyId]);
            $branchRaw = $branchStatement->fetchColumn();
            $branchId = $branchRaw === false ? null : (int) $branchRaw;
            $documentId = (int) ($input['documentId'] ?? 0);
            if ($documentId) {
                $draft = $pdo->prepare('SELECT id, status, branch_id FROM documents WHERE id = ? AND company_id = ? AND document_type_id = ? FOR UPDATE');
                $draft->execute([$documentId, $companyId, (int) $documentType['id']]);
                $draftRow = $draft->fetch();
                if (!$draftRow || $draftRow['status'] !== 'draft') fail('El borrador ya no está disponible para cobrar.', 409);
                if ($draftRow['branch_id'] !== null) $branchId = (int) $draftRow['branch_id'];
            }
            $prefix = date('Y');
            $seriesStatement = $pdo->prepare('SELECT id, next_number FROM document_series WHERE company_id = ? AND branch_id <=> ? AND document_type_id = ? AND prefix = ? AND active = 1 ORDER BY id LIMIT 1 FOR UPDATE');
            $seriesStatement->execute([$companyId, $branchId, (int) $documentType['id'], $prefix]);
            $series = $seriesStatement->fetch();
            if (!$series) {
                $pdo->prepare('INSERT INTO document_series (company_id, branch_id, document_type_id, prefix, next_number) VALUES (?, ?, ?, ?, 1)')->execute([$companyId, $branchId, (int) $documentType['id'], $prefix]);
                $series = ['id' => (int) $pdo->lastInsertId(), 'next_number' => 1];
            }
            $number = (int) $series['next_number'];
            $pdo->prepare('UPDATE document_series SET next_number = next_number + 1 WHERE id = ?')->execute([(int) $series['id']]);
            $status = $paymentTotal >= $total ? 'paid' : ($paymentTotal > 0 ? 'partially_paid' : 'issued');
            $metadata = json_encode(['electronic_status' => 'not_configured', 'print_kind' => 'internal_receipt', 'price_list' => $priceList], JSON_UNESCAPED_UNICODE);
            if ($documentId) {
                $pdo->prepare('DELETE FROM document_lines WHERE document_id = ?')->execute([$documentId]);
                $pdo->prepare('UPDATE documents SET branch_id=?,series_id=?,number=?,status=?,customer_id=?,seller_id=?,payment_term_id=?,issue_date=CURRENT_DATE,due_date=?,subtotal=?,discount_total=?,tax_total=?,total=?,paid_total=?,notes=?,metadata_json=? WHERE id=?')
                    ->execute([$branchId, (int) $series['id'], $number, $status, $customerId, $sellerId, $paymentTermId, $dueDate, $subtotal, $discountTotal, $taxTotal, $total, $paymentTotal, trim((string) ($input['notes'] ?? '')), $metadata, $documentId]);
            } else {
                $pdo->prepare('INSERT INTO documents (company_id,branch_id,document_type_id,series_id,number,status,customer_id,seller_id,payment_term_id,issue_date,due_date,subtotal,discount_total,tax_total,total,paid_total,notes,metadata_json) VALUES (?,?,?,?,?,?,?,?,?,CURRENT_DATE,?,?,?,?,?,?,?,?)')
                    ->execute([$companyId, $branchId, (int) $documentType['id'], (int) $series['id'], $number, $status, $customerId, $sellerId, $paymentTermId, $dueDate, $subtotal, $discountTotal, $taxTotal, $total, $paymentTotal, trim((string) ($input['notes'] ?? '')), $metadata]);
                $documentId = (int) $pdo->lastInsertId();
            }
            $lineInsert = $pdo->prepare('INSERT INTO document_lines (document_id,product_id,line_number,concept,reference,description,quantity,unit_price,discount_percent,tax_rate,gross_amount,discount_amount,net_amount,tax_amount,total_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($preparedLines as $index => $line) {
                $product = $line['product'];
                $lineInsert->execute([$documentId, (int) $product['id'], $index + 1, $product['name'], $product['sku'], $product['description'], $line['quantity'], $line['unitPrice'], $line['discountPercent'], $line['taxRate'], $line['gross'], $line['discount'], $line['net'], $line['tax'], $line['total']]);
            }
            if ((int) $documentType['affects_stock'] === 1) recordDocumentStockOut($pdo, $companyId, $documentId, $branchId);
            $nowPayment = date('Y-m-d H:i:s');
            $sessionUserId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $paymentInsert = $pdo->prepare('INSERT INTO payments (company_id,document_id,payment_method_id,amount,paid_at,created_by) VALUES (?,?,?,?,?,?)');
            $paymentIds = [];
            foreach ($payments as $payment) {
                $paymentInsert->execute([$companyId, $documentId, $payment['paymentMethodId'], $payment['amount'], $nowPayment, $sessionUserId]);
                $paymentIds[] = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
            $paymentResults = [];
            foreach ($payments as $index => $payment) $paymentResults[] = ['id' => $paymentIds[$index], 'amount' => $payment['amount']];
            respond(['ok'=>true,'document'=>['id'=>$documentId,'status'=>$status,'folio'=>sprintf('%s/%06d',$prefix,$number),'total'=>$total,'paidTotal'=>$paymentTotal,'pending'=>round($total-$paymentTotal,2),'electronicStatus'=>'not_configured'],'payments'=>$paymentResults], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'POST' && $path === '/documents/draft') {
        $input = body();
        $company = tenant($pdo);
        $companyId = (int) $company['id'];
        $typeCode = strtoupper((string) ($input['type'] ?? 'BOL'));
        $type = $pdo->prepare('SELECT id FROM document_types WHERE company_id = ? AND code = ? AND direction = "sale" AND active = 1 LIMIT 1');
        $type->execute([$companyId, $typeCode]);
        $typeId = (int) $type->fetchColumn();
        if (!$typeId) fail('Tipo de documento no configurado.', 422);
        $customerId = !empty($input['customerId']) ? (int) $input['customerId'] : null;
        if ($customerId) {
            $check = $pdo->prepare('SELECT id FROM customers WHERE id = ? AND company_id = ? AND active = 1');
            $check->execute([$customerId, $companyId]);
            if (!$check->fetchColumn()) fail('Cliente no válido.', 422);
        }
        $sellerId = !empty($input['sellerId']) ? (int) $input['sellerId'] : null;
        if ($sellerId) {
            $check = $pdo->prepare('SELECT id FROM users WHERE id = ? AND company_id = ? AND active = 1');
            $check->execute([$sellerId, $companyId]);
            if (!$check->fetchColumn()) fail('Vendedor no válido.', 422);
        }
        $paymentTermId = !empty($input['paymentTermId']) ? (int) $input['paymentTermId'] : null;
        $dueDate = null;
        if ($paymentTermId) {
            $check = $pdo->prepare('SELECT days FROM payment_terms WHERE id = ? AND company_id = ? AND active = 1');
            $check->execute([$paymentTermId, $companyId]);
            $days = $check->fetchColumn();
            if ($days === false) fail('Condición de pago no válida.', 422);
            $dueDate = date('Y-m-d', strtotime('+' . (int) $days . ' days'));
        }
        $lines = is_array($input['lines'] ?? null) ? array_values($input['lines']) : [];
        if (!$lines || count($lines) > 100) fail('Agrega entre 1 y 100 artículos antes de guardar el borrador.', 422);
        $priceList = trim((string) ($input['priceList'] ?? 'general')) ?: 'general';
        $productCheck = $pdo->prepare('SELECT p.id,p.sku,p.name,p.description,p.tax_rate,COALESCE((SELECT amount FROM product_prices pp WHERE pp.product_id=p.id AND pp.price_list=? ORDER BY pp.valid_from DESC,pp.id DESC LIMIT 1),(SELECT amount FROM product_prices pp WHERE pp.product_id=p.id ORDER BY pp.valid_from DESC,pp.id DESC LIMIT 1),0) AS price_amount FROM products p WHERE p.id=? AND p.company_id=? AND p.active=1 AND p.product_type IN ("product","service")');
        $prepared = []; $subtotal = $discount = $tax = 0.0;
        foreach ($lines as $line) {
            $quantity = (float) ($line['quantity'] ?? 0); $discountPercent = (float) ($line['discountPercent'] ?? 0);
            if ($quantity <= 0 || $quantity > 10000) fail('La cantidad debe ser mayor que cero.', 422);
            if ($discountPercent < 0 || $discountPercent > 100) fail('El descuento debe estar entre 0 y 100%.', 422);
            $productCheck->execute([$priceList, (int) ($line['productId'] ?? 0), $companyId]);
            $product = $productCheck->fetch();
            if (!$product || (float) $product['price_amount'] <= 0) fail('Uno de los productos no tiene un precio válido.', 422);
            $rate=(float)$product['tax_rate']; $unit=(float)$product['price_amount']/(1+$rate/100); $gross=round($quantity*$unit,2); $dto=round($gross*$discountPercent/100,2); $net=round($gross-$dto,2); $taxAmount=round($net*$rate/100,2);
            $prepared[]=['product'=>$product,'quantity'=>$quantity,'unit'=>$unit,'discountPercent'=>$discountPercent,'rate'=>$rate,'gross'=>$gross,'discount'=>$dto,'net'=>$net,'tax'=>$taxAmount,'total'=>round($net+$taxAmount,2)];
            $subtotal+=$net; $discount+=$dto; $tax+=$taxAmount;
        }
        $pdo->beginTransaction();
        try {
            $documentId=(int)($input['documentId']??0);
            if ($documentId) {
                $existing=$pdo->prepare('SELECT id FROM documents WHERE id=? AND company_id=? AND document_type_id=? AND status="draft" FOR UPDATE');
                $existing->execute([$documentId,$companyId,$typeId]);
                if (!$existing->fetchColumn()) fail('El borrador ya no está disponible.',409);
                $pdo->prepare('UPDATE documents SET customer_id=?,seller_id=?,payment_term_id=?,due_date=?,subtotal=?,discount_total=?,tax_total=?,total=?,notes=?,metadata_json=? WHERE id=?')->execute([$customerId,$sellerId,$paymentTermId,$dueDate,$subtotal,$discount,$tax,$subtotal+$tax,trim((string)($input['notes']??'')),json_encode(['electronic_status'=>'not_configured','price_list'=>$priceList],JSON_UNESCAPED_UNICODE),$documentId]);
                $pdo->prepare('DELETE FROM document_lines WHERE document_id=?')->execute([$documentId]);
            } else {
                $branch=$pdo->prepare('SELECT id FROM branches WHERE company_id=? AND active=1 ORDER BY id LIMIT 1'); $branch->execute([$companyId]); $branchRaw=$branch->fetchColumn(); $branchId=$branchRaw===false?null:(int)$branchRaw;
                $pdo->prepare('INSERT INTO documents (company_id,branch_id,document_type_id,status,customer_id,seller_id,payment_term_id,issue_date,due_date,subtotal,discount_total,tax_total,total,notes,metadata_json) VALUES (?,?,?,"draft",?,?,?,CURRENT_DATE,?,?,?,?,?,?,?)')->execute([$companyId,$branchId,$typeId,$customerId,$sellerId,$paymentTermId,$dueDate,$subtotal,$discount,$tax,$subtotal+$tax,trim((string)($input['notes']??'')),json_encode(['electronic_status'=>'not_configured','price_list'=>$priceList],JSON_UNESCAPED_UNICODE)]);
                $documentId=(int)$pdo->lastInsertId();
            }
            $insert=$pdo->prepare('INSERT INTO document_lines (document_id,product_id,line_number,concept,reference,description,quantity,unit_price,discount_percent,tax_rate,gross_amount,discount_amount,net_amount,tax_amount,total_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach($prepared as $index=>$line){$p=$line['product'];$insert->execute([$documentId,(int)$p['id'],$index+1,$p['name'],$p['sku'],$p['description'],$line['quantity'],$line['unit'],$line['discountPercent'],$line['rate'],$line['gross'],$line['discount'],$line['net'],$line['tax'],$line['total']]);}
            $pdo->commit();
            respond(['ok'=>true,'document'=>['id'=>$documentId,'status'=>'draft','subtotal'=>$subtotal,'tax'=>$tax,'total'=>round($subtotal+$tax,2)]],$input['documentId']?200:201);
        } catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }

    if ($method === 'GET' && $path === '/stock') {
        $company = tenant($pdo);
        $filter = (string) ($_GET['filter'] ?? '');
        if (!in_array($filter, ['', 'available', 'zero'], true)) fail('Filtro no válido.', 422);
        $locationId = (int) ($_GET['locationId'] ?? 0);
        if ($locationId) {
            $check = $pdo->prepare('SELECT id FROM stock_locations WHERE id = ? AND company_id = ? AND active = 1');
            $check->execute([$locationId, (int) $company['id']]);
            if (!$check->fetchColumn()) fail('Ubicación no válida.', 422);
        }
        $locationClause = $locationId ? ' AND sm.location_id = ' . $locationId : '';
        $inner = 'SELECT p.id, p.sku, p.name, p.description, p.cost_amount, COALESCE((SELECT amount FROM product_prices pp WHERE pp.product_id = p.id ORDER BY pp.valid_from DESC, pp.id DESC LIMIT 1), 0) AS price_amount, COALESCE(SUM(CASE sm.movement_type WHEN "out" THEN -ABS(sm.quantity) ELSE sm.quantity END), 0) AS quantity, MAX(CASE WHEN sm.movement_type = "in" THEN sm.occurred_at END) AS last_entry FROM products p LEFT JOIN stock_movements sm ON sm.product_id = p.id AND sm.company_id = p.company_id' . $locationClause . ' WHERE p.company_id = ? AND p.active = 1 AND p.product_type = "product" GROUP BY p.id';
        $where = $filter === 'available' ? ' WHERE quantity > 0' : ($filter === 'zero' ? ' WHERE quantity = 0' : '');
        $sql = 'SELECT * FROM (' . $inner . ') stock' . $where;
        $summaryStatement = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) AS units, COALESCE(SUM(GREATEST(quantity, 0) * cost_amount), 0) AS cost_value, COALESCE(SUM(GREATEST(quantity, 0) * price_amount), 0) AS price_value, COUNT(*) AS total FROM (' . $inner . ') stock' . $where);
        $summaryStatement->execute([(int) $company['id']]);
        $summary = $summaryStatement->fetch();
        $statement = $pdo->prepare($sql . ' ORDER BY name LIMIT 5000');
        $statement->execute([(int) $company['id']]);
        $items = $statement->fetchAll();
        respond(['ok' => true, 'items' => $items, 'total' => (int) $summary['total'], 'summary' => ['units' => (float) $summary['units'], 'costValue' => round((float) $summary['cost_value'], 2), 'priceValue' => round((float) $summary['price_value'], 2)]]);
    }

    if (preg_match('#^/documents/(\d+)/approvals$#', $path, $match) && $method === 'GET') {
        $company = tenant($pdo);$documentId = (int) $match[1];
        $check = $pdo->prepare('SELECT id FROM documents WHERE id=? AND company_id=?');$check->execute([$documentId, (int) $company['id']]);if (!$check->fetchColumn()) fail('Documento no encontrado.', 404);
        $statement = $pdo->prepare('SELECT a.id,a.status,a.reason,a.requested_at,a.decided_at,a.decision_note,requester.display_name AS requested_by,decider.display_name AS decided_by FROM document_approvals a LEFT JOIN users requester ON requester.id=a.requested_by_user_id LEFT JOIN users decider ON decider.id=a.decided_by_user_id WHERE a.document_id=? AND a.company_id=? ORDER BY a.requested_at DESC,a.id DESC');
        $statement->execute([$documentId, (int) $company['id']]);respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }
    if (preg_match('#^/documents/(\d+)/approval-request$#', $path, $match) && $method === 'POST') {
        $company = tenant($pdo);$user = requireAuthenticated($pdo);$documentId = (int) $match[1];$input = body();$reason = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 500);
        $pdo->beginTransaction();try {
            $statement = $pdo->prepare('SELECT id,status FROM documents WHERE id=? AND company_id=? FOR UPDATE');$statement->execute([$documentId, (int) $company['id']]);$document = $statement->fetch();
            if (!$document) fail('Documento no encontrado.', 404);
            if ($document['status'] !== 'draft') fail('Solo se pueden solicitar aprobaciones para borradores.', 409);
            $pending = $pdo->prepare('SELECT id FROM document_approvals WHERE document_id=? AND company_id=? AND status="pending" LIMIT 1');$pending->execute([$documentId, (int) $company['id']]);
            if ($pending->fetchColumn()) fail('Ya hay una solicitud de visto bueno pendiente para este borrador.', 409);
            $now = date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO document_approvals (company_id,document_id,status,reason,requested_by_user_id,requested_at,created_at) VALUES (?,?,"pending",?,?,?,?)')->execute([(int) $company['id'], $documentId, $reason, (int) $user['id'], $now, $now]);
            $approvalId = (int) $pdo->lastInsertId();$pdo->commit();
            respond(['ok' => true, 'approvalId' => $approvalId], 201);
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack();throw $error; }
    }
    if (preg_match('#^/document-approvals/(\d+)/decision$#', $path, $match) && $method === 'POST') {
        $company = tenant($pdo);$user = requireRole($pdo, ['owner', 'admin']);$approvalId = (int) $match[1];$input = body();
        $decision = (string) ($input['decision'] ?? '');if (!in_array($decision, ['approve', 'reject'], true)) fail('Indica si el visto bueno se aprueba o se rechaza.', 422);
        $note = mb_substr(trim((string) ($input['note'] ?? '')), 0, 500);
        $pdo->beginTransaction();try {
            $statement = $pdo->prepare('SELECT id,status FROM document_approvals WHERE id=? AND company_id=? FOR UPDATE');$statement->execute([$approvalId, (int) $company['id']]);$approval = $statement->fetch();
            if (!$approval) fail('Solicitud no encontrada.', 404);
            if ($approval['status'] !== 'pending') fail('La solicitud ya fue resuelta.', 409);
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $pdo->prepare('UPDATE document_approvals SET status=?,decided_by_user_id=?,decided_at=?,decision_note=? WHERE id=?')->execute([$status, (int) $user['id'], date('Y-m-d H:i:s'), $note, $approvalId]);
            $pdo->commit();respond(['ok' => true, 'status' => $status]);
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack();throw $error; }
    }

    if (preg_match('#^/documents/(\d+)/relations$#', $path, $match) && in_array($method,['GET','POST'],true)) {
        $company=tenant($pdo);$documentId=(int)$match[1];$check=$pdo->prepare('SELECT id FROM documents WHERE id=? AND company_id=?');$check->execute([$documentId,(int)$company['id']]);if(!$check->fetchColumn())fail('Documento no encontrado.',404);
        if($method==='GET'){$statement=$pdo->prepare('SELECT r.id,r.relation_type,other.id AS document_id,other.number,t.code AS document_type,s.prefix,other.status,other.issue_date,other.total,c.name AS customer_name FROM document_relations r JOIN documents other ON other.id=CASE WHEN r.source_document_id=? THEN r.target_document_id ELSE r.source_document_id END JOIN document_types t ON t.id=other.document_type_id LEFT JOIN document_series s ON s.id=other.series_id LEFT JOIN customers c ON c.id=other.customer_id WHERE r.source_document_id=? OR r.target_document_id=? ORDER BY other.issue_date DESC,other.id DESC');$statement->execute([$documentId,$documentId,$documentId]);respond(['ok'=>true,'items'=>$statement->fetchAll()]);}
        $input=body();$targetId=(int)($input['targetDocumentId']??0);if($targetId<1||$targetId===$documentId)fail('Selecciona otro documento para asociar.',422);$targetCheck=$pdo->prepare('SELECT id FROM documents WHERE id=? AND company_id=?');$targetCheck->execute([$targetId,(int)$company['id']]);if(!$targetCheck->fetchColumn())fail('El documento asociado no existe en esta empresa.',404);$pdo->prepare('INSERT IGNORE INTO document_relations (source_document_id,target_document_id,relation_type) VALUES (?,?,"associated")')->execute([$documentId,$targetId]);respond(['ok'=>true,'associated'=>true],201);
    }

    if ($method === 'GET' && preg_match('#^/documents/([0-9]+)$#', $path, $match)) {
        $company=tenant($pdo); $documentId=(int)$match[1];
        $statement=$pdo->prepare('SELECT d.id,d.number,s.prefix,t.code AS document_type_code,t.name AS document_type_name,d.status,d.issue_date,d.due_date,d.subtotal,d.discount_total,d.tax_total,d.total,d.paid_total,(d.total-d.paid_total) AS pending_total,d.notes,d.metadata_json,d.customer_id,d.seller_id,d.payment_term_id,c.name AS customer_name,c.tax_id AS customer_tax_id,c.address AS customer_address,c.email AS customer_email,b.name AS branch,b.address AS branch_address,co.name AS company_name,co.legal_name,co.tax_id AS company_tax_id,u.display_name AS seller_name,pt.name AS payment_term FROM documents d JOIN document_types t ON t.id=d.document_type_id JOIN companies co ON co.id=d.company_id LEFT JOIN document_series s ON s.id=d.series_id LEFT JOIN customers c ON c.id=d.customer_id LEFT JOIN branches b ON b.id=d.branch_id LEFT JOIN users u ON u.id=d.seller_id LEFT JOIN payment_terms pt ON pt.id=d.payment_term_id WHERE d.id=? AND d.company_id=?');
        $statement->execute([$documentId,(int)$company['id']]); $document=$statement->fetch();
        if(!$document)fail('Documento no encontrado.',404);
        $lineStatement=$pdo->prepare('SELECT dl.product_id,dl.concept,dl.reference,dl.description,dl.quantity,dl.unit_price,dl.discount_percent,dl.tax_rate,dl.gross_amount,dl.discount_amount,dl.net_amount,dl.tax_amount,dl.total_amount,p.product_type,COALESCE((SELECT SUM(CASE sm.movement_type WHEN "out" THEN -ABS(sm.quantity) ELSE sm.quantity END) FROM stock_movements sm WHERE sm.product_id=p.id AND sm.company_id=p.company_id),0) AS stock_quantity FROM document_lines dl LEFT JOIN products p ON p.id=dl.product_id WHERE dl.document_id=? ORDER BY dl.line_number');
        $lineStatement->execute([$documentId]);
        $paymentStatement=$pdo->prepare('SELECT p.id,p.amount,p.paid_at,p.reference,pm.name AS payment_method FROM payments p JOIN payment_methods pm ON pm.id=p.payment_method_id WHERE p.document_id=? ORDER BY p.paid_at,p.id');
        $paymentStatement->execute([$documentId]);
        $document['metadata']=json_decode((string)($document['metadata_json']??''),true)?:[];
        unset($document['metadata_json']);
        respond(['ok'=>true,'document'=>$document,'lines'=>$lineStatement->fetchAll(),'payments'=>$paymentStatement->fetchAll()]);
    }

    if ($method === 'GET' && $path === '/documents') {
        $company = tenant($pdo);
        $from = dateParam('from', date('Y-m-d', strtotime('-6 months')));
        $to = dateParam('to', date('Y-m-d'));
        if ($from > $to) fail('La fecha inicial no puede ser posterior a la final.', 422);
        $typeCode = strtoupper((string) ($_GET['type'] ?? 'BOL'));
        $limit = min(max((int) ($_GET['limit'] ?? 50), 1), 500);
        $sql = 'SELECT d.id, d.number, s.prefix, d.status, d.issue_date, d.created_at, d.discount_total, d.total, d.paid_total, (d.total - d.paid_total) AS pending_total, c.name AS customer_name, b.name AS branch FROM documents d JOIN document_types t ON t.id = d.document_type_id LEFT JOIN document_series s ON s.id = d.series_id LEFT JOIN customers c ON c.id = d.customer_id LEFT JOIN branches b ON b.id = d.branch_id WHERE d.company_id = ? AND t.code = ? AND d.issue_date BETWEEN ? AND ?';
        $params = [(int) $company['id'], $typeCode, $from, $to];
        $status = (string) ($_GET['status'] ?? '');
        if ($status !== '') {
            if (!in_array($status, ['draft', 'issued', 'partially_paid', 'paid', 'delivered', 'cancelled'], true)) fail('Estado no válido.', 422);
            $sql .= ' AND d.status = ?';
            $params[] = $status;
        }
        $statement = $pdo->prepare($sql . ' ORDER BY d.created_at DESC, d.id DESC LIMIT ' . $limit);
        $statement->execute($params);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    // Boletas importadas desde el listado de Gesvision (solo consulta). Mismos campos que /documents para la lista.
    if ($method === 'GET' && $path === '/receipts') {
        $company = tenant($pdo);
        $from = dateParam('from', date('Y-m-d', strtotime('-6 months')));
        $to = dateParam('to', date('Y-m-d'));
        if ($from > $to) fail('La fecha inicial no puede ser posterior a la final.', 422);
        $limit = min(max((int) ($_GET['limit'] ?? 50), 1), 500);
        $statement = $pdo->prepare('SELECT series AS prefix, number, branch_name AS branch, customer_name, status, paid_total, pending_total, discount_total, total, created_at FROM sales_receipts WHERE company_id = ? AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
        $statement->execute([(int) $company['id'], $from, $to]);
        $items = array_map(fn(array $row) => $row + ['id' => null, 'source' => 'receipt'], $statement->fetchAll());
        respond(['ok' => true, 'items' => $items]);
    }

    if ($method === 'POST' && preg_match('#^/documents/(\d+)/issue$#', $path, $match)) {
        $company = tenant($pdo);
        $documentId = (int) $match[1];
        $pdo->beginTransaction();
        try {
            $documentStatement = $pdo->prepare('SELECT id, status, document_type_id, branch_id FROM documents WHERE id = ? AND company_id = ? FOR UPDATE');
            $documentStatement->execute([$documentId, (int) $company['id']]);
            $document = $documentStatement->fetch();
            if (!$document) fail('Documento no encontrado.', 404);
            if ($document['status'] !== 'draft') fail('Solo se puede emitir un borrador.', 409);
            $linesStatement = $pdo->prepare('SELECT COUNT(*) FROM document_lines WHERE document_id = ?');
            $linesStatement->execute([$documentId]);
            if ((int) $linesStatement->fetchColumn() === 0) fail('El documento no tiene líneas.', 422);
            $stockType = $pdo->prepare('SELECT affects_stock FROM document_types WHERE id = ? AND company_id = ?');
            $stockType->execute([(int) $document['document_type_id'], (int) $company['id']]);
            if ((int) $stockType->fetchColumn() === 1) recordDocumentStockOut($pdo, (int) $company['id'], $documentId, $document['branch_id'] === null ? null : (int) $document['branch_id']);
            $prefix = date('Y');
            $seriesStatement = $pdo->prepare('SELECT id, next_number FROM document_series WHERE company_id = ? AND document_type_id = ? AND prefix = ? AND active = 1 ORDER BY id LIMIT 1 FOR UPDATE');
            $seriesStatement->execute([(int) $company['id'], (int) $document['document_type_id'], $prefix]);
            $series = $seriesStatement->fetch();
            if (!$series) {
                $pdo->prepare('INSERT INTO document_series (company_id, document_type_id, prefix, next_number) VALUES (?, ?, ?, 1)')
                    ->execute([(int) $company['id'], (int) $document['document_type_id'], $prefix]);
                $series = ['id' => (int) $pdo->lastInsertId(), 'next_number' => 1];
            }
            $number = (int) $series['next_number'];
            $pdo->prepare('UPDATE document_series SET next_number = next_number + 1 WHERE id = ?')->execute([(int) $series['id']]);
            $pdo->prepare('UPDATE documents SET status = "issued", series_id = ?, number = ?, issue_date = CURRENT_DATE WHERE id = ?')
                ->execute([(int) $series['id'], $number, $documentId]);
            $pdo->commit();
            respond(['ok' => true, 'document' => ['id' => $documentId, 'status' => 'issued', 'folio' => sprintf('%s/%06d', $prefix, $number)]]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'POST' && preg_match('#^/documents/(\d+)/cancel$#', $path, $match)) {
        $company = tenant($pdo);
        $documentId = (int) $match[1];
        $pdo->beginTransaction();
        try {
            $documentStatement = $pdo->prepare('SELECT id, status, paid_total FROM documents WHERE id = ? AND company_id = ? FOR UPDATE');
            $documentStatement->execute([$documentId, (int) $company['id']]);
            $document = $documentStatement->fetch();
            if (!$document) fail('Documento no encontrado.', 404);
            if ($document['status'] === 'cancelled') fail('El documento ya está anulado.', 409);
            if ((float) $document['paid_total'] > 0) fail('No se puede anular un documento con cobros registrados.', 409);
            $outgoing = $pdo->prepare('INSERT INTO stock_movements (company_id,product_id,location_id,document_id,quantity,movement_type,occurred_at) SELECT company_id,product_id,location_id,document_id,quantity,"in",NOW() FROM stock_movements WHERE document_id=? AND movement_type="out"');
            $outgoing->execute([$documentId]);
            $pdo->prepare('UPDATE documents SET status = "cancelled" WHERE id = ?')->execute([$documentId]);
            $pdo->commit();
            respond(['ok' => true, 'document' => ['id' => $documentId, 'status' => 'cancelled']]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'POST' && $path === '/products') {
        $company = tenant($pdo);
        $data = productInput(body());
        $pdo->beginTransaction();
        try {
            $skuStatement = $pdo->prepare('SELECT COUNT(*) FROM products WHERE company_id = ? AND sku = ?');
            $skuStatement->execute([(int) $company['id'], $data['sku']]);
            if ((int) $skuStatement->fetchColumn() > 0) fail('Ya existe un producto con ese código.', 409);
            $pdo->prepare('INSERT INTO products (company_id, sku, name, description, tax_rate, cost_amount, active) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) $company['id'], $data['sku'], $data['name'], $data['description'], $data['tax_rate'], $data['cost_amount'], $data['active']]);
            $productId = (int) $pdo->lastInsertId();
            if ($data['price_amount'] > 0) {
                $pdo->prepare('INSERT INTO product_prices (product_id, amount, valid_from) VALUES (?, ?, CURRENT_DATE)')->execute([$productId, $data['price_amount']]);
            }
            $pdo->commit();
            respond(['ok' => true, 'product' => ['id' => $productId] + $data], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'PUT' && preg_match('#^/products/(\d+)$#', $path, $match)) {
        $company = tenant($pdo);
        $productId = (int) $match[1];
        $data = productInput(body());
        $existsStatement = $pdo->prepare('SELECT id FROM products WHERE id = ? AND company_id = ?');
        $existsStatement->execute([$productId, (int) $company['id']]);
        if (!$existsStatement->fetchColumn()) fail('Producto no encontrado.', 404);
        $pdo->prepare('UPDATE products SET sku = ?, name = ?, description = ?, tax_rate = ?, cost_amount = ?, active = ? WHERE id = ? AND company_id = ?')
            ->execute([$data['sku'], $data['name'], $data['description'], $data['tax_rate'], $data['cost_amount'], $data['active'], $productId, (int) $company['id']]);
        respond(['ok' => true, 'product' => ['id' => $productId] + $data]);
    }

    if ($method === 'GET' && $path === '/payment-methods') {
        $company = tenant($pdo);
        $statement = $pdo->prepare('SELECT id, name, code FROM payment_methods WHERE company_id = ? AND active = 1 ORDER BY name');
        $statement->execute([(int) $company['id']]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $path === '/documents/open-balances') {
        $company=tenant($pdo);$customerId=(int)($_GET['customerId']??-1);if($customerId<0)fail('Selecciona un cliente para consultar sus documentos pendientes.',422);if($customerId>0){$check=$pdo->prepare('SELECT id FROM customers WHERE id=? AND company_id=? AND active=1');$check->execute([$customerId,(int)$company['id']]);if(!$check->fetchColumn())fail('Cliente no válido.',422);}
        $customerClause=$customerId===0?' AND d.customer_id IS NULL':' AND d.customer_id = ?';$params=[(int)$company['id']];if($customerId>0)$params[]=$customerId;$sql='SELECT d.id,d.number,s.prefix,t.code AS document_type,d.issue_date,d.due_date,d.total,d.paid_total,(d.total-d.paid_total) AS pending_total,c.name AS customer_name FROM documents d JOIN document_types t ON t.id=d.document_type_id LEFT JOIN document_series s ON s.id=d.series_id LEFT JOIN customers c ON c.id=d.customer_id WHERE d.company_id=? AND t.direction="sale" AND d.status IN ("issued","partially_paid","paid","delivered") AND d.total>d.paid_total'.$customerClause;$statement=$pdo->prepare($sql.' ORDER BY d.issue_date DESC,d.id DESC LIMIT 500');$statement->execute($params);respond(['ok'=>true,'items'=>$statement->fetchAll()]);
    }

    if ($method === 'GET' && preg_match('#^/payments/(\d+)$#',$path,$match)) {
        $company=tenant($pdo);$statement=$pdo->prepare('SELECT p.id,p.amount,p.paid_at,p.created_at,p.reference,p.notes,d.id AS document_id,d.number AS document_number,s.prefix AS document_prefix,d.status AS document_status,d.total AS document_total,d.paid_total AS document_paid_total,(d.total-d.paid_total) AS document_pending_total,c.id AS customer_id,c.name AS customer_name,pm.id AS payment_method_id,pm.name AS payment_method FROM payments p JOIN documents d ON d.id=p.document_id LEFT JOIN document_series s ON s.id=d.series_id LEFT JOIN customers c ON c.id=d.customer_id JOIN payment_methods pm ON pm.id=p.payment_method_id WHERE p.id=? AND p.company_id=?');$statement->execute([(int)$match[1],(int)$company['id']]);$payment=$statement->fetch();if(!$payment)fail('Cobro no encontrado.',404);respond(['ok'=>true,'payment'=>$payment]);
    }

    if ($method === 'GET' && $path === '/payments') {
        $company = tenant($pdo);
        $from = dateParam('from', date('Y-m-d', strtotime('-6 months')));
        $to = dateParam('to', date('Y-m-d'));
        if ($from > $to) fail('La fecha inicial no puede ser posterior a la final.', 422);
        $limit = min(max((int) ($_GET['limit'] ?? 20), 1), 500);$page=min(max((int)($_GET['page']??1),1),100000);$offset=($page-1)*$limit;$q=trim((string)($_GET['q']??''));$methodId=(int)($_GET['paymentMethodId']??0);$source='SELECT p.id,p.amount,p.paid_at,p.created_at,p.reference,p.notes,d.id AS document_id,d.number AS document_number,s.prefix AS document_prefix,c.name AS customer_name,pm.name AS payment_method,p.payment_method_id,\'native\' AS source FROM payments p JOIN documents d ON d.id=p.document_id LEFT JOIN customers c ON c.id=d.customer_id JOIN payment_methods pm ON pm.id=p.payment_method_id LEFT JOIN document_series s ON s.id=d.series_id WHERE p.company_id=? UNION ALL SELECT ip.id,ip.amount,ip.paid_at,ip.created_at,CONCAT(ip.receipt_series,\'/\',LPAD(ip.receipt_number,6,\'0\')) AS reference,NULL AS notes,NULL AS document_id,ip.invoice_number AS document_number,NULL AS document_prefix,ip.customer_name,ip.payment_type AS payment_method,NULL AS payment_method_id,\'imported\' AS source FROM imported_payments ip WHERE ip.company_id=?';$where=' FROM ('.$source.') x WHERE DATE(x.paid_at) BETWEEN ? AND ?';$params=[(int)$company['id'],(int)$company['id'],$from,$to];if($q!==''){$where.=' AND (x.customer_name LIKE ? OR CAST(x.document_number AS CHAR) LIKE ? OR CAST(x.id AS CHAR) LIKE ? OR x.reference LIKE ? OR x.payment_method LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like,$like,$like);}if($methodId){$where.=' AND x.payment_method_id=?';$params[]=$methodId;}
        $count=$pdo->prepare('SELECT COUNT(*)'.$where);$count->execute($params);$total=(int)$count->fetchColumn();$sort=(string)($_GET['sort']??'date');$order=in_array($sort,['amount','customer','document','method'],true)?['amount'=>'x.amount','customer'=>'x.customer_name','document'=>'x.document_number','method'=>'x.payment_method'][$sort]:'x.paid_at';$direction=strtolower((string)($_GET['direction']??'desc'))==='asc'?'ASC':'DESC';$statement=$pdo->prepare('SELECT x.id,x.amount,x.paid_at,x.created_at,x.reference,x.notes,x.document_id,x.document_number,x.document_prefix,x.customer_name,x.payment_method,x.source'.$where.' ORDER BY '.$order.' '.$direction.',x.id DESC LIMIT '.$limit.' OFFSET '.$offset);$statement->execute($params);respond(['ok'=>true,'items'=>$statement->fetchAll(),'total'=>$total,'page'=>$page,'limit'=>$limit,'from'=>$from,'to'=>$to]);
    }

    if ($method === 'POST' && $path === '/payments') {
        $currentUser = requireRole($pdo, ['owner', 'admin', 'cashier']);
        $input = body();
        $company = tenant($pdo);
        $documentId = (int) ($input['documentId'] ?? 0);
        $methodId = (int) ($input['paymentMethodId'] ?? 0);
        $amount = round((float) ($input['amount'] ?? 0), 2);
        if ($amount <= 0) fail('El importe debe ser mayor que cero.', 422);
        $pdo->beginTransaction();
        try {
            $documentStatement = $pdo->prepare('SELECT id, status, total, paid_total, branch_id FROM documents WHERE id = ? AND company_id = ? FOR UPDATE');
            $documentStatement->execute([$documentId, (int) $company['id']]);
            $document = $documentStatement->fetch();
            if (!$document) fail('Documento no encontrado.', 404);
            if ($document['status'] === 'draft') fail('Guarda y emite el borrador antes de registrar un abono.', 422);
            if ($document['status'] === 'cancelled') fail('No se puede cobrar un documento anulado.', 422);
            $pending = round((float) $document['total'] - (float) $document['paid_total'], 2);
            if ($amount > $pending) fail('El importe supera lo pendiente de pago.', 422);
            $methodStatement = $pdo->prepare('SELECT id FROM payment_methods WHERE id = ? AND company_id = ? AND active = 1');
            $methodStatement->execute([$methodId, (int) $company['id']]);
            if (!$methodStatement->fetchColumn()) fail('Forma de pago no válida.', 422);
            $paymentDate=trim((string)($input['paidAt']??date('Y-m-d')));$parsedDate=DateTime::createFromFormat('!Y-m-d',$paymentDate);if(!$parsedDate||$parsedDate->format('Y-m-d')!==$paymentDate)fail('La fecha del cobro no es válida.',422);
            if ($paymentDate > date('Y-m-d')) fail('La fecha del cobro no puede ser futura.', 422);
            $paymentDateTime=$paymentDate.' '.date('H:i:s');
            $docBranch = $document['branch_id'] !== null ? (int) $document['branch_id'] : null;
            $lockRegisters = $pdo->prepare('SELECT id FROM cash_registers WHERE company_id = ? AND (branch_id IS NULL OR (? IS NOT NULL AND branch_id = ?)) ORDER BY id FOR UPDATE');
            $lockRegisters->execute([(int) $company['id'], $docBranch, $docBranch]);
            $closureCheck = $pdo->prepare('SELECT cc.id FROM cash_closures cc JOIN cash_registers cr ON cr.id = cc.cash_register_id WHERE cr.company_id = ? AND (cr.branch_id IS NULL OR (? IS NOT NULL AND cr.branch_id = ?)) AND cc.status = "closed" AND cc.opened_at <= ? AND cc.closed_at >= ? LIMIT 1');
            $closureCheck->execute([(int) $company['id'], $docBranch, $docBranch, $paymentDateTime, $paymentDateTime]);
            if ($closureCheck->fetchColumn()) fail('No se puede registrar un cobro en un turno de caja ya cerrado.', 409);
            $reference=trim((string)($input['reference']??''));$notes=trim((string)($input['notes']??''));if(mb_strlen($reference)>120||mb_strlen($notes)>255)fail('La referencia u observación supera el largo permitido.',422);
            $pdo->prepare('INSERT INTO payments (company_id, document_id, payment_method_id, amount, paid_at, reference, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int) $company['id'], $documentId, $methodId, $amount, $paymentDateTime, $reference?:null, $notes?:null, (int) $currentUser['id']]);
            $paymentId = (int) $pdo->lastInsertId();
            $paidTotal = round((float) $document['paid_total'] + $amount, 2);
            $status = $paidTotal >= (float) $document['total'] ? 'paid' : 'partially_paid';
            $pdo->prepare('UPDATE documents SET paid_total = ?, status = ? WHERE id = ?')->execute([$paidTotal, $status, $documentId]);
            $pdo->commit();
            respond(['ok' => true, 'payment' => ['id' => $paymentId, 'documentId' => $documentId, 'amount' => $amount], 'document' => ['paidTotal' => $paidTotal, 'pending' => round((float) $document['total'] - $paidTotal, 2), 'status' => $status]], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if (($method === 'POST' && preg_match('#^/payments/(\d+)/cancel$#', $path, $match)) || ($method === 'DELETE' && preg_match('#^/payments/(\d+)$#', $path, $match))) {
        $currentUser = requireRole($pdo, ['owner', 'admin', 'cashier']);
        $company = tenant($pdo);
        $companyId = (int) $company['id'];
        $paymentId = (int) $match[1];
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT p.*, d.status AS document_status, d.total AS document_total, d.paid_total AS document_paid_total, d.branch_id AS document_branch_id FROM payments p JOIN documents d ON d.id = p.document_id WHERE p.id = ? AND p.company_id = ? FOR UPDATE');
            $statement->execute([$paymentId, $companyId]);
            $payment = $statement->fetch();
            if (!$payment) fail('Cobro no encontrado.', 404);

            $docBranch = $payment['document_branch_id'] !== null ? (int) $payment['document_branch_id'] : null;
            $lockRegisters = $pdo->prepare('SELECT id FROM cash_registers WHERE company_id = ? AND (branch_id IS NULL OR (? IS NOT NULL AND branch_id = ?)) ORDER BY id FOR UPDATE');
            $lockRegisters->execute([$companyId, $docBranch, $docBranch]);
            $closureCheck = $pdo->prepare('SELECT cc.id FROM cash_closures cc JOIN cash_registers cr ON cr.id = cc.cash_register_id WHERE cr.company_id = ? AND (cr.branch_id IS NULL OR (? IS NOT NULL AND cr.branch_id = ?)) AND cc.status = "closed" AND cc.opened_at <= ? AND cc.closed_at >= ? LIMIT 1');
            $closureCheck->execute([$companyId, $docBranch, $docBranch, $payment['paid_at'], $payment['paid_at']]);
            if ($closureCheck->fetchColumn()) fail('No se puede anular un cobro correspondiente a un cierre de caja ya cerrado.', 409);

            $newPaidTotal = max(0.0, round((float) $payment['document_paid_total'] - (float) $payment['amount'], 2));
            $currentDocStatus = (string) $payment['document_status'];
            if (in_array($currentDocStatus, ['cancelled', 'delivered'], true)) {
                $newStatus = $currentDocStatus;
            } else {
                $newStatus = $newPaidTotal >= (float) $payment['document_total'] ? 'paid' : ($newPaidTotal > 0 ? 'partially_paid' : 'issued');
            }

            $pdo->prepare('UPDATE documents SET paid_total = ?, status = ? WHERE id = ?')->execute([$newPaidTotal, $newStatus, (int) $payment['document_id']]);
            $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$paymentId]);

            $auditChanges = json_encode([
                'amount' => (float) $payment['amount'],
                'document_id' => (int) $payment['document_id'],
                'previous_paid_total' => (float) $payment['document_paid_total'],
                'new_paid_total' => $newPaidTotal,
                'document_status' => $newStatus,
                'payment_method_id' => (int) $payment['payment_method_id'],
                'paid_at' => $payment['paid_at'],
                'reference' => $payment['reference'],
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare('INSERT INTO audit_logs (company_id, user_id, entity_type, entity_id, action, changes_json) VALUES (?, ?, "payments", ?, "cancel", ?)')
                ->execute([$companyId, (int) $currentUser['id'], $paymentId, $auditChanges]);

            $pdo->commit();
            respond(['ok' => true, 'cancelledPaymentId' => $paymentId, 'document' => ['id' => (int) $payment['document_id'], 'paidTotal' => $newPaidTotal, 'pending' => round((float) $payment['document_total'] - $newPaidTotal, 2), 'status' => $newStatus]]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'GET' && $path === '/cash-closures') {
        $company = tenant($pdo);
        $status = (string) ($_GET['status'] ?? '');
        $now = date('Y-m-d H:i:s');
        $sql = 'SELECT cc.id, cr.name AS cash_register, b.name AS branch, cc.opened_at, cc.closed_at, cc.opening_amount, cc.closing_amount, cc.status, u.display_name AS closed_by_name, (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN documents d ON d.id = p.document_id JOIN payment_methods pm ON pm.id = p.payment_method_id WHERE p.company_id = cr.company_id AND (cr.branch_id IS NULL OR d.branch_id <=> cr.branch_id) AND (pm.code = "cash" OR LOWER(pm.name) LIKE "%efectivo%") AND p.paid_at >= cc.opened_at AND p.paid_at <= COALESCE(cc.closed_at, ?)) AS calculated_amount FROM cash_closures cc JOIN cash_registers cr ON cr.id = cc.cash_register_id LEFT JOIN branches b ON b.id = cr.branch_id LEFT JOIN users u ON u.id = cc.closed_by WHERE cr.company_id = ?';
        $params = [$now, (int) $company['id']];
        if (in_array($status, ['open', 'closed'], true)) { $sql .= ' AND cc.status = ?'; $params[] = $status; }
        $statement = $pdo->prepare($sql . ' ORDER BY cc.opened_at DESC LIMIT 100');
        $statement->execute($params);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $path === '/cash-closures/current') {
        $company = tenant($pdo);
        $companyId = (int) $company['id'];
        $requestedRegisterId = (int) ($_GET['cashRegisterId'] ?? 0);
        if ($requestedRegisterId) {
            $register = $pdo->prepare('SELECT id, name, branch_id FROM cash_registers WHERE id = ? AND company_id = ? AND active = 1');
            $register->execute([$requestedRegisterId, $companyId]);
        } else {
            $register = $pdo->prepare('SELECT id, name, branch_id FROM cash_registers WHERE company_id = ? AND active = 1 ORDER BY id LIMIT 1');
            $register->execute([$companyId]);
        }
        $cashRegister = $register->fetch();
        if (!$cashRegister) {
            $firstPayment = $pdo->prepare('SELECT MIN(paid_at) FROM payments WHERE company_id = ?');
            $firstPayment->execute([$companyId]);
            $openedAt = $firstPayment->fetchColumn() ?: date('Y-m-d 00:00:00');
            $now = date('Y-m-d H:i:s');
            $sum = $pdo->prepare('SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN payment_methods pm ON pm.id = p.payment_method_id WHERE p.company_id = ? AND (pm.code = "cash" OR LOWER(pm.name) LIKE "%efectivo%") AND p.paid_at >= ? AND p.paid_at <= ?');
            $sum->execute([$companyId, $openedAt, $now]);
            respond(['ok' => true, 'cashRegister' => null, 'openedAt' => $openedAt, 'calculatedAmount' => (float) $sum->fetchColumn(), 'breakdown' => []]);
        }
        $branchId = $cashRegister['branch_id'] !== null ? (int) $cashRegister['branch_id'] : null;
        $open = $pdo->prepare('SELECT id, opened_at FROM cash_closures WHERE cash_register_id = ? AND status = "open" ORDER BY opened_at LIMIT 1');
        $open->execute([(int) $cashRegister['id']]);
        $closure = $open->fetch();
        $openedAt = $closure['opened_at'] ?? null;
        if (!$openedAt) {
            $last = $pdo->prepare('SELECT MAX(closed_at) FROM cash_closures WHERE cash_register_id = ?');
            $last->execute([(int) $cashRegister['id']]);
            $openedAt = $last->fetchColumn();
            if (!$openedAt) {
                $firstPayment = $pdo->prepare('SELECT MIN(p.paid_at) FROM payments p JOIN documents d ON d.id = p.document_id WHERE p.company_id = ? AND (? IS NULL OR d.branch_id <=> ?)');
                $firstPayment->execute([$companyId, $branchId, $branchId]);
                $openedAt = $firstPayment->fetchColumn() ?: date('Y-m-d 00:00:00');
            }
        }
        $now = date('Y-m-d H:i:s');
        $sum = $pdo->prepare('SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN documents d ON d.id = p.document_id JOIN payment_methods pm ON pm.id = p.payment_method_id WHERE p.company_id = ? AND (? IS NULL OR d.branch_id <=> ?) AND (pm.code = "cash" OR LOWER(pm.name) LIKE "%efectivo%") AND p.paid_at >= ? AND p.paid_at <= ?');
        $sum->execute([$companyId, $branchId, $branchId, $openedAt, $now]);
        $calculatedCash = (float) $sum->fetchColumn();

        $breakdown = $pdo->prepare('SELECT pm.name AS method_name, COALESCE(SUM(p.amount), 0) AS total_amount, COUNT(p.id) AS payment_count FROM payments p JOIN documents d ON d.id = p.document_id JOIN payment_methods pm ON pm.id = p.payment_method_id WHERE p.company_id = ? AND (? IS NULL OR d.branch_id <=> ?) AND p.paid_at >= ? AND p.paid_at <= ? GROUP BY pm.id, pm.name ORDER BY total_amount DESC');
        $breakdown->execute([$companyId, $branchId, $branchId, $openedAt, $now]);

        respond([
            'ok' => true,
            'cashRegister' => $cashRegister,
            'openedAt' => $openedAt,
            'calculatedAmount' => $calculatedCash,
            'breakdown' => $breakdown->fetchAll()
        ]);
    }

    if ($method === 'POST' && $path === '/cash-closures/close') {
        $currentUser = requireRole($pdo, ['owner', 'admin', 'cashier']);
        $input = body();
        $company = tenant($pdo);
        $companyId = (int) $company['id'];
        $counted = round((float) ($input['countedAmount'] ?? -1), 2);
        if ($counted < 0) fail('Indica el importe del recuento.', 422);
        $userId = (int) $currentUser['id'];
        $now = date('Y-m-d H:i:s');
        $requestedRegisterId = (int) ($input['cashRegisterId'] ?? 0);
        $pdo->beginTransaction();
        try {
            if ($requestedRegisterId) {
                $registerStatement = $pdo->prepare('SELECT id, branch_id FROM cash_registers WHERE id = ? AND company_id = ? AND active = 1 FOR UPDATE');
                $registerStatement->execute([$requestedRegisterId, $companyId]);
                $cashRegister = $registerStatement->fetch();
                if (!$cashRegister) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    fail('Caja no encontrada.', 404);
                }
            } else {
                $registerStatement = $pdo->prepare('SELECT id, branch_id FROM cash_registers WHERE company_id = ? AND active = 1 ORDER BY id LIMIT 1 FOR UPDATE');
                $registerStatement->execute([$companyId]);
                $cashRegister = $registerStatement->fetch();
            }
            if (!$cashRegister) {
                $pdo->prepare('INSERT INTO cash_registers (company_id, name) VALUES (?, ?)')->execute([$companyId, 'Caja principal']);
                $registerId = (int) $pdo->lastInsertId();
                $branchId = null;
            } else {
                $registerId = (int) $cashRegister['id'];
                $branchId = $cashRegister['branch_id'] !== null ? (int) $cashRegister['branch_id'] : null;
            }
            $openStatement = $pdo->prepare('SELECT id, opened_at FROM cash_closures WHERE cash_register_id = ? AND status = "open" ORDER BY opened_at LIMIT 1 FOR UPDATE');
            $openStatement->execute([$registerId]);
            $closure = $openStatement->fetch();
            if (!$closure) {
                $lastStatement = $pdo->prepare('SELECT MAX(closed_at) FROM cash_closures WHERE cash_register_id = ?');
                $lastStatement->execute([$registerId]);
                $openedAt = $lastStatement->fetchColumn();
                if (!$openedAt) {
                    $firstPayment = $pdo->prepare('SELECT MIN(p.paid_at) FROM payments p JOIN documents d ON d.id = p.document_id WHERE p.company_id = ? AND (? IS NULL OR d.branch_id <=> ?)');
                    $firstPayment->execute([$companyId, $branchId, $branchId]);
                    $openedAt = $firstPayment->fetchColumn() ?: date('Y-m-d 00:00:00');
                }
                $pdo->prepare('INSERT INTO cash_closures (cash_register_id, opened_at) VALUES (?, ?)')->execute([$registerId, $openedAt]);
                $closure = ['id' => (int) $pdo->lastInsertId(), 'opened_at' => $openedAt];
            }
            $sumStatement = $pdo->prepare('SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN documents d ON d.id = p.document_id JOIN payment_methods pm ON pm.id = p.payment_method_id WHERE p.company_id = ? AND (? IS NULL OR d.branch_id <=> ?) AND (pm.code = "cash" OR LOWER(pm.name) LIKE "%efectivo%") AND p.paid_at >= ? AND p.paid_at <= ?');
            $sumStatement->execute([$companyId, $branchId, $branchId, $closure['opened_at'], $now]);
            $calculated = round((float) $sumStatement->fetchColumn(), 2);
            $pdo->prepare('UPDATE cash_closures SET closed_at = ?, closing_amount = ?, closed_by = ?, status = "closed" WHERE id = ?')->execute([$now, $counted, $userId, (int) $closure['id']]);
            $pdo->commit();
            respond(['ok' => true, 'closure' => ['id' => (int) $closure['id'], 'cashRegisterId' => $registerId, 'openedAt' => $closure['opened_at'], 'calculatedAmount' => $calculated, 'countedAmount' => $counted, 'difference' => round($counted - $calculated, 2), 'status' => 'closed']], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'POST' && $path === '/reports') {
        $user = currentUser($pdo);
        if (!$_POST && !$_FILES && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) fail('El adjunto supera el límite del servidor.', 413);
        $type = (string) ($_POST['type'] ?? '');
        $description = trim((string) ($_POST['description'] ?? ''));
        $error = reportInputError($type, $description);
        if ($error !== null) fail($error, 422);
        $attachment = null;
        $file = $_FILES['attachment'] ?? null;
        if (is_array($file) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
            $error = reportAttachmentError((int) $file['error'], (int) $file['size']);
            if ($error !== null) fail($error, 422);
            if (!is_uploaded_file((string) $file['tmp_name'])) fail('No se pudo subir el adjunto. Inténtalo otra vez.', 422);
            $mime = reportAttachmentMime((string) $file['tmp_name']);
            if ($mime === null) fail('El adjunto debe ser una imagen JPG, PNG o WebP, o un PDF.', 422);
            $dir = reportStorageDir();
            $storedName = reportStoredName($mime);
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) fail('No se pudo guardar el adjunto.', 500);
            if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $storedName)) fail('No se pudo guardar el adjunto.', 500);
            $attachment = ['path' => $storedName, 'name' => reportSafeFilename((string) $file['name']), 'mime' => $mime, 'size' => (int) $file['size']];
        }
        try {
            $statement = $pdo->prepare('INSERT INTO feedback_reports (company_id, user_id, type, description, status, attachment_path, attachment_name, attachment_mime, attachment_size) VALUES (?, ?, ?, ?, "new", ?, ?, ?, ?)');
            $statement->execute([(int) $user['company_id'], (int) $user['id'], $type, $description, $attachment['path'] ?? null, $attachment['name'] ?? null, $attachment['mime'] ?? null, $attachment['size'] ?? null]);
            $reportId = (int) $pdo->lastInsertId();
        } catch (Throwable $error) {
            if ($attachment !== null) @unlink($dir . '/' . $attachment['path']);
            throw $error;
        }
        $report = $pdo->prepare('SELECT r.id, r.type, r.description, r.status, u.display_name AS reporter_name, (r.attachment_path IS NOT NULL) AS has_attachment, r.attachment_name, r.attachment_size, r.created_at, r.status_changed_at FROM feedback_reports r LEFT JOIN users u ON u.id = r.user_id WHERE r.id = ?');
        $report->execute([$reportId]);
        respond(['ok' => true, 'report' => $report->fetch()], 201);
    }

    if ($method === 'GET' && $path === '/reports') {
        $user = requireRole($pdo, ['owner', 'admin']);
        $status = (string) ($_GET['status'] ?? '');
        if ($status !== '' && !in_array($status, REPORT_STATUSES, true)) fail('Estado no válido.', 422);
        $sql = 'SELECT r.id, r.type, r.description, r.status, u.display_name AS reporter_name, (r.attachment_path IS NOT NULL) AS has_attachment, r.attachment_name, r.attachment_size, r.created_at, r.status_changed_at FROM feedback_reports r LEFT JOIN users u ON u.id = r.user_id WHERE r.company_id = ?';
        $params = [(int) $user['company_id']];
        if ($status !== '') {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT 200';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'PUT' && preg_match('#^/reports/(\d+)/status$#', $path, $match)) {
        $user = requireRole($pdo, ['owner', 'admin']);
        $input = body();
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, REPORT_STATUSES, true)) fail('Estado no válido.', 422);
        $reportId = (int) $match[1];
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id, status FROM feedback_reports WHERE id = ? AND company_id = ? FOR UPDATE');
            $lock->execute([$reportId, (int) $user['company_id']]);
            $report = $lock->fetch();
            if (!$report) fail('Reporte no encontrado.', 404);
            $pdo->prepare('UPDATE feedback_reports SET status = ?, status_changed_at = NOW(), status_changed_by = ? WHERE id = ?')->execute([$status, (int) $user['id'], $reportId]);
            $pdo->prepare('INSERT INTO audit_logs (company_id, user_id, entity_type, entity_id, action, changes_json) VALUES (?, ?, "feedback_reports", ?, "status", ?)')->execute([(int) $user['company_id'], (int) $user['id'], $reportId, json_encode(['from' => $report['status'], 'to' => $status], JSON_UNESCAPED_UNICODE)]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        respond(['ok' => true, 'report' => ['id' => $reportId, 'status' => $status]]);
    }

    if ($method === 'GET' && preg_match('#^/reports/(\d+)/attachment$#', $path, $match)) {
        $user = requireRole($pdo, ['owner', 'admin']);
        $statement = $pdo->prepare('SELECT attachment_path, attachment_name, attachment_mime, attachment_size FROM feedback_reports WHERE id = ? AND company_id = ?');
        $statement->execute([(int) $match[1], (int) $user['company_id']]);
        $report = $statement->fetch();
        $file = $report && $report['attachment_path'] ? reportStorageDir() . '/' . basename((string) $report['attachment_path']) : '';
        if ($file === '' || !is_file($file)) fail('Adjunto no encontrado.', 404);
        // El tipo sale de la lista permitida al guardar, nunca del nombre del archivo; se descarga como attachment para que no se ejecute en el navegador.
        header('Content-Type: ' . $report['attachment_mime']);
        header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode((string) $report['attachment_name']));
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    fail('Ruta no encontrada.', 404);
} catch (PDOException $error) {
    fail('Error de base de datos.', 500);
} catch (Throwable $error) {
    fail('Error interno del servidor.', 500);
}
