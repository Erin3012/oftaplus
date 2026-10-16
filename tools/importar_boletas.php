<?php
declare(strict_types=1);

// Importa el listado de boletas (.xlsx exportado desde Gesvision) a la tabla sales_receipts.
// Uso:
//   php tools/importar_boletas.php ruta/Listado_de_boletas.xlsx --company-id=1 [--dry-run]
// Con --dry-run solo valida el archivo y no escribe en la base.
// La configuración de la base se lee igual que en api/index.php (variables DB_* o .oftaplus-db.env).

require __DIR__ . '/../api/receipts.php';

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
    importerFail('Indica la ruta del Excel: php tools/importar_boletas.php ruta.xlsx --company-id=1');
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
$columns = receiptColumnMap($header);
if (is_string($columns)) {
    importerFail($columns);
}

$receipts = [];
$skipped = 0;
$errors = [];
foreach ($rows as $index => $cells) {
    $seriesNumber = trim((string) ($cells[$columns['series_number']] ?? ''));
    // Las filas sin serie son el total del listado, no boletas.
    if ($seriesNumber === '') {
        $skipped++;
        continue;
    }
    $result = receiptNormalizeRow($cells, $columns);
    if (isset($result['error'])) {
        // Solo se muestra la serie: el nombre del cliente no sale a la consola.
        $errors[] = 'Fila ' . ($index + 2) . " ({$seriesNumber}): {$result['error']}";
        continue;
    }
    $receipts[] = $result['data'];
}

echo 'Boletas válidas: ' . count($receipts) . PHP_EOL;
echo "Filas sin serie (totales u otras): {$skipped}" . PHP_EOL;
echo 'Filas rechazadas: ' . count($errors) . PHP_EOL;
foreach ($errors as $error) {
    echo '  - ' . $error . PHP_EOL;
}

if ($dryRun) {
    echo 'Prueba sin cambios: no se escribió nada en la base.' . PHP_EOL;
    exit(0);
}

$config = importerDbConfig();
if ($config['name'] === '' || $config['user'] === '') {
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
    $counts = receiptSave($pdo, $companyId, $receipts);
    $pdo->commit();
} catch (PDOException $error) {
    importerFail('No se pudo guardar la importación: ' . $error->getMessage());
}

echo "Insertadas: {$counts['inserted']} · Actualizadas: {$counts['updated']} · Sin cambios: {$counts['unchanged']}" . PHP_EOL;
