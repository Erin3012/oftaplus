<?php
declare(strict_types=1);

/**
 * Pruebas de las reglas de Reportes y sugerencias (api/reports.php).
 * No requiere base de datos: ejecutar con `php tests/reports_test.php`.
 * 1. Tipo y descripción obligatorios, con límite de caracteres
 * 2. Límite de tamaño y errores de subida
 * 3. Tipo de adjunto detectado por contenido (imagen o PDF aceptados; texto y PHP rechazados)
 * 4. Nombre original saneado para mostrar, sin rutas
 * 5. Nombre guardado aleatorio y con extensión derivada del tipo detectado
 */

require __DIR__ . '/../api/reports.php';

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
echo " OFTAPLUS REPORTES Y SUGERENCIAS - PRUEBAS DE REGLAS\n";
echo "=====================================================\n\n";

echo "1. Tipo y descripción\n";
check('acepta reporte con descripción', reportInputError('report', 'La caja no cuadra') === null);
check('acepta sugerencia con descripción', reportInputError('suggestion', 'Agregar filtro por local') === null);
check('rechaza un tipo desconocido', reportInputError('queja', 'Texto') !== null);
check('rechaza un tipo vacío', reportInputError('', 'Texto') !== null);
check('rechaza una descripción vacía o solo espacios', reportInputError('report', '   ') !== null);
check('acepta exactamente 2000 caracteres', reportInputError('report', str_repeat('a', REPORT_DESCRIPTION_MAX)) === null);
check('rechaza más de 2000 caracteres', reportInputError('report', str_repeat('a', REPORT_DESCRIPTION_MAX + 1)) !== null);
check('cuenta caracteres multibyte y no bytes', reportInputError('report', str_repeat('ñ', REPORT_DESCRIPTION_MAX)) === null);

echo "\n1b. Correo de contacto opcional\n";
check('acepta un correo vacío', reportContactEmailError('') === null);
check('acepta un correo válido', reportContactEmailError('eri@ejemplo.cl') === null);
check('rechaza un correo sin arroba', reportContactEmailError('eri.ejemplo.cl') !== null);
check('rechaza un correo con espacios', reportContactEmailError('eri @ejemplo.cl') !== null);
check('rechaza un correo de más de 190 caracteres', reportContactEmailError(str_repeat('a', 185) . '@x.cl') !== null);

echo "\n2. Límite de tamaño y errores de subida\n";
check('acepta un adjunto de 5 MB exactos', reportAttachmentError(UPLOAD_ERR_OK, REPORT_ATTACHMENT_MAX_BYTES) === null);
check('rechaza un adjunto de más de 5 MB', reportAttachmentError(UPLOAD_ERR_OK, REPORT_ATTACHMENT_MAX_BYTES + 1) !== null);
check('rechaza un error de tamaño del servidor', reportAttachmentError(UPLOAD_ERR_INI_SIZE, 0) !== null);
check('rechaza un error de tamaño del formulario', reportAttachmentError(UPLOAD_ERR_FORM_SIZE, 0) !== null);
check('rechaza una subida parcial', reportAttachmentError(UPLOAD_ERR_PARTIAL, 10) !== null);
check('rechaza un archivo vacío', reportAttachmentError(UPLOAD_ERR_OK, 0) !== null);

echo "\n3. Tipo de adjunto por contenido\n";
$dir = sys_get_temp_dir() . '/oftaplus-reports-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
$files = [
    'imagen.png' => $png,
    'documento.pdf' => $pdf,
    'texto.txt' => "Hola, esto es texto plano.\n",
    'disfrazado.png' => "<?php echo 'x';",
];
foreach ($files as $name => $content) {
    file_put_contents($dir . '/' . $name, $content);
}
check('detecta PNG por contenido', reportAttachmentMime($dir . '/imagen.png') === 'image/png');
check('detecta PDF por contenido', reportAttachmentMime($dir . '/documento.pdf') === 'application/pdf');
check('rechaza texto plano', reportAttachmentMime($dir . '/texto.txt') === null);
check('rechaza PHP disfrazado de imagen', reportAttachmentMime($dir . '/disfrazado.png') === null);
check('rechaza una ruta inexistente', reportAttachmentMime($dir . '/no-existe.png') === null);

echo "\n4. Nombre original saneado\n";
check('quita rutas de Unix', reportSafeFilename('../../etc/passwd') === 'passwd');
check('quita rutas de Windows', reportSafeFilename('C:\\Users\\eri\\foto.jpg') === 'foto.jpg');
check('quita caracteres de control', reportSafeFilename("factura\x00\x1F.pdf") === 'factura.pdf');
check('usa un nombre por defecto si queda vacío', reportSafeFilename('   ') === 'adjunto');
check('limita el largo a 180 caracteres', mb_strlen(reportSafeFilename(str_repeat('a', 300) . '.pdf')) === 180);

echo "\n5. Nombre guardado\n";
$stored = reportStoredName('image/png');
check('termina en la extensión del tipo detectado', str_ends_with($stored, '.png'));
check('es hexadecimal aleatorio sin el nombre original', preg_match('/^[0-9a-f]{32}\.png$/', $stored) === 1);
check('dos nombres generados no coinciden', reportStoredName('application/pdf') !== reportStoredName('application/pdf'));
check('cabe en la columna attachment_path (80)', strlen(reportStoredName('application/pdf')) <= 80);
check('la carpeta por defecto queda fuera de la carpeta del proyecto', getenv('OFTAPLUS_UPLOAD_DIR') !== false || !str_starts_with(reportStorageDir(), dirname(__DIR__) . '/'));

foreach ($files as $name => $_) {
    @unlink($dir . '/' . $name);
}
@rmdir($dir);

echo "\n=====================================================\n";
echo " RESULTADO: {$passed} aprobadas, {$failed} fallidas\n";
echo "=====================================================\n";
exit($failed === 0 ? 0 : 1);
