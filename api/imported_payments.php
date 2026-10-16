<?php
declare(strict_types=1);

// Cobros importados desde el listado de cobros emitidos de Gesvision. Las reglas no dependen de la sesión,
// para probarlas con tests/imported_payments_test.php sin base de datos.
// Reutiliza el lector de .xlsx y las funciones de fecha y serie de api/receipts.php.

require_once __DIR__ . '/receipts.php';

// Clave interna => encabezado exacto de la columna en el Excel de origen.
const IMPORTED_PAYMENT_COLUMNS = [
    'receipt' => 'Serie/Nº',
    'customer' => 'Cliente',
    'invoice' => 'Factura',
    'type' => 'Tipo pago',
    'amount' => 'Importe',
    'paid' => 'Fecha',
    'created' => 'Fecha creación',
];

// Devuelve el índice de cada columna o un mensaje de error si falta alguna.
function importedPaymentColumnMap(array $header): array|string
{
    $columns = [];
    foreach (IMPORTED_PAYMENT_COLUMNS as $key => $label) {
        $found = array_search($label, $header, true);
        if ($found === false) {
            return "Falta la columna \"{$label}\" en el Excel.";
        }
        $columns[$key] = $found;
    }
    return $columns;
}

// Valida una fila y la deja lista para guardar. Devuelve ['data' => ...] o ['error' => ...].
// Los errores no incluyen nombres de clientes para poder mostrarlos en consola.
function importedPaymentNormalizeRow(array $cells, array $columns): array
{
    $cell = static fn(string $key): string|float|null => $cells[$columns[$key]] ?? null;

    $receipt = receiptSeriesAndNumber((string) $cell('receipt'));
    if ($receipt === null) {
        return ['error' => 'serie y número no válidos'];
    }

    $amount = $cell('amount');
    if (!is_float($amount)) {
        return ['error' => 'importe no numérico'];
    }

    $paidAt = receiptDateTime($cell('paid'));
    if ($paidAt === null) {
        return ['error' => 'fecha de pago no válida'];
    }

    $createdAt = receiptDateTime($cell('created'));
    if ($createdAt === null) {
        return ['error' => 'fecha de creación no válida'];
    }

    $type = receiptCleanText($cell('type'));
    if ($type === '') {
        return ['error' => 'tipo de pago vacío'];
    }

    $invoice = trim((string) $cell('invoice'));

    return ['data' => [
        'receipt_series' => $receipt['series'],
        'receipt_number' => $receipt['number'],
        'invoice_number' => $invoice !== '' ? $invoice : null,
        'customer_name' => receiptCleanText($cell('customer')),
        'payment_type' => $type,
        'amount' => round($amount, 2),
        'paid_at' => $paidAt,
        'created_at' => $createdAt,
    ]];
}

function importedPaymentUpsertSql(): string
{
    return 'INSERT INTO imported_payments (company_id, receipt_series, receipt_number, invoice_number, customer_name, payment_type, amount, paid_at, created_at)'
        . ' VALUES (:company_id, :receipt_series, :receipt_number, :invoice_number, :customer_name, :payment_type, :amount, :paid_at, :created_at)'
        . ' ON DUPLICATE KEY UPDATE invoice_number = VALUES(invoice_number), customer_name = VALUES(customer_name),'
        . ' payment_type = VALUES(payment_type), amount = VALUES(amount), paid_at = VALUES(paid_at), created_at = VALUES(created_at)';
}

// Guarda filas ya normalizadas. Con MySQL, rowCount() es 1 si inserta, 2 si actualiza y 0 si no cambia nada.
function importedPaymentSave(PDO $pdo, int $companyId, array $payments): array
{
    $statement = $pdo->prepare(importedPaymentUpsertSql());
    $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
    foreach ($payments as $payment) {
        $statement->execute([':company_id' => $companyId] + array_combine(
            array_map(fn($key) => ':' . $key, array_keys($payment)),
            array_values($payment)
        ));
        match ($statement->rowCount()) {
            1 => $counts['inserted']++,
            2 => $counts['updated']++,
            default => $counts['unchanged']++,
        };
    }
    return $counts;
}
