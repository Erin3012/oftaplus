<?php
declare(strict_types=1);

/**
 * Pruebas de las reglas de Órdenes de trabajo (api/work_orders.php).
 * No requiere base de datos: ejecutar con `php tests/work_orders_test.php`.
 * 1. La OT necesita al menos una línea
 * 2. Cada línea necesita concepto y cantidad mayor que cero
 * 3. Límite de líneas
 * 4. Normalización: recorte de textos, cantidades redondeadas y notas vacías como null
 */

require __DIR__ . '/../api/work_orders.php';

$passed = 0;
$failed = 0;

function check(string $description, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  OK    {$description}\n";
        return;
    }
    $failed++;
    echo "  FALLA {$description}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

echo "1. Líneas obligatorias\n";
check('Sin líneas se rechaza', workOrderLinesError([]) !== null);

echo "2. Concepto y cantidad\n";
check('Línea válida se acepta', workOrderLinesError([['concept' => 'Lente', 'quantity' => 1]]) === null);
check('Cantidad cero se rechaza', workOrderLinesError([['concept' => 'Lente', 'quantity' => 0]]) !== null);
check('Cantidad negativa se rechaza', workOrderLinesError([['concept' => 'Lente', 'quantity' => -2]]) !== null);
check('Cantidad ausente se rechaza', workOrderLinesError([['concept' => 'Lente']]) !== null);
check('Concepto vacío se rechaza', workOrderLinesError([['concept' => '   ', 'quantity' => 1]]) !== null);
check('Línea que no es arreglo se rechaza', workOrderLinesError(['texto']) !== null);

echo "3. Límite de líneas\n";
$many = array_fill(0, WORK_ORDER_MAX_LINES + 1, ['concept' => 'Lente', 'quantity' => 1]);
check('Más líneas que el límite se rechaza', workOrderLinesError($many) !== null);
check('Exactamente el límite se acepta', workOrderLinesError(array_slice($many, 0, WORK_ORDER_MAX_LINES)) === null);

echo "4. Normalización\n";
$normalized = workOrderNormalizeLines([
    ['productId' => '12', 'concept' => '  Montura demo  ', 'quantity' => '1.23456', 'notes' => '  '],
    ['concept' => str_repeat('a', 200), 'quantity' => 2, 'notes' => 'Revisar'],
]);
check('Producto se convierte a entero', $normalized[0]['productId'] === 12);
check('Concepto se recorta', $normalized[0]['concept'] === 'Montura demo');
check('Cantidad se redondea a 3 decimales', $normalized[0]['quantity'] === 1.235);
check('Notas vacías quedan en null', $normalized[0]['notes'] === null);
check('Línea sin producto queda con productId null', $normalized[1]['productId'] === null);
check('Concepto se limita al máximo', mb_strlen($normalized[1]['concept']) === WORK_ORDER_CONCEPT_MAX);
check('Notas se conservan', $normalized[1]['notes'] === 'Revisar');

echo "\n=====================================================\n";
echo " RESULTADO: {$passed} aprobadas, {$failed} fallidas\n";
echo "=====================================================\n";
exit($failed === 0 ? 0 : 1);
