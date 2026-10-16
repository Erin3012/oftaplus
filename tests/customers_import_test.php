<?php
declare(strict_types=1);

/**
 * Pruebas del importador de clientes (api/imported_customers.php).
 * No requiere base de datos: ejecutar con `php tests/customers_import_test.php`.
 * Usa solo datos inventados; nunca datos reales de clientes.
 */

require __DIR__ . '/../api/imported_customers.php';

$passed = 0;
$failed = 0;

function check(string $description, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
        $failed++;
    }
}

echo "1. Columnas del encabezado\n";
$header = ['Código', 'Razón social', 'NIE', 'Tlfn. Móvil', 'Email', 'Domicilio'];
$columns = customerColumnMap($header);
check('Encabezado completo', is_array($columns) && $columns['code'] === 0 && $columns['address'] === 5);
$missing = customerColumnMap(['Código', 'Razón social']);
check('Falta una columna', is_string($missing) && str_contains($missing, 'NIE'), is_string($missing) ? $missing : 'sin error');

echo "2. Normalización de filas\n";
$cells = [0 => '10002843', 1 => 'JUAN  PEREZ   SOTO', 2 => '11111111-1', 3 => ' 987654321 ', 4 => null, 5 => ''];
$result = customerNormalizeRow($cells, $columns);
check('Fila válida', isset($result['data']));
check('Espacios repetidos colapsados', ($result['data']['name'] ?? '') === 'JUAN PEREZ SOTO');
check('Teléfono recortado', ($result['data']['phone'] ?? '') === '987654321');
check('Email vacío queda en null', array_key_exists('email', $result['data'] ?? []) && $result['data']['email'] === null);
check('Domicilio vacío queda en null', array_key_exists('address', $result['data'] ?? []) && $result['data']['address'] === null);

$noName = customerNormalizeRow([0 => '10002844', 1 => '   '], $columns);
check('Sin razón social se rechaza', isset($noName['error']));

$longName = customerNormalizeRow([0 => '1', 1 => str_repeat('A', 181)], $columns);
check('Razón social demasiado larga se rechaza', isset($longName['error']));

echo "\nResultado: {$passed} correctas, {$failed} fallidas\n";
exit($failed === 0 ? 0 : 1);
