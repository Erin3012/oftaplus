<?php
declare(strict_types=1);

// Lógica del importador del listado de clientes (.xlsx exportado desde Gesvision) a la tabla customers.
// La usa tools/importar_clientes.php y tests/customers_import_test.php.

// Columnas del listado: clave interna => encabezado en el Excel.
const CUSTOMER_IMPORT_COLUMNS = [
    'code' => 'Código',
    'name' => 'Razón social',
    'tax_id' => 'NIE',
    'phone' => 'Tlfn. Móvil',
    'email' => 'Email',
    'address' => 'Domicilio',
];

// Largo máximo de cada campo en la tabla customers.
const CUSTOMER_IMPORT_LIMITS = [
    'code' => 50,
    'name' => 180,
    'tax_id' => 40,
    'phone' => 50,
    'email' => 190,
    'address' => 255,
];

// Devuelve la columna de cada campo o un mensaje de error si falta alguna.
function customerColumnMap(array $header): array|string
{
    $labels = array_map(fn($value) => trim((string) $value), $header);
    $columns = [];
    foreach (CUSTOMER_IMPORT_COLUMNS as $key => $label) {
        $index = array_search($label, $labels, true);
        if ($index === false) {
            return "Falta la columna «{$label}» en el Excel.";
        }
        $columns[$key] = $index;
    }
    return $columns;
}

// Valida una fila. Los textos se recortan y los espacios repetidos se colapsan.
// Un código o razón social vacíos rechazan la fila; los demás campos vacíos quedan en null.
function customerNormalizeRow(array $cells, array $columns): array
{
    $text = static fn(string $key): string => trim((string) preg_replace('/\s+/u', ' ', (string) ($cells[$columns[$key]] ?? '')));
    $optional = static fn(string $key): ?string => ($value = $text($key)) === '' ? null : $value;

    $data = [
        'code' => $text('code'),
        'name' => $text('name'),
        'tax_id' => $optional('tax_id'),
        'phone' => $optional('phone'),
        'email' => $optional('email'),
        'address' => $optional('address'),
    ];
    if ($data['code'] === '' || $data['name'] === '') {
        return ['error' => 'Falta el código o la razón social.'];
    }
    foreach (CUSTOMER_IMPORT_LIMITS as $key => $limit) {
        if ($data[$key] !== null && mb_strlen($data[$key]) > $limit) {
            return ['error' => "El campo {$key} supera {$limit} caracteres."];
        }
    }
    return ['data' => $data];
}

function customerUpsertSql(): string
{
    // Los campos vacíos del Excel no borran los datos ya cargados en la app.
    // active no se toca: un cliente desactivado a mano no vuelve a activarse por importar.
    return 'INSERT INTO customers (company_id, code, name, customer_type, tax_id, email, phone, address, active)'
        . " VALUES (:company_id, :code, :name, 'person', :tax_id, :email, :phone, :address, 1)"
        . ' ON DUPLICATE KEY UPDATE name = VALUES(name), tax_id = COALESCE(VALUES(tax_id), tax_id),'
        . ' email = COALESCE(VALUES(email), email), phone = COALESCE(VALUES(phone), phone),'
        . ' address = COALESCE(VALUES(address), address)';
}

// Guarda clientes ya normalizados y solo crea o actualiza; nunca borra.
// Con MySQL, rowCount() es 1 si inserta, 2 si actualiza y 0 si no cambia nada.
function customerSave(PDO $pdo, int $companyId, array $customers): array
{
    $statement = $pdo->prepare(customerUpsertSql());
    $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
    foreach ($customers as $customer) {
        $statement->execute([':company_id' => $companyId] + array_combine(
            array_map(fn($key) => ':' . $key, array_keys($customer)),
            array_values($customer)
        ));
        match ($statement->rowCount()) {
            1 => $counts['inserted']++,
            2 => $counts['updated']++,
            default => $counts['unchanged']++,
        };
    }
    return $counts;
}
