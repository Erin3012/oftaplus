<?php
declare(strict_types=1);

/**
 * Pruebas de la base de boletas importada (api/receipts.php).
 * No requiere base de datos: ejecutar con `php tests/receipts_test.php`.
 * Usa solo datos inventados; nunca datos reales de clientes.
 * 1. Serie y número
 * 2. Fechas de Excel y de texto
 * 3. Columnas del encabezado
 * 4. Normalización y validación de filas
 * 5. Lectura de un .xlsx generado en la prueba
 */

require __DIR__ . '/../api/receipts.php';

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

echo "=====================================================\n";
echo " OFTAPLUS BASE DE BOLETAS - PRUEBAS DE REGLAS\n";
echo "=====================================================\n\n";

echo "1. Serie y número\n";
check('separa serie y número', receiptSeriesAndNumber('2026/002351') === ['series' => '2026', 'number' => 2351]);
check('acepta número sin ceros a la izquierda', receiptSeriesAndNumber('2026/1193') === ['series' => '2026', 'number' => 1193]);
check('rechaza separador distinto de barra', receiptSeriesAndNumber('2026-002351') === null);
check('rechaza texto vacío', receiptSeriesAndNumber('') === null);
check('rechaza número sin serie', receiptSeriesAndNumber('002351') === null);
check('columna Excel AA indica 26', receiptColumnIndex('AA12') === 26);
check('columna Excel A indica 0', receiptColumnIndex('A1') === 0);

echo "\n2. Fechas\n";
check('convierte fecha serial de Excel con hora', receiptExcelSerialToDateTime(46310.771388888825) === '2026-10-15 18:30:48');
check('convierte fecha serial sin hora', receiptExcelSerialToDateTime(46310.0) === '2026-10-15 00:00:00');
check('acepta fecha dd/mm/aaaa hh:mm', receiptDateTime('15/10/2026 18:30') === '2026-10-15 18:30:00');
check('acepta fecha ISO', receiptDateTime('2026-10-15 18:30:48') === '2026-10-15 18:30:48');
check('rechaza fecha vacía', receiptDateTime(null) === null);
check('rechaza fecha inválida', receiptDateTime('ayer') === null);

echo "\n3. Columnas del encabezado\n";
$header = [0 => 'Serie y número', 1 => 'Sucursal', 2 => 'Cliente', 3 => 'Estado', 4 => 'Total pagado', 5 => 'Pdt. pago', 6 => 'Total -> Dto.', 7 => 'Total', 8 => 'Fecha creación', 9 => 'Nombre trabajador'];
$columns = receiptColumnMap($header);
check('reconoce el encabezado completo', is_array($columns) && $columns['discount'] === 6 && $columns['worker'] === 9);
$missing = $header;
unset($missing[5]);
$columnsError = receiptColumnMap($missing);
check('avisa de una columna faltante', is_string($columnsError) && str_contains($columnsError, 'Pdt. pago'));

echo "\n4. Normalización de filas\n";
$cells = [
    0 => '2026/002317', 1 => 'OPTICA VISION 360 SP', 2 => 'MARIELA  BENEVENTI BELMAR', 3 => 'PENDIENTE',
    4 => 60000.0, 5 => 60000.0, 6 => 0.0, 7 => 120000.0, 8 => 46309.751388888825, 9 => null,
];
$ok = receiptNormalizeRow($cells, $columns);
check('acepta una fila válida', isset($ok['data']));
check('colapsa dobles espacios en el nombre', ($ok['data']['customer_name'] ?? '') === 'MARIELA BENEVENTI BELMAR');
check('guarda la serie y el número separados', ($ok['data']['series'] ?? '') === '2026' && ($ok['data']['number'] ?? 0) === 2317);
check('deja el trabajador en null si está vacío', array_key_exists('worker_name', $ok['data'] ?? []) && $ok['data']['worker_name'] === null);
check('guarda el estado en mayúsculas', ($ok['data']['status'] ?? '') === 'PENDIENTE');

$mismatch = $cells;
$mismatch[7] = 999999.0;
check('rechaza si pagado + pendiente no cuadra con el total', isset(receiptNormalizeRow($mismatch, $columns)['error']));

$noDate = $cells;
$noDate[8] = null;
check('rechaza una fila sin fecha', isset(receiptNormalizeRow($noDate, $columns)['error']));

$textAmount = $cells;
$textAmount[4] = 'sesenta mil';
check('rechaza un importe que no es número', isset(receiptNormalizeRow($textAmount, $columns)['error']));

$badSeries = $cells;
$badSeries[0] = 'BOLETA 1';
check('rechaza una serie no válida', isset(receiptNormalizeRow($badSeries, $columns)['error']));

$error = receiptNormalizeRow($mismatch, $columns)['error'] ?? '';
check('el error no incluye el nombre del cliente', !str_contains($error, 'BENEVENTI'));

echo "\n5. Lectura de un .xlsx generado\n";
$dir = sys_get_temp_dir() . '/oftaplus-receipts-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$xlsx = $dir . '/prueba.xlsx';
$sharedStrings = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="' . RECEIPT_SHEET_NS . '"><si><t>Serie y número</t></si><si><t>Cliente de prueba</t></si></sst>';
$sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . RECEIPT_SHEET_NS . '"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><r><t>Cliente</t></r></is></c></row>'
    . '<row r="2"><c r="A2" t="inlineStr"><is><t>2026/000001</t></is></c><c r="C2" t="s"><v>1</v></c><c r="E2"><v>1500.5</v></c></row>'
    . '<row r="3"/>'
    . '</sheetData></worksheet>';
$zip = new ZipArchive();
$zip->open($xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('xl/sharedStrings.xml', $sharedStrings);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->close();

$rows = receiptReadXlsx($xlsx);
check('lee las filas con texto compartido, texto en línea y números', count($rows) === 2);
check('mapea el texto compartido a su columna', ($rows[1][2] ?? null) === 'Cliente de prueba');
check('mapea el texto en línea a su columna', ($rows[1][0] ?? null) === '2026/000001' && ($rows[0][2] ?? null) === 'Cliente');
check('mapea los números como decimales', ($rows[1][4] ?? null) === 1500.5);
check('omite filas vacías', count($rows) === 2);
try {
    receiptReadXlsx($dir . '/no-existe.xlsx');
    check('avisa de un archivo que no existe', false);
} catch (RuntimeException $exception) {
    check('avisa de un archivo que no existe', true);
}

@unlink($xlsx);
@rmdir($dir);

echo "\n=====================================================\n";
echo " RESULTADO: {$passed} aprobadas, {$failed} fallidas\n";
echo "=====================================================\n";
exit($failed === 0 ? 0 : 1);
