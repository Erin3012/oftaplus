<?php
declare(strict_types=1);

// Importa el listado de clientes (.xlsx exportado desde Gesvision) a la tabla customers.
// Uso:
//   php tools/importar_clientes.php ruta/Listado_de_clientes.xlsx --company-id=1 [--dry-run]
// Solo crea y actualiza clientes: nunca borra los que no estén en el Excel.
// Con --dry-run cuenta nuevos, actualizados y sin cambios contra la base, y deshace la transacción: no deja nada escrito.
// Sin configuración de base, --dry-run solo valida el archivo.
// La configuración de la base se lee igual que en tools/importar_boletas.php (variables DB_* o .oftaplus-db.env).

require __DIR__ . '/../api/receipts.php';
require __DIR__ . '/../api/imported_customers.php';

function importerFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function importerDbConfig(): array
{
    $values = [];
    $envFile = getenv('OFTAPLUS_DB_ENV') ?: dirname(__DIR__, 2) . '/.oftaplus-db.env';
    if (is_readable($envFile)) {
        foreach (explode(';', trim((string) file_get_contents($envFile))) as $pair) {
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

$arguments = array_slice($argv, 1);
$file = null;
$companyId = 0;
$dryRun = false;
foreach ($arguments as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (preg_match('/^--company-id=(\d+)$/', $argument, $match) === 1) {
        $companyId = (int) $match[1];
    } elseif (!str_starts_with($argument, '--')) {
        $file = $argument;
    } else {
        importerFail("Opción no reconocida: {$argument}");
    }
}

if ($file === null) {
    importerFail('Indica la ruta del Excel: php tools/importar_clientes.php ruta.xlsx --company-id=1');
}
if ($companyId <= 0) {
    importerFail('Indica la empresa con --company-id=N.');
}

try {
    $rows = receiptReadXlsx($file);
} catch (RuntimeException $error) {
    importerFail($error->getMessage());
}

$header = array_shift($rows) ?? [];
$columns = customerColumnMap($header);
if (is_string($columns)) {
    importerFail($columns);
}

$customers = [];
$errors = [];
$seenCodes = [];
foreach ($rows as $index => $cells) {
    $line = $index + 2;
    $result = customerNormalizeRow($cells, $columns);
    if (isset($result['error'])) {
        // Solo se muestra el número de fila: los datos del cliente no salen a la consola.
        $errors[] = "Fila {$line}: {$result['error']}";
        continue;
    }
    $code = $result['data']['code'];
    if (isset($seenCodes[$code])) {
        $errors[] = "Fila {$line}: código repetido en el Excel.";
        continue;
    }
    $seenCodes[$code] = true;
    $customers[] = $result['data'];
}

echo 'Clientes válidos: ' . count($customers) . PHP_EOL;
echo 'Filas rechazadas: ' . count($errors) . PHP_EOL;
foreach ($errors as $error) {
    echo '  - ' . $error . PHP_EOL;
}

$config = importerDbConfig();
if ($config['name'] === '' || $config['user'] === '') {
    if ($dryRun) {
        echo 'Sin configuración de base: solo se validó el archivo.' . PHP_EOL;
        exit(0);
    }
    importerFail('Falta la configuración de la base (DB_NAME, DB_USER, DB_PASS).');
}

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4",
        $config['user'],
        $config['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->beginTransaction();
    $counts = customerSave($pdo, $companyId, $customers);
    if ($dryRun) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
} catch (PDOException $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    importerFail('No se pudo guardar la importación: ' . $error->getMessage());
}

echo "Nuevos: {$counts['inserted']} · Actualizados: {$counts['updated']} · Sin cambios: {$counts['unchanged']}" . PHP_EOL;
echo $dryRun
    ? 'Prueba sin cambios: no se escribió nada en la base.' . PHP_EOL
    : 'Importación guardada.' . PHP_EOL;
