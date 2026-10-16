<?php
declare(strict_types=1);

// Base de boletas importada desde el listado Excel de Gesvision. Las reglas no dependen de la sesión,
// para probarlas con tests/receipts_test.php sin base de datos.

const RECEIPT_SHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
// Clave interna => encabezado exacto de la columna en el Excel de origen.
const RECEIPT_COLUMNS = [
    'series_number' => 'Serie y número',
    'branch' => 'Sucursal',
    'customer' => 'Cliente',
    'status' => 'Estado',
    'paid' => 'Total pagado',
    'pending' => 'Pdt. pago',
    'discount' => 'Total -> Dto.',
    'total' => 'Total',
    'created_at' => 'Fecha creación',
    'worker' => 'Nombre trabajador',
];
// Tolerancia en pesos para cuadrar pagado + pendiente con el total.
const RECEIPT_TOTAL_TOLERANCE = 0.5;

// Lee la primera hoja de un .xlsx con ZipArchive y DOM, sin librerías externas.
// Devuelve filas como listas indexadas por columna (A = 0); las filas vacías se omiten.
function receiptReadXlsx(string $path): array
{
    $zip = new ZipArchive();
    if (!is_file($path) || $zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el archivo Excel.');
    }
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('El Excel no tiene una primera hoja en xl/worksheets/sheet1.xml.');
    }

    $shared = [];
    if ($sharedXml !== false) {
        foreach (receiptXmlDocument($sharedXml)->getElementsByTagNameNS(RECEIPT_SHEET_NS, 'si') as $item) {
            $shared[] = trim($item->textContent);
        }
    }

    $rows = [];
    foreach (receiptXmlDocument($sheetXml)->getElementsByTagNameNS(RECEIPT_SHEET_NS, 'row') as $rowNode) {
        $cells = [];
        foreach ($rowNode->getElementsByTagNameNS(RECEIPT_SHEET_NS, 'c') as $cell) {
            $index = receiptColumnIndex($cell->getAttribute('r'));
            $cells[$index] = receiptCellValue($cell, $shared);
        }
        if (array_filter($cells, fn($value) => $value !== null && $value !== '') !== []) {
            $rows[] = $cells;
        }
    }
    return $rows;
}

function receiptXmlDocument(string $xml): DOMDocument
{
    $dom = new DOMDocument();
    if (!$dom->loadXML($xml, LIBXML_NONET)) {
        throw new RuntimeException('El archivo Excel está dañado.');
    }
    return $dom;
}

// "AA12" => 26. Solo importa la letra de la columna.
function receiptColumnIndex(string $reference): int
{
    preg_match('/^[A-Z]+/', $reference, $match);
    $index = 0;
    foreach (str_split($match[0] ?? 'A') as $letter) {
        $index = $index * 26 + (ord($letter) - 64);
    }
    return $index - 1;
}

function receiptCellValue(DOMElement $cell, array $shared): string|float|null
{
    $type = $cell->getAttribute('t');
    // Excel guarda el texto en línea con saltos de línea entre etiquetas: se recorta.
    if ($type === 'inlineStr') {
        return trim($cell->textContent);
    }
    $value = $cell->getElementsByTagNameNS(RECEIPT_SHEET_NS, 'v')->item(0)?->textContent;
    if ($value === null || $value === '') {
        return null;
    }
    if ($type === 's') {
        return isset($shared[(int) $value]) ? trim($shared[(int) $value]) : null;
    }
    if ($type === 'str' || $type === 'b' || $type === 'e') {
        return trim($value);
    }
    return (float) $value;
}

// Devuelve el índice de cada columna o un mensaje de error si falta alguna.
function receiptColumnMap(array $header): array|string
{
    $columns = [];
    foreach (RECEIPT_COLUMNS as $key => $label) {
        $found = array_search($label, $header, true);
        if ($found === false) {
            return "Falta la columna \"{$label}\" en el Excel.";
        }
        $columns[$key] = $found;
    }
    return $columns;
}

// "2026/002351" => ['series' => '2026', 'number' => 2351]
function receiptSeriesAndNumber(string $value): ?array
{
    if (preg_match('/^(\d{4})\/(\d{1,10})$/', trim($value), $match) !== 1) {
        return null;
    }
    return ['series' => $match[1], 'number' => (int) $match[2]];
}

// Excel guarda las fechas como días desde 1899-12-30 con la hora como fracción.
function receiptExcelSerialToDateTime(float $serial): string
{
    $days = (int) floor($serial);
    $seconds = (int) round(($serial - $days) * 86400);
    $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
    return $base->modify("+{$days} days")->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
}

function receiptDateTime(string|float|null $value): ?string
{
    if (is_float($value)) {
        return receiptExcelSerialToDateTime($value);
    }
    $text = trim((string) $value);
    foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $text, new DateTimeZone('UTC'));
        if ($date !== false) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    return null;
}

// Valida una fila y la deja lista para guardar. Devuelve ['data' => ...] o ['error' => ...].
// Los errores no incluyen nombres de clientes para poder mostrarlos en consola.
function receiptNormalizeRow(array $cells, array $columns): array
{
    $cell = static fn(string $key): string|float|null => $cells[$columns[$key]] ?? null;

    $number = receiptSeriesAndNumber((string) $cell('series_number'));
    if ($number === null) {
        return ['error' => 'serie y número no válidos'];
    }

    $amounts = [];
    foreach (['paid', 'pending', 'discount', 'total'] as $key) {
        $value = $cell($key);
        if (!is_float($value)) {
            return ['error' => "importe no numérico en {$key}"];
        }
        $amounts[$key] = round($value, 2);
    }
    if (abs($amounts['paid'] + $amounts['pending'] - $amounts['total']) > RECEIPT_TOTAL_TOLERANCE) {
        return ['error' => 'total pagado + pendiente no cuadra con el total'];
    }

    $createdAt = receiptDateTime($cell('created_at'));
    if ($createdAt === null) {
        return ['error' => 'fecha de creación no válida'];
    }

    $status = strtoupper(trim((string) $cell('status')));
    if ($status === '') {
        return ['error' => 'estado vacío'];
    }

    return ['data' => [
        'series' => $number['series'],
        'number' => $number['number'],
        'branch_name' => receiptCleanText($cell('branch')),
        'customer_name' => receiptCleanText($cell('customer')),
        'status' => $status,
        'paid_total' => $amounts['paid'],
        'pending_total' => $amounts['pending'],
        'discount_total' => $amounts['discount'],
        'total' => $amounts['total'],
        'created_at' => $createdAt,
        'worker_name' => receiptCleanText($cell('worker')) ?: null,
    ]];
}

// El Excel trae dobles espacios en nombres ("JOSE  RODRIGUEZ"); se colapsan para comparar y mostrar.
function receiptCleanText(string|float|null $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
}

function receiptUpsertSql(): string
{
    return 'INSERT INTO sales_receipts (company_id, series, number, branch_name, customer_name, status, paid_total, pending_total, discount_total, total, created_at, worker_name)'
        . ' VALUES (:company_id, :series, :number, :branch_name, :customer_name, :status, :paid_total, :pending_total, :discount_total, :total, :created_at, :worker_name)'
        . ' ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name), customer_name = VALUES(customer_name), status = VALUES(status),'
        . ' paid_total = VALUES(paid_total), pending_total = VALUES(pending_total), discount_total = VALUES(discount_total),'
        . ' total = VALUES(total), created_at = VALUES(created_at), worker_name = VALUES(worker_name)';
}

// Guarda filas ya normalizadas. Con MySQL, rowCount() es 1 si inserta, 2 si actualiza y 0 si no cambia nada.
function receiptSave(PDO $pdo, int $companyId, array $receipts): array
{
    $statement = $pdo->prepare(receiptUpsertSql());
    $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
    foreach ($receipts as $receipt) {
        $statement->execute([':company_id' => $companyId] + array_combine(
            array_map(fn($key) => ':' . $key, array_keys($receipt)),
            array_values($receipt)
        ));
        match ($statement->rowCount()) {
            1 => $counts['inserted']++,
            2 => $counts['updated']++,
            default => $counts['unchanged']++,
        };
    }
    return $counts;
}
