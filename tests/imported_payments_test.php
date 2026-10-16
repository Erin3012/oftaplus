<?php
declare(strict_types=1);

/**
 * Pruebas de los cobros importados (api/imported_payments.php).
 * No requiere base de datos: ejecutar con `php tests/imported_payments_test.php`.
 * Usa solo datos inventados; nunca datos reales de clientes.
 * 1. Columnas del encabezado
 * 2. Normalización y validación de filas
 * 3. Lectura de un .xlsx generado en la prueba
 */

require __DIR__ . '/../api/imported_payments.php';

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
echo " OFTAPLUS COBROS IMPORTADOS - PRUEBAS DE REGLAS\n";
echo "=====================================================\n\n";

$header = ['Serie/Nº', 'Cliente', 'Factura', 'Tipo pago', 'Importe', 'Fecha', 'Fecha creación'];

echo "1. Columnas del encabezado\n";
$columns = importedPaymentColumnMap($header);
check('reconoce todas las columnas del listado', is_array($columns) && count($columns) === count(IMPORTED_PAYMENT_COLUMNS));
check('mapea la serie y número a su posición', ($columns['receipt'] ?? null) === 0);
check('avisa si falta una columna', is_string(importedPaymentColumnMap(array_slice($header, 0, 4))));

// Fechas de Excel: 46310.5 = 2026-10-15 12:00 (días desde 1899-12-30).
$excelDate = 46310.5;
$cells = ['2026/000100', 'CLIENTE DE PRUEBA  UNO', '2026/000090', 'TARJETA DEBITO', 25000.0, $excelDate, $excelDate];

echo "\n2. Normalización y validación de filas\n";
$ok = importedPaymentNormalizeRow($cells, $columns);
check('acepta una fila válida', isset($ok['data']));
check('separa serie y número', ($ok['data']['receipt_series'] ?? '') === '2026' && ($ok['data']['receipt_number'] ?? 0) === 100);
check('guarda la factura', ($ok['data']['invoice_number'] ?? null) === '2026/000090');
check('colapsa dobles espacios en el cliente', ($ok['data']['customer_name'] ?? '') === 'CLIENTE DE PRUEBA UNO');
check('guarda el tipo de pago', ($ok['data']['payment_type'] ?? '') === 'TARJETA DEBITO');
check('guarda el importe como decimal', ($ok['data']['amount'] ?? null) === 25000.0);
check('convierte la fecha de Excel a fecha y hora', ($ok['data']['paid_at'] ?? '') === '2026-10-15 12:00:00');
check('convierte la fecha de creación a fecha y hora', ($ok['data']['created_at'] ?? '') === '2026-10-15 12:00:00');

$negative = $cells;
$negative[4] = -5000.0;
$refund = importedPaymentNormalizeRow($negative, $columns);
check('acepta un importe negativo (devolución)', ($refund['data']['amount'] ?? null) === -5000.0);

$noInvoice = $cells;
$noInvoice[2] = '';
$noInvoiceRow = importedPaymentNormalizeRow($noInvoice, $columns);
check('deja la factura en null si está vacía', array_key_exists('invoice_number', $noInvoiceRow['data'] ?? []) && $noInvoiceRow['data']['invoice_number'] === null);

$badSeries = $cells;
$badSeries[0] = 'COBRO 1';
check('rechaza una serie no válida', isset(importedPaymentNormalizeRow($badSeries, $columns)['error']));

$textAmount = $cells;
$textAmount[4] = 'veinticinco mil';
check('rechaza un importe que no es número', isset(importedPaymentNormalizeRow($textAmount, $columns)['error']));

$noDate = $cells;
$noDate[5] = null;
check('rechaza una fila sin fecha de pago', isset(importedPaymentNormalizeRow($noDate, $columns)['error']));

$noType = $cells;
$noType[3] = '   ';
check('rechaza un tipo de pago vacío', isset(importedPaymentNormalizeRow($noType, $columns)['error']));

$error = importedPaymentNormalizeRow($textAmount, $columns)['error'] ?? '';
check('el error no incluye el nombre del cliente', !str_contains($error, 'PRUEBA'));

echo "\n3. Lectura de un .xlsx generado\n";
$dir = sys_get_temp_dir() . '/oftaplus-payments-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$xlsx = $dir . '/prueba.xlsx';
$sharedStrings = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="' . RECEIPT_SHEET_NS . '"><si><t>Serie/Nº</t></si><si><t>Cliente de prueba</t></si></sst>';
$sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="' . RECEIPT_SHEET_NS . '"><sheetData>'
    . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>Cliente</t></is></c></row>'
    . '<row r="2"><c r="A2" t="inlineStr"><is><t>2026/000100</t></is></c><c r="B2" t="s"><v>1</v></c><c r="E2"><v>25000</v></c></row>'
    . '<row r="3"/>'
    . '</sheetData></worksheet>';
$zip = new ZipArchive();
$zip->open($xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('xl/sharedStrings.xml', $sharedStrings);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->close();

$rows = receiptReadXlsx($xlsx);
check('lee las filas del Excel de prueba', count($rows) === 2);
check('mapea el texto compartido a su columna', ($rows[1][1] ?? null) === 'Cliente de prueba');
check('mapea el importe como decimal', ($rows[1][4] ?? null) === 25000.0);

@unlink($xlsx);
@rmdir($dir);

echo "\n=====================================================\n";
echo " RESULTADO: {$passed} aprobadas, {$failed} fallidas\n";
echo "=====================================================\n";
exit($failed === 0 ? 0 : 1);
