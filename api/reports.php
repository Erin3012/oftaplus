<?php
declare(strict_types=1);

// Reglas de Reportes y sugerencias sin acceso a la base ni a la sesión, para probarlas con tests/reports_test.php.

const REPORT_TYPES = ['report', 'suggestion'];
const REPORT_STATUSES = ['new', 'in_review', 'resolved'];
const REPORT_DESCRIPTION_MAX = 2000;
const REPORT_ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024;
// Tipo MIME detectado en el contenido real del archivo => extensión con la que se guarda.
const REPORT_ATTACHMENT_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

// Fuera de la carpeta pública, igual que el archivo de configuración de la base.
function reportStorageDir(): string
{
    return getenv('OFTAPLUS_UPLOAD_DIR') ?: dirname(__DIR__, 2) . '/oftaplus-storage/reports';
}

function reportInputError(string $type, string $description): ?string
{
    if (!in_array($type, REPORT_TYPES, true)) return 'Selecciona si es un reporte o una sugerencia.';
    $length = mb_strlen(trim($description));
    if ($length === 0) return 'Escribe el motivo del reporte o sugerencia.';
    if ($length > REPORT_DESCRIPTION_MAX) return 'La descripción no puede superar los 2000 caracteres.';
    return null;
}

function reportAttachmentError(int $uploadError, int $size): ?string
{
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE || $size > REPORT_ATTACHMENT_MAX_BYTES) return 'El adjunto supera el máximo de 5 MB.';
    if ($uploadError !== UPLOAD_ERR_OK) return 'No se pudo subir el adjunto. Inténtalo otra vez.';
    if ($size <= 0) return 'El adjunto está vacío.';
    return null;
}

// El correo de contacto es opcional; si viene, debe ser válido.
function reportContactEmailError(string $email): ?string
{
    if ($email === '') return null;
    if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Ingresa un correo válido o déjalo vacío.';
    return null;
}

// Usa el contenido del archivo y no el nombre ni el tipo que envía el navegador.
function reportAttachmentMime(string $path): ?string
{
    if (!is_file($path)) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    return is_string($mime) && isset(REPORT_ATTACHMENT_TYPES[$mime]) ? $mime : null;
}

// El nombre original solo se muestra en el historial; nunca forma parte de la ruta guardada.
function reportSafeFilename(string $name): string
{
    $base = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))));
    return $base === '' ? 'adjunto' : mb_substr($base, 0, 180);
}

function reportStoredName(string $mime): string
{
    return bin2hex(random_bytes(16)) . '.' . REPORT_ATTACHMENT_TYPES[$mime];
}
