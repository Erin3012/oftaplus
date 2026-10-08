<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

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
        return $pdo;
    } catch (Throwable $error) {
        fail('No se pudo conectar a la base de datos.', 503);
    }
}

function tenant(PDO $pdo): array
{
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

function route(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $apiPosition = strpos($path, '/api');
    return $apiPosition === false ? '/' : (substr($path, $apiPosition + 4) ?: '/');
}

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = route();

    if ($method === 'GET' && $path === '/health') {
        $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
        respond(['ok' => true, 'database' => true, 'tables' => $tables]);
    }

    if ($method === 'GET' && $path === '/products') {
        $q = trim((string) ($_GET['q'] ?? ''));
        $limit = min(max((int) ($_GET['limit'] ?? 20), 1), 50);
        $statement = $pdo->prepare('SELECT id, sku, name, description, tax_rate, cost_amount, COALESCE((SELECT amount FROM product_prices pp WHERE pp.product_id = products.id ORDER BY pp.valid_from DESC, pp.id DESC LIMIT 1), 0) AS price_amount FROM products WHERE active = 1 AND (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT ' . $limit);
        $like = '%' . $q . '%';
        $statement->execute([$like, $like]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'GET' && $path === '/customers') {
        $q = trim((string) ($_GET['q'] ?? ''));
        $limit = min(max((int) ($_GET['limit'] ?? 20), 1), 50);
        $offset = min(max((int) ($_GET['offset'] ?? 0), 0), 1000000);
        $where = 'FROM customers WHERE active = 1 AND (name LIKE ? OR code LIKE ? OR email LIKE ?)';
        $like = '%' . $q . '%';
        $countStatement = $pdo->prepare('SELECT COUNT(*) ' . $where);
        $countStatement->execute([$like, $like, $like]);
        $statement = $pdo->prepare('SELECT id, code, name, email, phone ' . $where . ' ORDER BY name LIMIT ' . $limit . ' OFFSET ' . $offset);
        $statement->execute([$like, $like, $like]);
        respond(['ok' => true, 'items' => $statement->fetchAll(), 'total' => (int) $countStatement->fetchColumn()]);
    }

    if ($method === 'POST' && $path === '/documents/draft') {
        $input = body();
        $company = tenant($pdo);
        $typeCode = strtoupper((string) ($input['type'] ?? 'BOL'));
        $typeStatement = $pdo->prepare('SELECT id FROM document_types WHERE company_id = ? AND code = ? LIMIT 1');
        $typeStatement->execute([(int) $company['id'], $typeCode]);
        $typeId = (int) $typeStatement->fetchColumn();
        if (!$typeId) fail('Tipo de documento no configurado.', 422);
        $customerId = !empty($input['customerId']) ? (int) $input['customerId'] : null;
        $lines = is_array($input['lines'] ?? null) ? $input['lines'] : [];
        $subtotal = 0.0; $discount = 0.0; $tax = 0.0;
        foreach ($lines as $line) {
            $quantity = max((float) ($line['quantity'] ?? 1), 0);
            $unitPrice = max((float) ($line['unitPrice'] ?? 0), 0);
            $discountPercent = min(max((float) ($line['discountPercent'] ?? 0), 0), 100);
            $taxRate = max((float) ($line['taxRate'] ?? 19), 0);
            $gross = $quantity * $unitPrice;
            $lineDiscount = $gross * $discountPercent / 100;
            $net = $gross - $lineDiscount;
            $subtotal += $net; $discount += $lineDiscount; $tax += $net * $taxRate / 100;
        }
        $total = $subtotal + $tax;
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('INSERT INTO documents (company_id, document_type_id, status, customer_id, issue_date, subtotal, discount_total, tax_total, total, notes) VALUES (?, ?, "draft", ?, CURRENT_DATE, ?, ?, ?, ?, ?)');
            $statement->execute([(int) $company['id'], $typeId, $customerId, $subtotal, $discount, $tax, $total, (string) ($input['notes'] ?? '')]);
            $documentId = (int) $pdo->lastInsertId();
            $lineStatement = $pdo->prepare('INSERT INTO document_lines (document_id, product_id, line_number, concept, reference, description, quantity, unit_price, discount_percent, tax_rate, gross_amount, discount_amount, net_amount, tax_amount, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($lines as $index => $line) {
                $quantity = max((float) ($line['quantity'] ?? 1), 0);
                $unitPrice = max((float) ($line['unitPrice'] ?? 0), 0);
                $discountPercent = min(max((float) ($line['discountPercent'] ?? 0), 0), 100);
                $taxRate = max((float) ($line['taxRate'] ?? 19), 0);
                $gross = $quantity * $unitPrice; $lineDiscount = $gross * $discountPercent / 100; $net = $gross - $lineDiscount; $lineTax = $net * $taxRate / 100;
                $lineStatement->execute([$documentId, !empty($line['productId']) ? (int) $line['productId'] : null, $index + 1, (string) ($line['concept'] ?? 'Artículo'), $line['reference'] ?? null, $line['description'] ?? null, $quantity, $unitPrice, $discountPercent, $taxRate, $gross, $lineDiscount, $net, $lineTax, $net + $lineTax]);
            }
            $pdo->commit();
            respond(['ok' => true, 'document' => ['id' => $documentId, 'status' => 'draft', 'subtotal' => $subtotal, 'tax' => $tax, 'total' => $total]], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'GET' && $path === '/inventory-counts') {
        $company = tenant($pdo);
        $status = isset($_GET['status']) ? (string) $_GET['status'] : null;
        $limit = min(max((int) ($_GET['limit'] ?? 50), 1), 100);
        $offset = min(max((int) ($_GET['offset'] ?? 0), 0), 1000000);
        $where = 'WHERE ic.company_id = ?';
        $params = [(int) $company['id']];
        if ($status) {
            $where .= ' AND ic.status = ?';
            $params[] = $status;
        }
        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM inventory_counts ic ' . $where);
        $countStatement->execute($params);
        $statement = $pdo->prepare('SELECT ic.id, ic.status, ic.count_date, ic.started_at, ic.completed_at, ic.closed_at, ic.location_id, COUNT(icl.id) AS line_count, SUM(CASE WHEN icl.quantity_counted IS NOT NULL THEN 1 ELSE 0 END) AS lines_counted FROM inventory_counts ic LEFT JOIN inventory_count_lines icl ON ic.id = icl.count_id ' . $where . ' GROUP BY ic.id ORDER BY ic.count_date DESC, ic.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
        $statement->execute($params);
        respond(['ok' => true, 'items' => $statement->fetchAll(), 'total' => (int) $countStatement->fetchColumn()]);
    }

    if ($method === 'POST' && $path === '/inventory-counts') {
        $input = body();
        $company = tenant($pdo);
        $countDate = (string) ($input['count_date'] ?? date('Y-m-d'));
        $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('INSERT INTO inventory_counts (company_id, location_id, status, count_date, notes) VALUES (?, ?, ?, ?, ?)');
            $statement->execute([(int) $company['id'], $locationId, 'draft', $countDate, (string) ($input['notes'] ?? '')]);
            $countId = (int) $pdo->lastInsertId();
            $productStatement = $pdo->prepare('SELECT DISTINCT p.id FROM products p LEFT JOIN stock_movements sm ON p.id = sm.product_id WHERE p.company_id = ? AND p.active = 1');
            $productStatement->execute([(int) $company['id']]);
            $products = $productStatement->fetchAll();
            $lineStatement = $pdo->prepare('INSERT INTO inventory_count_lines (count_id, product_id, quantity_expected) VALUES (?, ?, (SELECT COALESCE(SUM(CASE WHEN movement_type IN ("in", "adjustment") THEN quantity WHEN movement_type IN ("out", "transfer") THEN -quantity ELSE 0 END), 0) FROM stock_movements WHERE product_id = ? AND location_id = ? AND company_id = ?))');
            foreach ($products as $product) {
                $lineStatement->execute([$countId, (int) $product['id'], (int) $product['id'], $locationId, (int) $company['id']]);
            }
            $pdo->commit();
            respond(['ok' => true, 'count' => ['id' => $countId, 'status' => 'draft', 'count_date' => $countDate]], 201);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'POST' && preg_match('#^/inventory-count-lines$#', $path)) {
        $input = body();
        $countId = !empty($input['count_id']) ? (int) $input['count_id'] : null;
        $productId = !empty($input['product_id']) ? (int) $input['product_id'] : null;
        $quantityCounted = !empty($input['quantity_counted']) ? (float) $input['quantity_counted'] : null;
        if (!$countId || !$productId) fail('count_id y product_id son requeridos.', 422);
        $statement = $pdo->prepare('UPDATE inventory_count_lines SET quantity_counted = ?, status = ? WHERE count_id = ? AND product_id = ?');
        $statement->execute([$quantityCounted, 'counted', $countId, $productId]);
        if ($statement->rowCount() === 0) {
            $insertStatement = $pdo->prepare('INSERT INTO inventory_count_lines (count_id, product_id, quantity_expected, quantity_counted, status) VALUES (?, ?, 0, ?, ?)');
            $insertStatement->execute([$countId, $productId, $quantityCounted, 'counted']);
        }
        respond(['ok' => true, 'message' => 'Línea de recuento actualizada.']);
    }

    if ($method === 'GET' && preg_match('#^/inventory-counts/(\d+)/lines$#', $path, $matches)) {
        $countId = (int) $matches[1];
        $company = tenant($pdo);
        $statement = $pdo->prepare('SELECT icl.id, icl.product_id, icl.quantity_expected, icl.quantity_counted, icl.quantity_difference, icl.status, p.sku, p.name FROM inventory_count_lines icl JOIN inventory_counts ic ON icl.count_id = ic.id JOIN products p ON icl.product_id = p.id WHERE ic.company_id = ? AND icl.count_id = ? ORDER BY p.name');
        $statement->execute([(int) $company['id'], $countId]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'POST' && preg_match('#^/inventory-counts/(\d+)/complete$#', $path, $matches)) {
        $countId = (int) $matches[1];
        $company = tenant($pdo);
        $input = body();
        $userId = !empty($input['user_id']) ? (int) $input['user_id'] : null;
        $pdo->beginTransaction();
        try {
            $countStatement = $pdo->prepare('SELECT id, location_id FROM inventory_counts WHERE id = ? AND company_id = ?');
            $countStatement->execute([$countId, (int) $company['id']]);
            $count = $countStatement->fetch();
            if (!$count) fail('Recuento no encontrado.', 404);
            $updateStatement = $pdo->prepare('UPDATE inventory_counts SET status = ?, completed_at = NOW(), completed_by = ? WHERE id = ?');
            $updateStatement->execute(['completed', $userId, $countId]);
            $pdo->commit();
            respond(['ok' => true, 'message' => 'Recuento completado.']);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    if ($method === 'POST' && preg_match('#^/inventory-counts/(\d+)/close$#', $path, $matches)) {
        $countId = (int) $matches[1];
        $company = tenant($pdo);
        $input = body();
        $userId = !empty($input['user_id']) ? (int) $input['user_id'] : null;
        $pdo->beginTransaction();
        try {
            $countStatement = $pdo->prepare('SELECT id, location_id FROM inventory_counts WHERE id = ? AND company_id = ?');
            $countStatement->execute([$countId, (int) $company['id']]);
            $count = $countStatement->fetch();
            if (!$count) fail('Recuento no encontrado.', 404);
            $lineStatement = $pdo->prepare('SELECT icl.id, icl.product_id, icl.quantity_difference FROM inventory_count_lines icl WHERE count_id = ? AND icl.quantity_counted IS NOT NULL AND icl.quantity_difference != 0');
            $lineStatement->execute([$countId]);
            $differences = $lineStatement->fetchAll();
            $adjustmentStatement = $pdo->prepare('INSERT INTO inventory_adjustments (company_id, count_id, product_id, location_id, adjustment_quantity, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($differences as $diff) {
                $adjustmentStatement->execute([(int) $company['id'], $countId, (int) $diff['product_id'], (int) $count['location_id'], (float) $diff['quantity_difference'], 'Ajuste por recuento de inventario', 'pending']);
            }
            $updateStatement = $pdo->prepare('UPDATE inventory_counts SET status = ?, closed_at = NOW(), closed_by = ? WHERE id = ?');
            $updateStatement->execute(['closed', $userId, $countId]);
            $pdo->commit();
            respond(['ok' => true, 'message' => 'Recuento cerrado. Ajustes generados.', 'adjustments_count' => count($differences)]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    fail('Ruta no encontrada.', 404);
} catch (PDOException $error) {
    fail('Error de base de datos.', 500);
} catch (Throwable $error) {
    fail('Error interno del servidor.', 500);
}
