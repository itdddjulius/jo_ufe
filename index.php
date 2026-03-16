<?php
declare(strict_types=1);
session_start();
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
ensure_dirs();

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
function flash(string $type, string $message): void { $_SESSION['flash_'.$type] = $message; }
function pull_flash(string $type): ?string {
    $key = 'flash_'.$type;
    if (!isset($_SESSION[$key])) return null;
    $v = (string)$_SESSION[$key];
    unset($_SESSION[$key]);
    return $v;
}
function redirect(string $path): void { header('Location: ' . $path); exit; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function sanitize_filename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'file';
    $name = trim($name, '._');
    return $name !== '' ? $name : 'file';
}
function file_ext(string $filename): string { return strtolower(pathinfo($filename, PATHINFO_EXTENSION)); }
function allowed_exts(): array { return ['doc','docx','txt','md','csv','json','xml','svg','jpg','jpeg','png','pdf','exe']; }
function is_allowed(string $filename): bool { return in_array(file_ext($filename), allowed_exts(), true); }
function detect_mime(string $path): string {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (finfo_file($finfo, $path) ?: 'application/octet-stream') : 'application/octet-stream';
    if ($finfo) finfo_close($finfo);
    return $mime;
}
function file_id(string $storedName): string { return sha1($storedName); }
function original_path(string $storedName): string { return originals_dir() . '/' . basename($storedName); }
function version_dir(string $storedName): string { return versions_dir() . '/' . file_id($storedName); }
function ensure_version_dir(string $storedName): string {
    $dir = version_dir($storedName); if (!is_dir($dir)) @mkdir($dir, 0775, true); return $dir;
}
function create_version(string $storedName): ?string {
    $src = original_path($storedName);
    if (!is_file($src)) return null;
    $dir = ensure_version_dir($storedName);
    $name = date('Ymd_His') . '_' . basename($storedName);
    $dest = $dir . '/' . $name;
    if (@copy($src, $dest)) { audit("Version created for {$storedName}: {$name} by User"); return $dest; }
    return null;
}
function list_versions(string $storedName): array {
    $dir = version_dir($storedName);
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*') ?: [];
    $out = [];
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $out[] = ['name'=>basename($file),'path'=>$file,'size'=>filesize($file) ?: 0,'timestamp'=>filemtime($file) ?: time(),'user'=>'User'];
    }
    usort($out, fn($a,$b)=>$b['timestamp'] <=> $a['timestamp']);
    return array_values($out);
}
function audit(string $message): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents(logs_dir() . '/audit.log', $line, FILE_APPEND);
}
function format_bytes(int $bytes): string {
    $units = ['B','KB','MB','GB']; $i=0; $n=(float)$bytes; while ($n>=1024 && $i<count($units)-1) {$n/=1024; $i++;}
    return round($n,2) . ' ' . $units[$i];
}
function list_original_files(): array {
    $files = glob(originals_dir() . '/*') ?: [];
    $out = [];
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        $storedName = basename($file);
        $out[] = [
            'id'=>file_id($storedName),
            'stored_name'=>$storedName,
            'name'=>preg_replace('/^[0-9]{14}_/', '', $storedName) ?: $storedName,
            'size'=>filesize($file) ?: 0,
            'mtime'=>filemtime($file) ?: time(),
            'path'=>$file,
            'mime'=>detect_mime($file),
            'ext'=>file_ext($storedName),
        ];
    }
    usort($out, fn($a,$b)=>$b['mtime'] <=> $a['mtime']);
    return $out;
}
function parse_docx_text(string $path): string {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml') ?: '';
    $zip->close();
    if ($xml === '') return '';
    $xml = preg_replace('/<\\/w:p>/', "\n\n", $xml);
    $xml = preg_replace('/<w:tab\\/>/', "\t", $xml);
    $xml = strip_tags($xml);
    $xml = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return trim((string)preg_replace("/\n{3,}/", "\n\n", $xml));
}
function save_docx_text(string $path, string $content): bool {
    if (!class_exists('ZipArchive')) return false;
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
            if ($i < count($lines)-1) $runs[] = '<w:r><w:br/></w:r>';
        }
        $bodyXml .= '<w:p>' . implode('', $runs) . '</w:p>';
    }
    if ($bodyXml === '') $bodyXml = '<w:p><w:r><w:t></w:t></w:r></w:p>';
    $xml = preg_replace('/<w:body>.*?<w:sectPr/s', '<w:body>' . $bodyXml . '<w:sectPr', $xml, 1);
    if ($xml === null) { $zip->close(); return false; }
    $ok = $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    return (bool)$ok;
}
function pdf_extract_text(string $path): string {
    $raw = @file_get_contents($path); if ($raw === false) return '';
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $raw);
    preg_match_all('/\(([^()]*)\)/s', $text, $matches);
    $pieces = [];
    foreach (($matches[1] ?? []) as $m) {
        $m = preg_replace('/\\\\[nrtbf]/', ' ', $m);
        $m = str_replace(['\\\\(', '\\\\)', '\\\\\\\\'], ['(', ')', '\\\\'], $m);
        if (preg_match('/[A-Za-z]{3,}/', $m)) $pieces[] = $m;
    }
    return trim((string)preg_replace('/\s+/', ' ', implode("\n", $pieces)));
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
        return ['mode'=>'text','editable'=>true,'content'=>$content,'stats'=>['characters'=>mb_strlen($content),'lines'=>substr_count($content, "\n")+1],'limitations'=>[]];
    }
    if ($mode === 'docx') {
        if ($ext === 'doc') {
            return ['mode'=>'docx','editable'=>false,'content'=>'','paragraphs'=>[],'limitations'=>['Legacy .doc binary editing is not supported directly.','Convert .doc to .docx for structured editing/export.']];
        }
        $text = parse_docx_text($path);
        return ['mode'=>'docx','editable'=>true,'content'=>$text,'paragraphs'=>array_values(array_filter(array_map('trim', preg_split("/\R{2,}/", $text) ?: []))),'limitations'=>['Practical paragraph-based docx editing/export only.']];
    }
    if ($mode === 'svg') {
        $content = file_get_contents($path) ?: '';
        preg_match('/width="([^"]+)"/i', $content, $w);
        preg_match('/height="([^"]+)"/i', $content, $h);
        return ['mode'=>'svg','editable'=>true,'content'=>$content,'svg_preview'=>$content,'properties'=>['width'=>$w[1] ?? '', 'height'=>$h[1] ?? ''],'limitations'=>[]];
    }
    if ($mode === 'image') {
        $size = @getimagesize($path);
        return ['mode'=>'image','editable'=>true,'content'=>'','preview_url'=>'?action=download&inline=1&file=' . rawurlencode($originalName),'metadata'=>['width'=>$size[0] ?? null,'height'=>$size[1] ?? null,'mime'=>$mime,'filename'=>$originalName],'limitations'=>['Crop is starter-level; rotate/flip/brightness/contrast are browser-assisted before save.']];
    }
    if ($mode === 'pdf') {
        return ['mode'=>'pdf','editable'=>false,'content'=>pdf_extract_text($path),'preview_url'=>'?action=download&inline=1&file=' . rawurlencode($originalName),'limitations'=>['PDF freeform paragraph editing is restricted in this safe single-file version.','Use preview and text extraction workflows.']];
    }
    if ($mode === 'exe') {
        $head = file_get_contents($path, false, null, 0, 512) ?: '';
        return ['mode'=>'exe','editable'=>false,'content'=>'','metadata'=>['filename'=>$originalName,'size'=>filesize($path) ?: 0,'sha256'=>hash_file('sha256',$path),'md5'=>hash_file('md5',$path),'header_hex'=>strtoupper(bin2hex(substr($head,0,64)))],'limitations'=>['EXE handling is inspection-only.','Uploaded binaries are never executed.','No freeform binary editing is provided.']];
    }
    return ['mode'=>'text','editable'=>true,'content'=>'','limitations'=>[]];
}
function image_save_from_data_url(string $path, string $dataUrl): bool {
    if (!preg_match('/^data:image\/(png|jpeg);base64,(.*)$/', $dataUrl, $m)) return false;
    $binary = base64_decode($m[2], true);
    if ($binary === false) return false;
    return (bool)file_put_contents($path, $binary);
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action === 'download') {
    $storedName = basename((string)($_GET['file'] ?? ''));
    $path = original_path($storedName);
    if (!is_file($path)) { http_response_code(404); exit('File not found'); }
    $mime = detect_mime($path);
    $inline = !empty($_GET['inline']);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)(filesize($path) ?: 0));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename($storedName) . '"');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ($action === 'upload') {
        if (empty($_FILES['file'])) { flash('error', 'No file uploaded.'); redirect('/'); }
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { flash('error', 'Upload failed.'); redirect('/'); }
        $originalName = sanitize_filename((string)$file['name']);
        if (!is_allowed($originalName)) { flash('error', 'Unsupported file type.'); redirect('/'); }
        if (($file['size'] ?? 0) > MAX_UPLOAD_BYTES) { flash('error', 'File exceeds 25MB limit.'); redirect('/'); }
        $storedName = date('YmdHis') . '_' . $originalName;
        $target = original_path($storedName);
        if (!move_uploaded_file((string)$file['tmp_name'], $target)) { flash('error', 'Unable to store uploaded file.'); redirect('/'); }
        audit("Uploaded {$storedName} by User");
        flash('success', 'File uploaded successfully.');
        redirect('/?file=' . rawurlencode($storedName));
    }

    if ($action === 'save') {
        $storedName = basename((string)($_POST['file'] ?? ''));
        $path = original_path($storedName);
        if ($storedName === '' || !is_file($path)) { flash('error', 'File not found.'); redirect('/'); }
        create_version($storedName);
        $mime = detect_mime($path);
        $mode = adapter_key($storedName, $mime);
        $ok = false;
        $msg = 'Save failed.';
        if ($mode === 'text' || $mode === 'svg') {
            $content = (string)($_POST['content'] ?? '');
            $ok = (bool)file_put_contents($path, $content);
            $msg = $ok ? strtoupper($mode) . ' saved.' : 'Unable to save file.';
        } elseif ($mode === 'docx') {
            if (file_ext($storedName) === 'docx') {
                $ok = save_docx_text($path, (string)($_POST['content'] ?? ''));
                $msg = $ok ? 'DOCX saved.' : 'DOCX save failed.';
            } else {
                $msg = '.doc save is restricted. Convert to .docx.';
            }
        } elseif ($mode === 'image') {
            $ok = image_save_from_data_url($path, (string)($_POST['imageData'] ?? ''));
            $msg = $ok ? 'Image saved.' : 'Image save failed.';
        } elseif ($mode === 'pdf') {
            $msg = 'PDF freeform editing is restricted in this version.';
        } elseif ($mode === 'exe') {
            $msg = 'EXE files are inspection-only in this safe mode.';
        }
        if ($ok) { audit("Saved {$storedName} by User"); flash('success', $msg); } else { flash('error', $msg); }
        redirect('/?file=' . rawurlencode($storedName));
    }

    if ($action === 'restore') {
        $storedName = basename((string)($_POST['file'] ?? ''));
        $versionName = basename((string)($_POST['version'] ?? ''));
        $original = original_path($storedName);
        $version = version_dir($storedName) . '/' . $versionName;
        if (!is_file($original) || !is_file($version)) { flash('error', 'Version not found.'); redirect('/'); }
        create_version($storedName);
        copy($version, $original);
        audit("Restored version {$versionName} for {$storedName} by User");
        flash('success', 'Version restored.');
        redirect('/?file=' . rawurlencode($storedName));
    }
}

$files = list_original_files();
$selectedStored = basename((string)($_GET['file'] ?? ($files[0]['stored_name'] ?? '')));
$selectedPath = $selectedStored !== '' ? original_path($selectedStored) : '';
$selected = null;
foreach ($files as $f) { if ($f['stored_name'] === $selectedStored) { $selected = $f; break; } }
$fileData = ($selected && is_file($selectedPath)) ? load_adapter_data($selectedPath, $selectedStored, $selected['mime']) : null;
$versions = $selected ? list_versions($selectedStored) : [];
$flashSuccess = pull_flash('success');
$flashError = pull_flash('error');
$auditPath = logs_dir() . '/audit.log';
$auditLines = is_file($auditPath) ? array_slice(array_reverse(file($auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 0, 12) : [];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= h(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.52.2/min/vs/loader.min.js"></script>
  <style>
    :root{--jo-bg:#000;--jo-text:#fff;--jo-green:#00c853;--jo-border:rgba(255,255,255,.14);--jo-card:rgba(255,255,255,.06);--jo-nav:rgba(0,0,0,.78);--jo-muted:rgba(255,255,255,.72)}
    body{background:var(--jo-bg);color:var(--jo-text);min-height:100vh}
    .jo-shell{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    .jo-sidebar{background:rgba(255,255,255,.03);border-right:1px solid var(--jo-border)}
    .jo-topbar{background:var(--jo-nav);border-bottom:1px solid var(--jo-border);backdrop-filter:blur(10px)}
    .jo-card{background:var(--jo-card);border:1px solid var(--jo-border);border-radius:18px;box-shadow:0 10px 35px rgba(0,0,0,.35)}
    .btn-jo{background:var(--jo-green)!important;color:#000!important;font-weight:800!important;border:none!important}
    .btn-outline-jo{border:1px solid var(--jo-green)!important;color:var(--jo-green)!important;background:transparent!important;font-weight:800!important}
    .btn-outline-jo:hover{background:rgba(0,200,83,.12)!important;color:#fff!important}
    .jo-muted{color:var(--jo-muted)}
    .sidebar-link{display:block;padding:.85rem 1rem;border-radius:12px;color:#fff;text-decoration:none}
    .sidebar-link:hover{background:rgba(255,255,255,.05);color:#fff}
    .dropzone{border:2px dashed rgba(255,255,255,.18);border-radius:18px;background:rgba(255,255,255,.03)}
    textarea,input,select{background:rgba(255,255,255,.06)!important;color:#fff!important;border:1px solid rgba(255,255,255,.15)!important}
    .meta-grid{display:grid;grid-template-columns:140px 1fr;gap:8px}
    .file-card{cursor:pointer}
    .file-card.active{border-color:rgba(0,200,83,.55)}
    .modal-content{background:#050505;border:1px solid var(--jo-border);border-radius:18px}
    .modal-header,.modal-footer{border-color:var(--jo-border)!important}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
    #monacoHost{height:420px;border:1px solid rgba(255,255,255,.15);border-radius:12px;overflow:hidden}
    .toolbar-scroll{overflow:auto;white-space:nowrap}
    .img-edit-wrap{min-height:420px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.03);border-radius:14px}
    @media (max-width:991px){.jo-shell{grid-template-columns:1fr}.jo-sidebar{display:none}}
  </style>
</head>
<body>
<div class="jo-shell">
  <aside class="jo-sidebar p-3">
    <div class="d-flex align-items-center gap-2 mb-4"><i class="fa-solid fa-folder-tree text-success"></i><div class="fw-bold"><?= h(APP_NAME) ?></div></div>
    <div class="d-grid gap-2">
      <button class="sidebar-link text-start" data-bs-toggle="modal" data-bs-target="#dashboardModal"><i class="fa-solid fa-gauge me-2"></i>Dashboard</button>
      <button class="sidebar-link text-start" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="fa-solid fa-upload me-2"></i>Upload File</button>
      <button class="sidebar-link text-start" data-bs-toggle="modal" data-bs-target="#editorModal"><i class="fa-solid fa-pen-to-square me-2"></i>Editor Workspace</button>
      <button class="sidebar-link text-start" data-bs-toggle="modal" data-bs-target="#previewModal"><i class="fa-regular fa-eye me-2"></i>Preview Panel</button>
      <button class="sidebar-link text-start" data-bs-toggle="modal" data-bs-target="#versionsModal"><i class="fa-solid fa-clock-rotate-left me-2"></i>Version History</button>
      <button class="sidebar-link text-start" data-bs-toggle="modal" data-bs-target="#metadataModal"><i class="fa-solid fa-circle-info me-2"></i>File Metadata</button>
      <button class="sidebar-link text-start" data-bs-toggle="modal" data-bs-target="#settingsModal"><i class="fa-solid fa-gear me-2"></i>Settings</button>
      <button class="sidebar-link text-start" data-bs-toggle="modal" data-bs-target="#helpModal"><i class="fa-regular fa-life-ring me-2"></i>Help / Supported Formats</button>
    </div>
  </aside>

  <main>
    <div class="jo-topbar px-4 py-3 d-flex justify-content-between align-items-center sticky-top">
      <div class="fw-bold"><?= h(APP_NAME) ?></div>
      <div class="jo-muted small">All dashboard functions are available through modal popups</div>
    </div>

    <div class="container-fluid p-4">
      <?php if ($flashSuccess): ?><div class="alert alert-success"><?= h($flashSuccess) ?></div><?php endif; ?>
      <?php if ($flashError): ?><div class="alert alert-danger"><?= h($flashError) ?></div><?php endif; ?>

      <div class="row g-4">
        <div class="col-xl-8">
          <div class="jo-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
              <div>
                <h1 class="h3 mb-1">Universal File Workbench</h1>
                <div class="jo-muted">Use the left dashboard menu to open Upload, Editor, Preview, Version History, Metadata, Settings, and Help in modal popups.</div>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-jo" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="fa-solid fa-upload me-2"></i>Upload File</button>
                <button class="btn btn-outline-jo" data-bs-toggle="modal" data-bs-target="#editorModal"><i class="fa-solid fa-pen-to-square me-2"></i>Open Editor</button>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-6"><div class="jo-card p-3"><div class="fw-bold mb-1">Current File</div><div class="jo-muted small"><?= $selected ? h($selected['name']) : 'None selected' ?></div></div></div>
              <div class="col-md-6"><div class="jo-card p-3"><div class="fw-bold mb-1">Mode</div><div class="jo-muted small"><?= $fileData ? h((string)$fileData['mode']) : '—' ?></div></div></div>
              <div class="col-md-6"><div class="jo-card p-3"><div class="fw-bold mb-1">Versions</div><div class="jo-muted small"><?= count($versions) ?></div></div></div>
              <div class="col-md-6"><div class="jo-card p-3"><div class="fw-bold mb-1">Audit Entries</div><div class="jo-muted small"><?= count($auditLines) ?></div></div></div>
            </div>
          </div>
        </div>
        <div class="col-xl-4">
          <div class="jo-card p-4 mb-4">
            <div class="fw-bold mb-3">Recent Files</div>
            <div class="d-grid gap-2">
              <?php if (!$files): ?>
                <div class="jo-muted">No files uploaded yet.</div>
              <?php else: foreach ($files as $f): ?>
                <a class="file-card jo-card p-3 text-decoration-none text-white <?= $selected && $selected['stored_name'] === $f['stored_name'] ? 'active' : '' ?>" href="?file=<?= rawurlencode($f['stored_name']) ?>">
                  <div class="fw-bold"><?= h($f['name']) ?></div>
                  <div class="jo-muted small"><?= h($f['ext']) ?> • <?= h(format_bytes((int)$f['size'])) ?></div>
                </a>
              <?php endforeach; endif; ?>
            </div>
          </div>
          <div class="jo-card p-4">
            <div class="fw-bold mb-3">Audit Log</div>
            <div class="small jo-muted mono" style="max-height:260px;overflow:auto;white-space:pre-wrap"><?php foreach ($auditLines as $line): ?><?= h($line) . "\n" ?><?php endforeach; ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="px-4 py-2 small border-top" style="border-color:var(--jo-border)!important;background:rgba(0,0,0,.88)">Another Website by Julius Olatokunbo</div>
  </main>
</div>

<!-- Dashboard Modal -->
<div class="modal fade" id="dashboardModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-gauge me-2 text-success"></i>Dashboard</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-3"><div class="jo-card p-3"><div class="fw-bold">Files</div><div class="jo-muted small"><?= count($files) ?></div></div></div><div class="col-md-3"><div class="jo-card p-3"><div class="fw-bold">Versions</div><div class="jo-muted small"><?= count($versions) ?></div></div></div><div class="col-md-3"><div class="jo-card p-3"><div class="fw-bold">Selected Mode</div><div class="jo-muted small"><?= $fileData ? h((string)$fileData['mode']) : '—' ?></div></div></div><div class="col-md-3"><div class="jo-card p-3"><div class="fw-bold">Max Upload</div><div class="jo-muted small"><?= h(format_bytes(MAX_UPLOAD_BYTES)) ?></div></div></div></div><div class="mt-4 jo-card p-3"><div class="fw-bold mb-2">Overview</div><div class="jo-muted">This single-file edition exposes all dashboard features through modal popups while retaining the same upload, preview, edit, version, metadata, settings, and help workflows.</div></div></div><div class="modal-footer"><button class="btn btn-outline-jo" data-bs-dismiss="modal">Close</button></div></div></div></div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-upload me-2 text-success"></i>Upload File</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><form action="?action=upload" method="post" enctype="multipart/form-data" id="uploadForm"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><div id="dropzone" class="dropzone p-5 text-center"><div class="mb-3"><i class="fa-solid fa-cloud-arrow-up fa-3x text-success"></i></div><div class="fw-bold mb-2">Drag and drop a file here</div><div class="jo-muted mb-3">or choose a file manually</div><input type="file" name="file" id="uploadInput" class="form-control mb-3" required><div class="small jo-muted">Supported: DOC/DOCX, TXT/MD/CSV/JSON/XML, SVG, JPG/JPEG/PNG, PDF, EXE</div></div></form></div><div class="modal-footer"><button class="btn btn-outline-jo" data-bs-dismiss="modal">Cancel</button><button class="btn btn-jo" onclick="document.getElementById('uploadForm').submit()">Upload</button></div></div></div></div>

<!-- Editor Modal -->
<div class="modal fade" id="editorModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-pen-to-square me-2 text-success"></i>Editor Workspace</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body">
<?php if (!$selected || !$fileData): ?>
  <div class="jo-card p-4 jo-muted">Upload or select a file first.</div>
<?php else: ?>
  <form method="post" action="?action=save" id="saveForm">
    <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="file" value="<?= h($selectedStored) ?>">
    <input type="hidden" id="imageData" name="imageData" value="">
    <div class="toolbar-scroll d-flex gap-2 mb-3 flex-wrap">
      <?php if (in_array($fileData['mode'], ['text','docx'], true)): ?>
        <input id="searchText" class="form-control w-auto" placeholder="Search">
        <input id="replaceText" class="form-control w-auto" placeholder="Replace">
        <button class="btn btn-outline-jo btn-sm" type="button" onclick="textSearchReplace()">Search & Replace</button>
      <?php endif; ?>
      <?php if ($fileData['mode'] === 'svg'): ?>
        <button class="btn btn-outline-jo btn-sm" type="button" onclick="refreshSvgPreview()">Refresh Preview</button>
      <?php endif; ?>
      <?php if ($fileData['mode'] === 'image'): ?>
        <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('rotate')">Rotate</button>
        <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('flipX')">Flip X</button>
        <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('flipY')">Flip Y</button>
        <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('bright+')">Brightness +</button>
        <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('bright-')">Brightness -</button>
        <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('contrast+')">Contrast +</button>
        <button class="btn btn-outline-jo btn-sm" type="button" onclick="applyImageToolbar('contrast-')">Contrast -</button>
      <?php endif; ?>
      <?php if (!in_array($fileData['mode'], ['pdf','exe'], true) && !empty($fileData['editable'])): ?>
        <button class="btn btn-jo btn-sm" type="submit">Save Version</button>
      <?php endif; ?>
    </div>

    <?php if ($fileData['mode'] === 'text' || $fileData['mode'] === 'docx'): ?>
      <div class="mb-3"><div id="monacoHost"></div><textarea id="content" name="content" class="d-none"><?= h((string)$fileData['content']) ?></textarea></div>
    <?php elseif ($fileData['mode'] === 'svg'): ?>
      <div class="row g-3"><div class="col-lg-6"><div id="monacoHost"></div><textarea id="content" name="content" class="d-none"><?= h((string)$fileData['content']) ?></textarea></div><div class="col-lg-6"><div id="svgPreview" class="jo-card p-3 h-100 overflow-auto"></div></div></div>
    <?php elseif ($fileData['mode'] === 'image'): ?>
      <div class="img-edit-wrap"><img id="editableImage" src="?action=download&amp;inline=1&amp;file=<?= rawurlencode($selectedStored) ?>" alt="Image Preview" class="img-fluid rounded" style="max-height:520px"></div>
    <?php elseif ($fileData['mode'] === 'pdf'): ?>
      <div class="alert alert-secondary">PDF is preview/extraction oriented in this version.</div><iframe src="?action=download&amp;inline=1&amp;file=<?= rawurlencode($selectedStored) ?>" style="width:100%;height:420px;border:0;border-radius:12px"></iframe><div class="mt-3"><label class="form-label">Extracted Text</label><textarea id="content" class="form-control mono" rows="10" readonly><?= h((string)$fileData['content']) ?></textarea></div>
    <?php elseif ($fileData['mode'] === 'exe'): ?>
      <div class="alert alert-warning">EXE handling is inspection-only. Uploaded binaries are never executed.</div><div class="jo-card p-3"><?php foreach (($fileData['metadata'] ?? []) as $k => $v): ?><div class="meta-grid mb-2"><div class="jo-muted"><?= h((string)$k) ?></div><div class="text-break mono"><?= h((string)$v) ?></div></div><?php endforeach; ?></div>
    <?php endif; ?>

    <?php if (!empty($fileData['limitations'])): ?><div class="alert alert-secondary mt-3"><strong>Limitations</strong><ul class="mb-0"><?php foreach ($fileData['limitations'] as $lim): ?><li><?= h((string)$lim) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  </form>
<?php endif; ?>
</div><div class="modal-footer"><?php if ($selected): ?><a class="btn btn-outline-jo" href="?action=download&amp;file=<?= rawurlencode($selectedStored) ?>"><i class="fa-solid fa-download me-2"></i>Download</a><?php endif; ?><button class="btn btn-outline-jo" data-bs-dismiss="modal">Close</button></div></div></div></div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-regular fa-eye me-2 text-success"></i>Preview Panel</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body">
<?php if (!$selected || !$fileData): ?><div class="jo-card p-4 jo-muted">No file selected.</div><?php else: ?>
  <?php if (in_array($fileData['mode'], ['image'], true)): ?>
    <div class="text-center"><img src="?action=download&amp;inline=1&amp;file=<?= rawurlencode($selectedStored) ?>" class="img-fluid rounded" style="max-height:580px"></div>
  <?php elseif ($fileData['mode'] === 'pdf'): ?>
    <iframe src="?action=download&amp;inline=1&amp;file=<?= rawurlencode($selectedStored) ?>" style="width:100%;height:560px;border:0;border-radius:12px"></iframe>
  <?php elseif ($fileData['mode'] === 'svg'): ?>
    <div class="jo-card p-3 overflow-auto"><?= $fileData['svg_preview'] ?? '' ?></div>
  <?php elseif (in_array($fileData['mode'], ['text','docx'], true)): ?>
    <pre class="jo-card p-3 mono" style="white-space:pre-wrap"><?= h((string)$fileData['content']) ?></pre>
  <?php else: ?>
    <div class="jo-card p-3 jo-muted">Preview is not available for this mode beyond metadata inspection.</div>
  <?php endif; ?>
<?php endif; ?>
</div><div class="modal-footer"><button class="btn btn-outline-jo" data-bs-dismiss="modal">Close</button></div></div></div></div>

<!-- Versions Modal -->
<div class="modal fade" id="versionsModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-clock-rotate-left me-2 text-success"></i>Version History</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php if (!$selected): ?><div class="jo-card p-3 jo-muted">Select a file first.</div><?php elseif (!$versions): ?><div class="jo-card p-3 jo-muted">No versions yet. Every save creates a timestamped version.</div><?php else: ?><div class="d-grid gap-2"><?php foreach ($versions as $v): ?><form method="post" action="?action=restore" class="jo-card p-3"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="file" value="<?= h($selectedStored) ?>"><input type="hidden" name="version" value="<?= h($v['name']) ?>"><div class="fw-bold small"><?= h($v['name']) ?></div><div class="jo-muted small"><?= h(date('Y-m-d H:i:s', (int)$v['timestamp'])) ?> • <?= h(format_bytes((int)$v['size'])) ?> • User</div><button class="btn btn-outline-jo btn-sm mt-2">Restore Version</button></form><?php endforeach; ?></div><?php endif; ?></div><div class="modal-footer"><button class="btn btn-outline-jo" data-bs-dismiss="modal">Close</button></div></div></div></div>

<!-- Metadata Modal -->
<div class="modal fade" id="metadataModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-circle-info me-2 text-success"></i>File Metadata</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><?php if (!$selected): ?><div class="jo-card p-3 jo-muted">No file selected.</div><?php else: ?><div class="meta-grid"><?php foreach (['name'=>$selected['name'],'stored_name'=>$selected['stored_name'],'ext'=>$selected['ext'],'mime'=>$selected['mime'],'size'=>format_bytes((int)$selected['size']),'modified'=>date('Y-m-d H:i:s',(int)$selected['mtime'])] as $k=>$v): ?><div class="jo-muted"><?= h((string)$k) ?></div><div class="text-break mono"><?= h((string)$v) ?></div><?php endforeach; ?></div><?php if (!empty($fileData['metadata']) && is_array($fileData['metadata'])): ?><hr><?php foreach ($fileData['metadata'] as $k => $v): ?><div class="meta-grid mb-2"><div class="jo-muted"><?= h((string)$k) ?></div><div class="text-break mono"><?= h((string)$v) ?></div></div><?php endforeach; ?><?php endif; ?><?php endif; ?></div><div class="modal-footer"><button class="btn btn-outline-jo" data-bs-dismiss="modal">Close</button></div></div></div></div>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-solid fa-gear me-2 text-success"></i>Settings</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="jo-card p-3 mb-3"><div class="fw-bold mb-2">Runtime</div><div class="meta-grid"><div class="jo-muted">PHP</div><div><?= h(PHP_VERSION) ?></div><div class="jo-muted">Upload limit</div><div><?= h(format_bytes(MAX_UPLOAD_BYTES)) ?></div><div class="jo-muted">Storage root</div><div class="mono"><?= h(storage_root()) ?></div></div></div><div class="jo-card p-3"><div class="fw-bold mb-2">Security</div><ul class="mb-0 jo-muted"><li>CSRF protection enabled for upload/save/restore.</li><li>Uploads are stored outside direct execution workflows.</li><li>EXE files are inspection-only and never executed.</li></ul></div></div><div class="modal-footer"><button class="btn btn-outline-jo" data-bs-dismiss="modal">Close</button></div></div></div></div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-regular fa-life-ring me-2 text-success"></i>Help / Supported Formats</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><div class="jo-card p-3 h-100"><div class="fw-bold mb-2">Supported Formats</div><ul class="jo-muted mb-0"><li>Text: txt, md, csv, json, xml</li><li>Word: doc, docx</li><li>SVG</li><li>Images: jpg, jpeg, png</li><li>PDF</li><li>EXE (inspection only)</li></ul></div></div><div class="col-md-6"><div class="jo-card p-3 h-100"><div class="fw-bold mb-2">Editing Rules</div><ul class="jo-muted mb-0"><li>Text files: full text workflow</li><li>DOCX: practical paragraph workflow</li><li>SVG: raw markup + live preview</li><li>Images: rotate / flip / brightness / contrast</li><li>PDF: preview + extraction</li><li>EXE: metadata only</li></ul></div></div></div></div><div class="modal-footer"><button class="btn btn-outline-jo" data-bs-dismiss="modal">Close</button></div></div></div></div>

<!-- Contact Modal -->
<div class="modal fade" id="contactModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fa-regular fa-envelope me-2 text-success"></i>Contact</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><div class="ratio ratio-16x9" style="min-height:70vh"><iframe src="https://www.raiiarcomio.com/contact2" title="Contact" style="border:0;background:#000" referrerpolicy="no-referrer" allowfullscreen></iframe></div></div><div class="modal-footer"><a class="btn btn-jo" href="https://www.raiiarcom.io/contact" target="_blank" rel="noreferrer">Open in new tab</a><button class="btn btn-outline-jo" data-bs-dismiss="modal">Close</button></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
let monacoEditor = null;
function bindDropzone(){
  const dz = document.getElementById('dropzone');
  const input = document.getElementById('uploadInput');
  if(!dz || !input) return;
  ['dragenter','dragover'].forEach(evt=>dz.addEventListener(evt,e=>{e.preventDefault();dz.classList.add('border-success');}));
  ['dragleave','drop'].forEach(evt=>dz.addEventListener(evt,e=>{e.preventDefault();dz.classList.remove('border-success');}));
  dz.addEventListener('drop', e=>{ if(e.dataTransfer.files.length){ input.files = e.dataTransfer.files; } });
}
function textSearchReplace(){
  const ta = document.getElementById('content');
  const search = document.getElementById('searchText');
  const replace = document.getElementById('replaceText');
  if(!ta || !search) return;
  const needle = search.value; if(!needle) return;
  const val = (monacoEditor ? monacoEditor.getValue() : ta.value).split(needle).join(replace ? replace.value : '');
  if(monacoEditor){ monacoEditor.setValue(val); } else { ta.value = val; }
  refreshSvgPreview();
}
function refreshSvgPreview(){
  const preview = document.getElementById('svgPreview');
  const ta = document.getElementById('content');
  if(preview && ta){ preview.innerHTML = monacoEditor ? monacoEditor.getValue() : ta.value; }
}
function applyImageToolbar(action){
  const img = document.getElementById('editableImage'); if(!img) return;
  let rotate = parseFloat(img.dataset.rotate || '0');
  let scaleX = parseFloat(img.dataset.scaleX || '1');
  let scaleY = parseFloat(img.dataset.scaleY || '1');
  let brightness = parseFloat(img.dataset.brightness || '100');
  let contrast = parseFloat(img.dataset.contrast || '100');
  if(action==='rotate') rotate += 90;
  if(action==='flipX') scaleX *= -1;
  if(action==='flipY') scaleY *= -1;
  if(action==='bright+') brightness += 10;
  if(action==='bright-') brightness -= 10;
  if(action==='contrast+') contrast += 10;
  if(action==='contrast-') contrast -= 10;
  img.dataset.rotate = rotate; img.dataset.scaleX = scaleX; img.dataset.scaleY = scaleY; img.dataset.brightness = brightness; img.dataset.contrast = contrast;
  img.style.transform = `rotate(${rotate}deg) scale(${scaleX}, ${scaleY})`;
  img.style.filter = `brightness(${brightness}%) contrast(${contrast}%)`;
}
function saveImageToHiddenInput(){
  const img = document.getElementById('editableImage');
  const hidden = document.getElementById('imageData');
  if(!img || !hidden) return;
  const canvas = document.createElement('canvas');
  const w = img.naturalWidth || img.width; const h = img.naturalHeight || img.height;
  canvas.width = w; canvas.height = h;
  const ctx = canvas.getContext('2d');
  ctx.filter = img.style.filter || 'none';
  ctx.translate(w/2, h/2);
  const rotate = parseFloat(img.dataset.rotate || '0') * Math.PI / 180;
  const scaleX = parseFloat(img.dataset.scaleX || '1');
  const scaleY = parseFloat(img.dataset.scaleY || '1');
  ctx.rotate(rotate); ctx.scale(scaleX, scaleY);
  ctx.drawImage(img, -w/2, -h/2, w, h);
  hidden.value = canvas.toDataURL('image/png');
}
function initMonaco(){
  const host = document.getElementById('monacoHost');
  const ta = document.getElementById('content');
  if(!host || !ta || typeof require === 'undefined') return;
  require.config({ paths: { vs: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.52.2/min/vs' }});
  require(['vs/editor/editor.main'], function(){
    if(monacoEditor){ monacoEditor.dispose(); }
    monacoEditor = monaco.editor.create(host, {
      value: ta.value || '',
      language: 'plaintext',
      theme: 'vs-dark',
      automaticLayout: true,
      minimap: { enabled: false }
    });
    monacoEditor.onDidChangeModelContent(()=>{ ta.value = monacoEditor.getValue(); refreshSvgPreview(); });
    refreshSvgPreview();
  });
}
document.addEventListener('DOMContentLoaded', ()=>{
  bindDropzone();
  const saveForm = document.getElementById('saveForm');
  if(saveForm){ saveForm.addEventListener('submit', ()=>{ if(document.getElementById('editableImage')) saveImageToHiddenInput(); if(monacoEditor && document.getElementById('content')) document.getElementById('content').value = monacoEditor.getValue(); }); }
  const editorModal = document.getElementById('editorModal');
  if(editorModal){ editorModal.addEventListener('shown.bs.modal', ()=>{ setTimeout(initMonaco, 50); }); }
});
</script>
</body>
</html>
