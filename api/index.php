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
        $where = 'FROM customers WHERE active = 1 AND (name LIKE ? OR code LIKE ? OR email LIKE ? OR tax_id LIKE ? OR phone LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like, $like];
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
        $net = round((float) ($input['netAmount'] ?? 0), 2);
        if ($net <= 0) fail('El neto debe ser mayor que cero.', 422);
        $taxRate = max((float) ($input['taxRate'] ?? 19), 0);
        $tax = round($net * $taxRate / 100, 2);
        $issueDate = trim((string) ($input['issueDate'] ?? date('Y-m-d')));
        $date = DateTime::createFromFormat('!Y-m-d', $issueDate);
        if (!$date || $date->format('Y-m-d') !== $issueDate) fail('Fecha inválida. Usa AAAA-MM-DD.', 422);
        $typeStatement = $pdo->prepare('SELECT id FROM document_types WHERE company_id = ? AND code = "FAR" LIMIT 1');
        $typeStatement->execute([(int) $company['id']]);
        $typeId = (int) $typeStatement->fetchColumn();
        if (!$typeId) fail('Tipo de documento no configurado.', 422);
        $metadata = json_encode(['externalRef' => trim((string) ($input['externalRef'] ?? ''))], JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO documents (company_id, document_type_id, status, supplier_name, issue_date, subtotal, tax_total, total, notes, metadata_json) VALUES (?, ?, "issued", ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int) $company['id'], $typeId, $supplierName, $issueDate, $net, $tax, $net + $tax, (string) ($input['notes'] ?? ''), $metadata]);
        respond(['ok' => true, 'invoice' => ['id' => (int) $pdo->lastInsertId(), 'supplier' => $supplierName, 'net' => $net, 'tax' => $tax, 'total' => $net + $tax]], 201);
    }

    if ($method === 'GET' && $path === '/transfers') {
        $company = tenant($pdo);
        $statement = $pdo->prepare('SELECT st.id, o.name AS origin, de.name AS destination, st.transfer_type, st.status, st.created_at, (SELECT COALESCE(SUM(quantity), 0) FROM stock_transfer_lines l WHERE l.transfer_id = st.id) AS quantity FROM stock_transfers st JOIN stock_locations o ON o.id = st.origin_location_id JOIN stock_locations de ON de.id = st.destination_location_id WHERE st.company_id = ? ORDER BY st.created_at DESC LIMIT 200');
        $statement->execute([(int) $company['id']]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
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
        $statement = $pdo->prepare('SELECT ic.id, ic.notes, sl.name AS location, ic.status, ic.created_at, ic.closed_at, COALESCE(SUM(l.counted_quantity), 0) AS counted_quantity, COALESCE(SUM(l.counted_quantity = l.expected_quantity), 0) AS matched_lines, COUNT(l.id) AS lines FROM inventory_counts ic JOIN stock_locations sl ON sl.id = ic.location_id LEFT JOIN inventory_count_lines l ON l.inventory_count_id = ic.id WHERE ic.company_id = ? GROUP BY ic.id ORDER BY ic.created_at DESC LIMIT 200');
        $statement->execute([(int) $company['id']]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
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

    if ($method === 'GET' && $path === '/stock') {
        $company = tenant($pdo);
        $filter = (string) ($_GET['filter'] ?? '');
        if (!in_array($filter, ['', 'available', 'zero'], true)) fail('Filtro no válido.', 422);
        $sql = 'SELECT * FROM (SELECT p.id, p.sku, p.name, p.description, p.cost_amount, COALESCE((SELECT amount FROM product_prices pp WHERE pp.product_id = p.id ORDER BY pp.valid_from DESC, pp.id DESC LIMIT 1), 0) AS price_amount, COALESCE(SUM(CASE sm.movement_type WHEN "out" THEN -ABS(sm.quantity) ELSE sm.quantity END), 0) AS quantity, MAX(CASE WHEN sm.movement_type = "in" THEN sm.occurred_at END) AS last_entry FROM products p LEFT JOIN stock_movements sm ON sm.product_id = p.id AND sm.company_id = p.company_id WHERE p.company_id = ? AND p.active = 1 GROUP BY p.id) stock';
        if ($filter === 'available') $sql .= ' WHERE quantity > 0';
        if ($filter === 'zero') $sql .= ' WHERE quantity = 0';
        $statement = $pdo->prepare($sql . ' ORDER BY name LIMIT 500');
        $statement->execute([(int) $company['id']]);
        $items = $statement->fetchAll();
        $units = 0.0; $costValue = 0.0; $priceValue = 0.0;
        foreach ($items as $item) {
            $quantity = max((float) $item['quantity'], 0);
            $units += $quantity; $costValue += $quantity * (float) $item['cost_amount']; $priceValue += $quantity * (float) $item['price_amount'];
        }
        respond(['ok' => true, 'items' => $items, 'summary' => ['units' => $units, 'costValue' => round($costValue, 2), 'priceValue' => round($priceValue, 2)]]);
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

    if ($method === 'POST' && preg_match('#^/documents/(\d+)/issue$#', $path, $match)) {
        $company = tenant($pdo);
        $documentId = (int) $match[1];
        $pdo->beginTransaction();
        try {
            $documentStatement = $pdo->prepare('SELECT id, status, document_type_id FROM documents WHERE id = ? AND company_id = ? FOR UPDATE');
            $documentStatement->execute([$documentId, (int) $company['id']]);
            $document = $documentStatement->fetch();
            if (!$document) fail('Documento no encontrado.', 404);
            if ($document['status'] !== 'draft') fail('Solo se puede emitir un borrador.', 409);
            $linesStatement = $pdo->prepare('SELECT COUNT(*) FROM document_lines WHERE document_id = ?');
            $linesStatement->execute([$documentId]);
            if ((int) $linesStatement->fetchColumn() === 0) fail('El documento no tiene líneas.', 422);
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

    if ($method === 'GET' && $path === '/payments') {
        $company = tenant($pdo);
        $from = dateParam('from', date('Y-m-d', strtotime('-6 months')));
        $to = dateParam('to', date('Y-m-d'));
        if ($from > $to) fail('La fecha inicial no puede ser posterior a la final.', 422);
        $limit = min(max((int) ($_GET['limit'] ?? 50), 1), 500);
        $statement = $pdo->prepare('SELECT p.id, p.amount, p.paid_at, p.created_at, p.reference, d.id AS document_id, d.number AS document_number, c.name AS customer_name, pm.name AS payment_method FROM payments p JOIN documents d ON d.id = p.document_id LEFT JOIN customers c ON c.id = d.customer_id JOIN payment_methods pm ON pm.id = p.payment_method_id WHERE p.company_id = ? AND DATE(p.paid_at) BETWEEN ? AND ? ORDER BY p.paid_at DESC, p.id DESC LIMIT ' . $limit);
        $statement->execute([(int) $company['id'], $from, $to]);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/payments') {
        $input = body();
        $company = tenant($pdo);
        $documentId = (int) ($input['documentId'] ?? 0);
        $methodId = (int) ($input['paymentMethodId'] ?? 0);
        $amount = round((float) ($input['amount'] ?? 0), 2);
        if ($amount <= 0) fail('El importe debe ser mayor que cero.', 422);
        $pdo->beginTransaction();
        try {
            $documentStatement = $pdo->prepare('SELECT id, status, total, paid_total FROM documents WHERE id = ? AND company_id = ? FOR UPDATE');
            $documentStatement->execute([$documentId, (int) $company['id']]);
            $document = $documentStatement->fetch();
            if (!$document) fail('Documento no encontrado.', 404);
            if ($document['status'] === 'cancelled') fail('No se puede cobrar un documento anulado.', 422);
            $pending = round((float) $document['total'] - (float) $document['paid_total'], 2);
            if ($amount > $pending) fail('El importe supera lo pendiente de pago.', 422);
            $methodStatement = $pdo->prepare('SELECT id FROM payment_methods WHERE id = ? AND company_id = ? AND active = 1');
            $methodStatement->execute([$methodId, (int) $company['id']]);
            if (!$methodStatement->fetchColumn()) fail('Forma de pago no válida.', 422);
            $pdo->prepare('INSERT INTO payments (company_id, document_id, payment_method_id, amount, paid_at, reference, notes) VALUES (?, ?, ?, ?, NOW(), ?, ?)')
                ->execute([(int) $company['id'], $documentId, $methodId, $amount, $input['reference'] ?? null, $input['notes'] ?? null]);
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

    if ($method === 'GET' && $path === '/cash-closures') {
        $company = tenant($pdo);
        $status = (string) ($_GET['status'] ?? '');
        $sql = 'SELECT cc.id, cr.name AS cash_register, b.name AS branch, cc.opened_at, cc.closed_at, cc.opening_amount, cc.closing_amount, cc.status, (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.company_id = cr.company_id AND p.paid_at >= cc.opened_at AND p.paid_at <= COALESCE(cc.closed_at, NOW())) AS calculated_amount FROM cash_closures cc JOIN cash_registers cr ON cr.id = cc.cash_register_id LEFT JOIN branches b ON b.id = cr.branch_id WHERE cr.company_id = ?';
        $params = [(int) $company['id']];
        if (in_array($status, ['open', 'closed'], true)) { $sql .= ' AND cc.status = ?'; $params[] = $status; }
        $statement = $pdo->prepare($sql . ' ORDER BY cc.opened_at DESC LIMIT 100');
        $statement->execute($params);
        respond(['ok' => true, 'items' => $statement->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/cash-closures/close') {
        $input = body();
        $company = tenant($pdo);
        $counted = round((float) ($input['countedAmount'] ?? -1), 2);
        if ($counted < 0) fail('Indica el importe del recuento.', 422);
        $pdo->beginTransaction();
        try {
            $registerStatement = $pdo->prepare('SELECT id FROM cash_registers WHERE company_id = ? AND active = 1 ORDER BY id LIMIT 1');
            $registerStatement->execute([(int) $company['id']]);
            $registerId = (int) $registerStatement->fetchColumn();
            if (!$registerId) {
                $pdo->prepare('INSERT INTO cash_registers (company_id, name) VALUES (?, ?)')->execute([(int) $company['id'], 'Caja principal']);
                $registerId = (int) $pdo->lastInsertId();
            }
            $openStatement = $pdo->prepare('SELECT id, opened_at FROM cash_closures WHERE cash_register_id = ? AND status = "open" ORDER BY opened_at LIMIT 1 FOR UPDATE');
            $openStatement->execute([$registerId]);
            $closure = $openStatement->fetch();
            if (!$closure) {
                $lastStatement = $pdo->prepare('SELECT MAX(closed_at) FROM cash_closures WHERE cash_register_id = ?');
                $lastStatement->execute([$registerId]);
                $openedAt = $lastStatement->fetchColumn() ?: date('Y-m-d 00:00:00');
                $pdo->prepare('INSERT INTO cash_closures (cash_register_id, opened_at) VALUES (?, ?)')->execute([$registerId, $openedAt]);
                $closure = ['id' => (int) $pdo->lastInsertId(), 'opened_at' => $openedAt];
            }
            $sumStatement = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE company_id = ? AND paid_at >= ? AND paid_at <= NOW()');
            $sumStatement->execute([(int) $company['id'], $closure['opened_at']]);
            $calculated = round((float) $sumStatement->fetchColumn(), 2);
            $pdo->prepare('UPDATE cash_closures SET closed_at = NOW(), closing_amount = ?, status = "closed" WHERE id = ?')->execute([$counted, (int) $closure['id']]);
            $pdo->commit();
            respond(['ok' => true, 'closure' => ['id' => (int) $closure['id'], 'openedAt' => $closure['opened_at'], 'calculatedAmount' => $calculated, 'countedAmount' => $counted, 'difference' => round($counted - $calculated, 2), 'status' => 'closed']], 201);
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
