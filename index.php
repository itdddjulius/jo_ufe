<?php
declare(strict_types=1);
session_start();

/*
JO Universal File Editor — Single File Edition
- One-file PHP app with adapter-style internal handlers
- Upload, preview, edit, save, download, version history, audit log
- Supports: text, doc/docx (practical text workflow), svg, jpg/jpeg/png, pdf, exe
- Storage folders are created beside this file:
  /storage/originals
  /storage/versions
  /storage/temp
  /storage/logs
*/

date_default_timezone_set('UTC');

const APP_NAME = 'JO Universal File Editor';
const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

function app_dir(): string { return __DIR__; }
function storage_root(): string { return app_dir() . '/storage'; }
function originals_dir(): string { return storage_root() . '/originals'; }
function versions_dir(): string { return storage_root() . '/versions'; }
function temp_dir(): string { return storage_root() . '/temp'; }
function logs_dir(): string { return storage_root() . '/logs'; }

function ensure_dirs(): void {
    foreach ([storage_root(), originals_dir(), versions_dir(), temp_dir(), logs_dir()] as $dir) {
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    }
}

function csrf_token(): string {
    if (!isset($_SESSION['_token'])) {
        $_SESSION['_token'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['_token'];
}
function verify_csrf(): void {
    $token = (string)($_POST['_token'] ?? '');
    if (!hash_equals((string)($_SESSION['_token'] ?? ''), $token)) {
        flash('error', 'Invalid CSRF token.');
        redirect('/');
    }
}

function flash(string $type, string $message): void {
    $_SESSION['flash_'.$type] = $message;
}
function pull_flash(string $type): ?string {
    $key = 'flash_'.$type;
    if (!isset($_SESSION[$key])) return null;
    $v = (string)$_SESSION[$key];
    unset($_SESSION[$key]);
    return $v;
}
function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function sanitize_filename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'file';
    $name = trim($name, '._');
    return $name !== '' ? $name : 'file';
}
function file_ext(string $filename): string {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}
function allowed_exts(): array {
    return ['doc', 'docx', 'txt', 'md', 'csv', 'json', 'xml', 'svg', 'jpg', 'jpeg', 'png', 'pdf', 'exe'];
}
function is_allowed(string $filename): bool {
    return in_array(file_ext($filename), allowed_exts(), true);
}
function detect_mime(string $path): string {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (finfo_file($finfo, $path) ?: 'application/octet-stream') : 'application/octet-stream';
    if ($finfo) finfo_close($finfo);
    return $mime;
}
function file_id(string $storedName): string {
    return sha1($storedName);
}
function original_path(string $storedName): string {
    return originals_dir() . '/' . basename($storedName);
}
function version_dir(string $storedName): string {
    return versions_dir() . '/' . file_id($storedName);
}
function ensure_version_dir(string $storedName): string {
    $dir = version_dir($storedName);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
function create_version(string $storedName): ?string {
    $src = original_path($storedName);
    if (!is_file($src)) return null;
    $dir = ensure_version_dir($storedName);
    $name = date('Ymd_His') . '_' . basename($storedName);
    $dest = $dir . '/' . $name;
    if (@copy($src, $dest)) {
        audit("Version created for {$storedName}: {$name} by User");
        return $dest;
    }
    return null;
}
function list_versions(string $storedName): array {
    $dir = version_dir($storedName);
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*') ?: [];
    $out = [];
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $out[] = [
            'name' => basename($file),
            'path' => $file,
            'size' => filesize($file) ?: 0,
            'timestamp' => filemtime($file) ?: time(),
            'user' => 'User',
        ];
    }
    usort($out, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    return array_values($out);
}
function audit(string $message): void {
    ensure_dirs();
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents(logs_dir() . '/audit.log', $line, FILE_APPEND);
}
function list_original_files(): array {
    ensure_dirs();
    $files = glob(originals_dir() . '/*') ?: [];
    $out = [];
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $storedName = basename($file);
        $out[] = [
            'id' => file_id($storedName),
            'stored_name' => $storedName,
            'name' => preg_replace('/^[0-9]{14}_/', '', $storedName) ?: $storedName,
            'size' => filesize($file) ?: 0,
            'mtime' => filemtime($file) ?: time(),
            'path' => $file,
            'mime' => detect_mime($file),
            'ext' => file_ext($storedName),
        ];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function format_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($units)-1) { $n /= 1024; $i++; }
    return round($n, 2) . ' ' . $units[$i];
}
function parse_docx_text(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml') ?: '';
    $zip->close();
    if ($xml === '') return '';
    $xml = preg_replace('/<\/w:p>/', "\n\n", $xml);
    $xml = preg_replace('/<w:tab\/>/', "\t", $xml);
    $xml = strip_tags($xml);
    $xml = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $xml = preg_replace("/\n{3,}/", "\n\n", trim($xml));
    return $xml ?: '';
}
function save_docx_text(string $path, string $content): bool {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;

    $xml = $zip->getFromName('word/document.xml');
    if ($xml === false) { $zip->close(); return false; }

    $paras = preg_split("/\R{2,}/", trim($content)) ?: [];
    $bodyXml = '';
    foreach ($paras as $para) {
        $lines = preg_split("/\R/", $para) ?: [''];
        $runs = [];
        foreach ($lines as $i => $line) {
            $safe = htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $runs[] = '<w:r><w:t xml:space="preserve">'.$safe.'</w:t></w:r>';
            if ($i < count($lines)-1) {
                $runs[] = '<w:r><w:br/></w:r>';
            }
        }
        $bodyXml .= '<w:p>' . implode('', $runs) . '</w:p>';
    }
    if ($bodyXml === '') {
        $bodyXml = '<w:p><w:r><w:t></w:t></w:r></w:p>';
    }
    $replacement = $bodyXml . '<w:sectPr';
    $xml = preg_replace('/<w:body>.*?<w:sectPr/s', '<w:body>' . $replacement, $xml, 1);
    if ($xml === null) { $zip->close(); return false; }

    $ok = $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    return (bool)$ok;
}
function pdf_extract_text(string $path): string {
    $raw = @file_get_contents($path);
    if ($raw === false) return '';
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $raw);
    preg_match_all('/\(([^()]*)\)/s', $text, $matches);
    $pieces = [];
    foreach (($matches[1] ?? []) as $m) {
        $m = preg_replace('/\\\\[nrtbf]/', ' ', $m);
        $m = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $m);
        if (preg_match('/[A-Za-z]{3,}/', $m)) $pieces[] = $m;
    }
    $joined = trim(preg_replace('/\s+/', ' ', implode("\n", $pieces)) ?? '');
    return $joined;
}
function adapter_key(string $filename, string $mime): string {
    $ext = file_ext($filename);
    if (in_array($ext, ['txt','md','csv','json','xml'], true)) return 'text';
    if (in_array($ext, ['doc','docx'], true)) return 'docx';
    if ($ext === 'svg') return 'svg';
    if (in_array($ext, ['jpg','jpeg','png'], true)) return 'image';
    if ($ext === 'pdf') return 'pdf';
    if ($ext === 'exe') return 'exe';
    return 'text';
}
function load_adapter_data(string $path, string $originalName, string $mime): array {
    $mode = adapter_key($originalName, $mime);
    $ext = file_ext($originalName);

    if ($mode === 'text') {
        $content = file_get_contents($path) ?: '';
        return [
            'mode' => 'text',
            'editable' => true,
            'content' => $content,
            'stats' => ['characters' => mb_strlen($content), 'lines' => substr_count($content, "\n") + 1],
            'limitations' => [],
        ];
    }

    if ($mode === 'docx') {
        if ($ext === 'doc') {
            return [
                'mode' => 'docx',
                'editable' => false,
                'content' => '',
                'paragraphs' => [],
                'limitations' => [
                    'Legacy .doc binary editing is not supported directly.',
                    'Convert .doc to .docx for structured editing/export.',
                ],
            ];
        }
        $text = parse_docx_text($path);
        $paragraphs = array_values(array_filter(array_map('trim', preg_split("/\R{2,}/", $text) ?: [])));
        return [
            'mode' => 'docx',
            'editable' => true,
            'content' => $text,
            'paragraphs' => $paragraphs,
            'limitations' => [
                'This single-file edition supports practical paragraph-based docx editing/export, not full Word layout fidelity.',
            ],
        ];
    }

    if ($mode === 'svg') {
        $content = file_get_contents($path) ?: '';
        preg_match('/width="([^"]+)"/i', $content, $width);
        preg_match('/height="([^"]+)"/i', $content, $height);
        return [
            'mode' => 'svg',
            'editable' => true,
            'content' => $content,
            'svg_preview' => $content,
            'properties' => ['width' => $width[1] ?? '', 'height' => $height[1] ?? ''],
            'limitations' => [],
        ];
    }

    if ($mode === 'image') {
        $size = @getimagesize($path);
        return [
            'mode' => 'image',
            'editable' => true,
            'content' => '',
            'preview_url' => '/?action=download&inline=1&file=' . rawurlencode(basename($path)),
            'metadata' => [
                'width' => $size[0] ?? null,
                'height' => $size[1] ?? null,
                'mime' => $mime,
                'filename' => $originalName,
            ],
            'limitations' => [
                'Image operations are browser-assisted in this one-file edition.',
            ],
        ];
    }

    if ($mode === 'pdf') {
        $text = pdf_extract_text($path);
        return [
            'mode' => 'pdf',
            'editable' => false,
            'content' => $text,
            'preview_url' => '/?action=download&inline=1&file=' . rawurlencode(basename($path)),
            'limitations' => [
                'Arbitrary paragraph editing inside PDFs is restricted.',
                'Use preview, extraction, overlay text, and annotation workflow extensions.',
            ],
        ];
    }

    if ($mode === 'exe') {
        $size = filesize($path) ?: 0;
        $head = file_get_contents($path, false, null, 0, 512) ?: '';
        return [
            'mode' => 'exe',
            'editable' => false,
            'content' => '',
            'metadata' => [
                'filename' => $originalName,
                'size' => $size,
                'sha256' => hash_file('sha256', $path),
                'md5' => hash_file('md5', $path),
                'header_hex' => strtoupper(bin2hex(substr($head, 0, 64))),
            ],
            'limitations' => [
                'EXE handling is inspection-only.',
                'No freeform binary editing is provided.',
                'Uploaded binaries are never executed.',
            ],
        ];
    }

    return ['mode' => 'text', 'editable' => false, 'content' => '', 'limitations' => ['Unsupported mode.']];
}
function save_via_adapter(string $path, string $originalName, array $payload): array {
    $mime = detect_mime($path);
    $mode = adapter_key($originalName, $mime);
    $ext = file_ext($originalName);

    if ($mode === 'text' || $mode === 'svg') {
        $content = (string)($payload['content'] ?? '');
        file_put_contents($path, $content);
        return ['success' => true, 'message' => strtoupper($mode) . ' saved.'];
    }

    if ($mode === 'docx') {
        if ($ext !== 'docx') {
            return ['success' => false, 'message' => '.doc save is restricted. Convert to .docx for export.'];
        }
        $ok = save_docx_text($path, (string)($payload['content'] ?? ''));
        return ['success' => $ok, 'message' => $ok ? 'DOCX saved.' : 'Unable to save DOCX.'];
    }

    if ($mode === 'image') {
        $dataUrl = (string)($payload['imageData'] ?? '');
        if (!preg_match('/^data:image\/(png|jpeg);base64,(.*)$/', $dataUrl, $m)) {
            return ['success' => false, 'message' => 'Invalid image payload.'];
        }
        $binary = base64_decode($m[2], true);
        if ($binary === false) {
            return ['success' => false, 'message' => 'Unable to decode image.'];
        }
        file_put_contents($path, $binary);
        return ['success' => true, 'message' => 'Image saved.'];
    }

    if ($mode === 'pdf') {
        return ['success' => false, 'message' => 'PDF freeform editing is restricted in this edition.'];
    }

    if ($mode === 'exe') {
        return ['success' => false, 'message' => 'EXE files are inspection-only in safe mode.'];
    }

    return ['success' => false, 'message' => 'Unsupported save mode.'];
}

ensure_dirs();

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'upload' && $method === 'POST') {
    verify_csrf();
    if (empty($_FILES['file'])) {
        flash('error', 'No file uploaded.');
        redirect('/');
    }
    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed.');
        redirect('/');
    }
    $originalName = sanitize_filename((string)$file['name']);
    if (!is_allowed($originalName)) {
        flash('error', 'Unsupported file type.');
        redirect('/');
    }
    if (($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        flash('error', 'File exceeds 25MB limit.');
        redirect('/');
    }
    $storedName = date('YmdHis') . '_' . $originalName;
    $target = original_path($storedName);
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        flash('error', 'Unable to store uploaded file.');
        redirect('/');
    }
    audit("Uploaded {$storedName} by User");
    flash('success', 'File uploaded successfully.');
    redirect('/?file=' . rawurlencode($storedName));
}

if ($action === 'save' && $method === 'POST') {
    verify_csrf();
    $storedName = (string)($_POST['file'] ?? '');
    $path = original_path($storedName);
    if ($storedName === '' || !is_file($path)) {
        flash('error', 'File not found.');
        redirect('/');
    }
    create_version($storedName);
    $result = save_via_adapter($path, $storedName, [
        'content' => $_POST['content'] ?? '',
        'imageData' => $_POST['imageData'] ?? '',
    ]);
    if (!($result['success'] ?? false)) {
        flash('error', $result['message'] ?? 'Save failed.');
    } else {
        audit("Saved {$storedName} by User");
        flash('success', $result['message'] ?? 'Saved.');
    }
    redirect('/?file=' . rawurlencode($storedName));
}

if ($action === 'restore' && $method === 'POST') {
    verify_csrf();
    $storedName = (string)($_POST['file'] ?? '');
    $versionName = basename((string)($_POST['version'] ?? ''));
    $src = version_dir($storedName) . '/' . $versionName;
    $dest = original_path($storedName);
    if (!is_file($src) || !is_file($dest)) {
        flash('error', 'Version not found.');
        redirect('/');
    }
    create_version($storedName);
    copy($src, $dest);
    audit("Restored version {$versionName} for {$storedName} by User");
    flash('success', 'Version restored.');
    redirect('/?file=' . rawurlencode($storedName));
}

if ($action === 'download') {
    $storedName = basename((string)($_GET['file'] ?? ''));
    $inline = isset($_GET['inline']) && (string)$_GET['inline'] === '1';
    $path = original_path($storedName);
    if ($storedName === '' || !is_file($path)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }
    $mime = detect_mime($path);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)(filesize($path) ?: 0));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($storedName) . '"');
    readfile($path);
    exit;
}

$storedName = (string)($_GET['file'] ?? '');
$path = $storedName ? original_path($storedName) : '';
$fileData = null;
$versions = [];
if ($storedName !== '' && is_file($path)) {
    $mime = detect_mime($path);
    $fileData = load_adapter_data($path, $storedName, $mime);
    $versions = list_versions($storedName);
}
$files = list_original_files();
$flashSuccess = pull_flash('success');
$flashError = pull_flash('error');
$token = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= h(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min/vs/loader.js"></script>
  <style>
    :root{
      --jo-bg:#000;
      --jo-panel:rgba(255,255,255,.06);
      --jo-text:#fff;
      --jo-green:#00c853;
      --jo-border:rgba(255,255,255,.12);
      --jo-muted:rgba(255,255,255,.72);
    }
    body{background:var(--jo-bg);color:var(--jo-text);min-height:100vh;}
    .jo-shell{display:grid;grid-template-columns:260px 1fr;min-height:100vh;}
    .jo-sidebar{border-right:1px solid var(--jo-border);background:rgba(255,255,255,.03);}
    .jo-topbar{border-bottom:1px solid var(--jo-border);background:rgba(0,0,0,.72);backdrop-filter:blur(8px);}
    .jo-card{background:var(--jo-panel);border:1px solid var(--jo-border);border-radius:18px;}
    .btn-jo{background:var(--jo-green)!important;color:#000!important;font-weight:800!important;border:none!important;}
    .btn-outline-jo{border:1px solid var(--jo-green)!important;color:var(--jo-green)!important;background:transparent!important;font-weight:800!important;}
    .btn-outline-jo:hover{background:rgba(0,200,83,.12)!important;color:#fff!important;}
    .jo-muted{color:var(--jo-muted);}
    .dropzone{border:2px dashed rgba(255,255,255,.18);border-radius:18px;background:rgba(255,255,255,.03);}
    .sidebar-link{display:block;padding:.85rem 1rem;border-radius:12px;color:#fff;text-decoration:none;}
    .sidebar-link:hover{background:rgba(255,255,255,.05);color:#fff;}
    textarea, input, select{background:rgba(255,255,255,.06)!important;color:#fff!important;border:1px solid rgba(255,255,255,.15)!important;}
    .bottom-bar{position:sticky;bottom:0;background:rgba(0,0,0,.88);border-top:1px solid var(--jo-border);backdrop-filter:blur(8px);}
    .meta-kv{display:grid;grid-template-columns:150px 1fr;gap:8px;}
    .code-area{min-height:380px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;}
    #monacoEditor{height:420px;border:1px solid rgba(255,255,255,.12);border-radius:12px;overflow:hidden;}
    .img-editor-wrap{overflow:auto;min-height:420px;display:flex;align-items:center;justify-content:center;}
    .thumb-list{max-height:280px;overflow:auto;}
    @media (max-width: 991px){
      .jo-shell{grid-template-columns:1fr;}
      .jo-sidebar{display:none;}
    }
  </style>
</head>
<body>
<div class="jo-shell">
  <aside class="jo-sidebar p-3">
    <div class="d-flex align-items-center gap-2 mb-4">
      <i class="fa-solid fa-folder-tree text-success"></i>
      <div class="fw-bold"><?= h(APP_NAME) ?></div>
    </div>
    <nav class="d-grid gap-2">
      <a class="sidebar-link" href="/"><i class="fa-solid fa-gauge me-2"></i>Dashboard</a>
      <a class="sidebar-link" href="/"><i class="fa-solid fa-upload me-2"></i>Upload File</a>
      <a class="sidebar-link" href="/"><i class="fa-solid fa-pen-to-square me-2"></i>Editor Workspace</a>
      <a class="sidebar-link" href="/"><i class="fa-regular fa-eye me-2"></i>Preview Panel</a>
      <a class="sidebar-link" href="/"><i class="fa-solid fa-clock-rotate-left me-2"></i>Version History</a>
      <a class="sidebar-link" href="/"><i class="fa-solid fa-circle-info me-2"></i>File Metadata</a>
      <a class="sidebar-link" href="/"><i class="fa-solid fa-gear me-2"></i>Settings</a>
      <a class="sidebar-link" href="/"><i class="fa-regular fa-life-ring me-2"></i>Help / Supported Formats</a>
    </nav>
  </aside>

  <main>
    <div class="jo-topbar px-4 py-3 d-flex justify-content-between align-items-center sticky-top">
      <div class="fw-bold"><?= h(APP_NAME) ?></div>
      <div class="jo-muted small">One-file edition • adapters • versions • safe workflows</div>
    </div>

    <div class="container-fluid p-4">
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="jo-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h1 class="h3 mb-1">Dashboard</h1>
                <div class="jo-muted">Universal multi-format file workbench</div>
              </div>
              <div class="text-success"><i class="fa-solid fa-file-pen fa-2x"></i></div>
            </div>

            <?php if ($flashSuccess): ?>
              <div class="alert alert-success"><?= h($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if ($flashError): ?>
              <div class="alert alert-danger"><?= h($flashError) ?></div>
            <?php endif; ?>

            <form action="/?action=upload" method="post" enctype="multipart/form-data" class="dropzone p-5 text-center" id="uploadForm">
              <input type="hidden" name="_token" value="<?= h($token) ?>">
              <div class="mb-3">
                <i class="fa-solid fa-cloud-arrow-up fa-3x text-success"></i>
              </div>
              <h2 class="h5">Upload File</h2>
              <p class="jo-muted">DOC/DOCX, TXT/MD/CSV/JSON/XML, SVG, JPG/PNG, PDF, EXE</p>
              <input type="file" name="file" class="form-control mb-3" id="fileInput" required>
              <button class="btn btn-jo px-4">Upload</button>
            </form>
          </div>

          <?php if ($fileData && is_array($fileData)): ?>
          <div class="jo-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
              <div>
                <h2 class="h4 mb-1">Editor Workspace</h2>
                <div class="jo-muted small"><?= h($storedName) ?> • <?= h((string)$fileData['mode']) ?></div>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-jo btn-sm" href="/?action=download&file=<?= rawurlencode($storedName) ?>">
                  <i class="fa-solid fa-download me-1"></i>Download
                </a>
              </div>
            </div>

            <form method="post" action="/?action=save" id="saveForm">
              <input type="hidden" name="_token" value="<?= h($token) ?>">
              <input type="hidden" name="file" value="<?= h($storedName) ?>">
              <input type="hidden" id="imageData" name="imageData" value="">
              <input type="hidden" id="contentHidden" name="content" value="">

              <?php if (($fileData['mode'] ?? '') === 'text'): ?>
                <div class="d-flex gap-2 flex-wrap mb-3">
                  <input id="searchText" class="form-control w-auto" placeholder="Search">
                  <input id="replaceText" class="form-control w-auto" placeholder="Replace">
                  <button class="btn btn-outline-jo btn-sm" type="button" onclick="textSearchReplace()">Search & Replace</button>
                  <button class="btn btn-jo btn-sm" type="submit">Save</button>
                </div>
                <div id="monacoEditor"></div>
                <textarea id="content" class="d-none"><?= h((string)$fileData['content']) ?></textarea>

              <?php elseif (($fileData['mode'] ?? '') === 'docx'): ?>
                <div class="d-flex gap-2 flex-wrap mb-3">
                  <button class="btn btn-jo btn-sm" type="submit" <?= empty($fileData['editable']) ? 'disabled' : '' ?>>Export DOCX</button>
                </div>
                <?php if (!empty($fileData['editable'])): ?>
                  <div id="monacoEditor"></div>
                  <textarea id="content" class="d-none"><?= h((string)$fileData['content']) ?></textarea>
                <?php else: ?>
                  <textarea class="form-control code-area" disabled><?= h(implode("\n", (array)($fileData['limitations'] ?? []))) ?></textarea>
                <?php endif; ?>

              <?php elseif (($fileData['mode'] ?? '') === 'svg'): ?>
                <div class="d-flex gap-2 flex-wrap mb-3">
                  <button class="btn btn-outline-jo btn-sm" type="button" onclick="refreshSvgPreview()">Preview Refresh</button>
                  <button class="btn btn-jo btn-sm" type="submit">Save SVG</button>
                </div>
                <div class="row g-3">
                  <div class="col-lg-6">
                    <div id="monacoEditor"></div>
                    <textarea id="content" class="d-none"><?= h((string)$fileData['content']) ?></textarea>
                  </div>
                  <div class="col-lg-6">
                    <div id="svgPreview" class="jo-card p-3 h-100 overflow-auto"></div>
                  </div>
                </div>

              <?php elseif (($fileData['mode'] ?? '') === 'image'): ?>
                <div class="d-flex gap-2 flex-wrap mb-3">
                  <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('rotate')">Rotate</button>
                  <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('flipX')">Flip X</button>
                  <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('flipY')">Flip Y</button>
                  <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('bright+')">Brightness +</button>
                  <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('bright-')">Brightness -</button>
                  <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('contrast+')">Contrast +</button>
                  <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('contrast-')">Contrast -</button>
                  <button class="btn btn-jo btn-sm" type="submit">Save As Version</button>
                </div>
                <div class="jo-card img-editor-wrap p-3">
                  <img id="editableImage"
                       src="<?= h((string)$fileData['preview_url']) ?>"
                       alt="Image Preview"
                       class="img-fluid rounded"
                       style="max-height:520px;">
                </div>

              <?php elseif (($fileData['mode'] ?? '') === 'pdf'): ?>
                <div class="d-flex gap-2 flex-wrap mb-3">
                  <a class="btn btn-outline-jo btn-sm" target="_blank" href="<?= h((string)$fileData['preview_url']) ?>">Open PDF Preview</a>
                </div>
                <div class="jo-card p-3 mb-3">
                  <iframe src="<?= h((string)$fileData['preview_url']) ?>" style="width:100%;height:420px;border:0;"></iframe>
                </div>
                <label class="form-label">Extracted Text</label>
                <textarea id="content" class="form-control code-area" readonly><?= h((string)$fileData['content']) ?></textarea>

              <?php elseif (($fileData['mode'] ?? '') === 'exe'): ?>
                <div class="alert alert-warning">Read-only inspection mode. Uploaded binaries are never executed.</div>
                <div class="jo-card p-3">
                  <?php foreach ((array)($fileData['metadata'] ?? []) as $k => $v): ?>
                    <div class="meta-kv mb-2">
                      <div class="jo-muted"><?= h((string)$k) ?></div>
                      <div class="text-break"><?= h((string)$v) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($fileData['limitations'])): ?>
                <div class="alert alert-secondary mt-3">
                  <strong>Limitations</strong>
                  <ul class="mb-0">
                    <?php foreach ((array)$fileData['limitations'] as $limitation): ?>
                      <li><?= h((string)$limitation) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
            </form>
          </div>
          <?php else: ?>
          <div class="jo-card p-4">
            <h2 class="h5 mb-3">Help / Supported Formats</h2>
            <ul class="jo-muted mb-0">
              <li>Text files: full text editing, search, replace, save/version</li>
              <li>DOCX: paragraph-oriented workflow and export back to .docx</li>
              <li>SVG: raw markup + live preview</li>
              <li>JPG / PNG: preview + browser-assisted adjustments + save</li>
              <li>PDF: preview + extraction workflow, not arbitrary full paragraph editing</li>
              <li>EXE: inspection-only, never executed</li>
            </ul>
          </div>
          <?php endif; ?>
        </div>

        <div class="col-lg-4">
          <div class="jo-card p-3 mb-4">
            <h2 class="h6 mb-3">File Explorer / Recent Files</h2>
            <?php if (!$files): ?>
              <div class="jo-muted">No files uploaded yet.</div>
            <?php else: ?>
              <div class="d-grid gap-2 thumb-list">
                <?php foreach ($files as $f): ?>
                  <a class="text-decoration-none text-white jo-card p-3 <?= $f['stored_name'] === $storedName ? 'border border-success' : '' ?>"
                     href="/?file=<?= rawurlencode($f['stored_name']) ?>">
                    <div class="fw-bold small"><?= h((string)$f['name']) ?></div>
                    <div class="small jo-muted"><?= format_bytes((int)$f['size']) ?> • <?= h((string)$f['ext']) ?></div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="jo-card p-3 mb-4">
            <h2 class="h6 mb-3">Version History</h2>
            <?php if (!$storedName): ?>
              <div class="jo-muted small">Select a file to view versions.</div>
            <?php elseif (!$versions): ?>
              <div class="jo-muted small">No versions yet.</div>
            <?php else: ?>
              <div class="d-grid gap-2">
                <?php foreach ($versions as $v): ?>
                  <form method="post" action="/?action=restore" class="jo-card p-2">
                    <input type="hidden" name="_token" value="<?= h($token) ?>">
                    <input type="hidden" name="file" value="<?= h($storedName) ?>">
                    <input type="hidden" name="version" value="<?= h((string)$v['name']) ?>">
                    <div class="small fw-bold"><?= h((string)$v['name']) ?></div>
                    <div class="small jo-muted"><?= date('Y-m-d H:i:s', (int)$v['timestamp']) ?></div>
                    <div class="small jo-muted"><?= format_bytes((int)$v['size']) ?> • User</div>
                    <button class="btn btn-outline-jo btn-sm mt-2">Restore</button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="jo-card p-3">
            <h2 class="h6 mb-3">Metadata</h2>
            <?php if ($storedName && is_file($path)): ?>
              <div class="meta-kv">
                <div class="jo-muted">Filename</div>
                <div class="text-break"><?= h($storedName) ?></div>

                <div class="jo-muted">Type</div>
                <div><?= h((string)($fileData['mode'] ?? 'unknown')) ?></div>

                <div class="jo-muted">Size</div>
                <div><?= format_bytes((int)(filesize($path) ?: 0)) ?></div>
              </div>

              <?php if (!empty($fileData['metadata']) && is_array($fileData['metadata'])): ?>
                <hr>
                <?php foreach ($fileData['metadata'] as $key => $value): ?>
                  <div class="meta-kv mb-2">
                    <div class="jo-muted"><?= h((string)$key) ?></div>
                    <div class="text-break"><?= h((string)$value) ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            <?php else: ?>
              <div class="jo-muted small">Upload or select a file to inspect metadata.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="bottom-bar px-4 py-2 d-flex flex-wrap justify-content-between small">
      <div>Save status: Ready</div>
      <div>Another Website by Julius Olatokunbo</div>
    </div>
  </main>
</div>

<script>
let monacoEditor = null;

function initMonaco() {
  const holder = document.getElementById('monacoEditor');
  const source = document.getElementById('content');
  const hidden = document.getElementById('contentHidden');
  if (!holder || !source || typeof require === 'undefined') {
    if (source && hidden) hidden.value = source.value || '';
    return;
  }

  require.config({ paths: { vs: 'https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min/vs' }});
  require(['vs/editor/editor.main'], function () {
    monacoEditor = monaco.editor.create(holder, {
      value: source.value || '',
      language: inferLanguage(),
      theme: 'vs-dark',
      automaticLayout: true,
      minimap: { enabled: false }
    });
    syncHiddenFromEditor();
    monacoEditor.onDidChangeModelContent(syncHiddenFromEditor);
  });
}

function inferLanguage() {
  const file = new URLSearchParams(location.search).get('file') || '';
  const ext = file.split('.').pop().toLowerCase();
  if (['json'].includes(ext)) return 'json';
  if (['xml', 'svg'].includes(ext)) return 'xml';
  if (['md'].includes(ext)) return 'markdown';
  if (['csv'].includes(ext)) return 'plaintext';
  return 'plaintext';
}

function syncHiddenFromEditor() {
  const hidden = document.getElementById('contentHidden');
  if (!hidden) return;
  if (monacoEditor) hidden.value = monacoEditor.getValue();
  else {
    const source = document.getElementById('content');
    hidden.value = source ? source.value : '';
  }
}

function setCounts() {
  const chars = document.getElementById('charCount');
  const lines = document.getElementById('lineCount');
  const value = monacoEditor ? monacoEditor.getValue() : ((document.getElementById('content') || {}).value || '');
  if (chars) chars.textContent = value.length;
  if (lines) lines.textContent = value ? value.split(/\r\n|\r|\n/).length : 0;
}
function textSearchReplace() {
  if (!monacoEditor) return;
  const search = document.getElementById('searchText');
  const replace = document.getElementById('replaceText');
  const needle = search ? search.value : '';
  if (!needle) return;
  const value = monacoEditor.getValue().split(needle).join(replace ? replace.value : '');
  monacoEditor.setValue(value);
  syncHiddenFromEditor();
  setCounts();
}
function refreshSvgPreview() {
  const preview = document.getElementById('svgPreview');
  if (!preview) return;
  const value = monacoEditor ? monacoEditor.getValue() : ((document.getElementById('content') || {}).value || '');
  preview.innerHTML = value;
}
function applyImageToolbar(action) {
  const img = document.getElementById('editableImage');
  if (!img) return;

  let rotate = parseFloat(img.dataset.rotate || '0');
  let scaleX = parseFloat(img.dataset.scaleX || '1');
  let scaleY = parseFloat(img.dataset.scaleY || '1');
  let brightness = parseFloat(img.dataset.brightness || '100');
  let contrast = parseFloat(img.dataset.contrast || '100');

  if (action === 'rotate') rotate += 90;
  if (action === 'flipX') scaleX *= -1;
  if (action === 'flipY') scaleY *= -1;
  if (action === 'bright+') brightness += 10;
  if (action === 'bright-') brightness -= 10;
  if (action === 'contrast+') contrast += 10;
  if (action === 'contrast-') contrast -= 10;

  img.dataset.rotate = rotate;
  img.dataset.scaleX = scaleX;
  img.dataset.scaleY = scaleY;
  img.dataset.brightness = brightness;
  img.dataset.contrast = contrast;

  img.style.transform = `rotate(${rotate}deg) scale(${scaleX}, ${scaleY})`;
  img.style.filter = `brightness(${brightness}%) contrast(${contrast}%)`;
}
function saveImageToHiddenInput() {
  const img = document.getElementById('editableImage');
  const hidden = document.getElementById('imageData');
  if (!img || !hidden) return;

  const canvas = document.createElement('canvas');
  const w = img.naturalWidth || img.width;
  const h = img.naturalHeight || img.height;
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  ctx.filter = img.style.filter || 'none';
  ctx.drawImage(img, 0, 0, w, h);
  hidden.value = canvas.toDataURL('image/png');
}

document.addEventListener('DOMContentLoaded', () => {
  initMonaco();

  const saveForm = document.getElementById('saveForm');
  if (saveForm) {
    saveForm.addEventListener('submit', () => {
      syncHiddenFromEditor();
      saveImageToHiddenInput();
    });
  }

  const uploadForm = document.getElementById('uploadForm');
  if (uploadForm) {
    uploadForm.addEventListener('dragover', e => { e.preventDefault(); uploadForm.classList.add('border-success'); });
    uploadForm.addEventListener('dragleave', e => { e.preventDefault(); uploadForm.classList.remove('border-success'); });
    uploadForm.addEventListener('drop', e => {
      e.preventDefault();
      uploadForm.classList.remove('border-success');
      const files = e.dataTransfer.files;
      const input = document.getElementById('fileInput');
      if (files && files.length && input) input.files = files;
    });
  }

  setInterval(() => {
    setCounts();
    if (document.getElementById('svgPreview')) refreshSvgPreview();
  }, 500);
});
</script>
</body>
</html>
