<?php
declare(strict_types=1);

// Órdenes de trabajo (OT) creadas desde el formulario de venta. Las funciones de este archivo no usan la base
// de datos ni la sesión, para poder probarlas con tests/work_orders_test.php.

const WORK_ORDER_MAX_LINES = 200;
const WORK_ORDER_CONCEPT_MAX = 180;
const WORK_ORDER_NOTES_MAX = 255;

// Devuelve un mensaje de error si las líneas no sirven para una OT, o null si son válidas.
function workOrderLinesError(array $lines): ?string
{
    if (!$lines) {
        return 'La OT necesita al menos un artículo.';
    }
    if (count($lines) > WORK_ORDER_MAX_LINES) {
        return 'La OT tiene demasiadas líneas.';
    }
    foreach ($lines as $line) {
        if (!is_array($line)) {
            return 'Línea de OT no válida.';
        }
        if ((float) ($line['quantity'] ?? 0) <= 0) {
            return 'La cantidad de cada línea debe ser mayor que cero.';
        }
        if (trim((string) ($line['concept'] ?? '')) === '') {
            return 'Cada línea de la OT necesita un concepto.';
        }
    }
    return null;
}

// Normaliza las líneas que llegan del formulario: recorta textos, redondea cantidades y descarta campos no usados.
function workOrderNormalizeLines(array $lines): array
{
    $normalized = [];
    foreach ($lines as $line) {
        $normalized[] = [
            'productId' => !empty($line['productId']) ? (int) $line['productId'] : null,
            'concept' => mb_substr(trim((string) $line['concept']), 0, WORK_ORDER_CONCEPT_MAX),
            'quantity' => round((float) $line['quantity'], 3),
            'notes' => ($notes = trim((string) ($line['notes'] ?? ''))) === '' ? null : mb_substr($notes, 0, WORK_ORDER_NOTES_MAX),
        ];
    }
    return $normalized;
}
