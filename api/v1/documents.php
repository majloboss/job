<?php
// Dokumenty pouzivatela — zivotopis a dalsie prilohy.
//
// GET    /v1/documents            zoznam mojich dokumentov
// POST   /v1/documents            nahratie (multipart/form-data, pole: file)
//        + doc_type, title, is_primary
// PATCH  /v1/documents            uprava metadat { id, title, doc_type, is_primary, use_for_ai }
// DELETE /v1/documents?id=5       zmazanie
//
// Subor sa uklada na disk, v DB je metadata a vytazeny text, ktory ide
// do promptu pri posudzovani vhodnosti inzeratu.
$auth = require_auth();
require_once __DIR__ . '/../helpers/doc_text.php';

$pdo = db();
$uid = $auth['user_id'];
// api/uploads/documents/ — teda VNUTRI api/, kde plati .htaccess so zakazom
// priameho stiahnutia. dirname(__DIR__, 2) by ukazalo o uroven vyssie, do
// korena webu, odkial by boli zivotopisy verejne dostupne cez URL.
$dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads'
     . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR;

const DOC_TYPES = ['cv', 'cover_letter', 'certificate', 'reference', 'portfolio', 'other'];
const DOC_MAX_BYTES = 10 * 1024 * 1024;
const DOC_MIME = [
    'application/pdf' => 'pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/msword' => 'doc',
    'application/rtf'    => 'rtf',
    'text/rtf'           => 'rtf',
    'text/plain'         => 'txt',
    'application/vnd.oasis.opendocument.text' => 'odt',
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
];

// ------------------------------------------------------------
// GET — zoznam
// ------------------------------------------------------------
if ($method === 'GET') {
    $st = $pdo->prepare(
        'SELECT id, doc_type, title, original_name, mime_type, size_bytes, lang,
                is_primary, use_for_ai, extracted_at, extract_error,
                LENGTH(extracted_text) AS text_chars, note, created_at
           FROM job.user_documents
          WHERE user_id = ?
          ORDER BY is_primary DESC, created_at DESC');
    $st->execute([$uid]);
    json_ok(['documents' => $st->fetchAll()]);
}

// ------------------------------------------------------------
// DELETE
// ------------------------------------------------------------
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Chýba id', 400);

    $st = $pdo->prepare('SELECT filename FROM job.user_documents WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    $filename = $st->fetchColumn();
    if ($filename === false) json_error('Dokument sa nenašiel', 404);

    $pdo->prepare('DELETE FROM job.user_documents WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
    $path = $dir . basename($filename);
    if (is_file($path)) unlink($path);

    json_ok(['deleted' => $id]);
}

// ------------------------------------------------------------
// PATCH — uprava metadat
// ------------------------------------------------------------
if ($method === 'PATCH') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) json_error('Chýba id', 400);

    $st = $pdo->prepare('SELECT doc_type FROM job.user_documents WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    $docType = $st->fetchColumn();
    if ($docType === false) json_error('Dokument sa nenašiel', 404);

    if (isset($body['doc_type'])) {
        if (!in_array($body['doc_type'], DOC_TYPES, true)) json_error('Neplatný typ dokumentu', 400);
        $docType = $body['doc_type'];
        $pdo->prepare('UPDATE job.user_documents SET doc_type = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$docType, $id]);
    }
    if (isset($body['title'])) {
        $pdo->prepare('UPDATE job.user_documents SET title = ?, updated_at = NOW() WHERE id = ?')
            ->execute([mb_substr(trim((string)$body['title']), 0, 200), $id]);
    }
    if (isset($body['use_for_ai'])) {
        $pdo->prepare('UPDATE job.user_documents SET use_for_ai = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$body['use_for_ai'] ? 't' : 'f', $id]);
    }
    if (!empty($body['is_primary'])) {
        // hlavny dokument je prave jeden v ramci typu (partial unique index)
        $pdo->prepare('UPDATE job.user_documents SET is_primary = FALSE
                        WHERE user_id = ? AND doc_type = ? AND id <> ?')
            ->execute([$uid, $docType, $id]);
        $pdo->prepare('UPDATE job.user_documents SET is_primary = TRUE, updated_at = NOW() WHERE id = ?')
            ->execute([$id]);
    }

    json_ok(['updated' => $id]);
}

if ($method !== 'POST') json_error('Method not allowed', 405);

// ------------------------------------------------------------
// POST — nahratie suboru
// ------------------------------------------------------------
$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    $why = match ($file['error'] ?? -1) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Súbor je príliš veľký',
        UPLOAD_ERR_NO_FILE => 'Nebol vybraný žiadny súbor',
        default => 'Súbor sa nenahral',
    };
    json_error($why, 400);
}
if ($file['size'] > DOC_MAX_BYTES) json_error('Súbor je príliš veľký (max 10 MB)', 400);

$mime = mime_content_type($file['tmp_name']) ?: '';
if (!isset(DOC_MIME[$mime])) {
    json_error('Nepodporovaný formát. Povolené: PDF, DOCX, DOC, ODT, RTF, TXT, JPG, PNG', 400);
}
$ext = DOC_MIME[$mime];

$docType = (string)($_POST['doc_type'] ?? 'cv');
if (!in_array($docType, DOC_TYPES, true)) $docType = 'other';

$title = trim((string)($_POST['title'] ?? ''));
if ($title === '') $title = pathinfo($file['name'], PATHINFO_FILENAME);
$title = mb_substr($title, 0, 200);

if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    json_error('Priečinok na dokumenty sa nepodarilo vytvoriť', 500);
}

$filename = 'u' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
    json_error('Chyba pri ukladaní súboru', 500);
}

// vytazenie textu — bez neho sa dokument ulozi tiez, len sa nepouzije v prompte
[$text, $extractError] = doc_extract_text($dir . $filename, $mime);

$isPrimary = !empty($_POST['is_primary']);
if (!$isPrimary) {
    // prvy dokument daneho typu je automaticky hlavny
    $st = $pdo->prepare('SELECT COUNT(*) FROM job.user_documents WHERE user_id = ? AND doc_type = ?');
    $st->execute([$uid, $docType]);
    $isPrimary = ((int)$st->fetchColumn() === 0);
}
if ($isPrimary) {
    $pdo->prepare('UPDATE job.user_documents SET is_primary = FALSE WHERE user_id = ? AND doc_type = ?')
        ->execute([$uid, $docType]);
}

$st = $pdo->prepare(
    'INSERT INTO job.user_documents
        (user_id, doc_type, title, filename, original_name, mime_type, size_bytes,
         extracted_text, extracted_at, extract_error, is_primary)
     VALUES (?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
$st->execute([
    $uid, $docType, $title, $filename,
    mb_substr($file['name'], 0, 255), $mime, $file['size'],
    $text, $text !== null ? date('Y-m-d H:i:s') : null, $extractError,
    $isPrimary ? 't' : 'f',
]);

json_ok([
    'id'         => (int)$st->fetchColumn(),
    'title'      => $title,
    'doc_type'   => $docType,
    'is_primary' => $isPrimary,
    'text_chars' => $text !== null ? mb_strlen($text) : 0,
    'extract_error' => $extractError,
]);
