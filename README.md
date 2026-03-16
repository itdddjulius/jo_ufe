# JO Universal File Editor — Single File Modal Edition

This package contains a **single-file PHP application** named `index.php`.

It refactors the Universal File Editor single-page version so that all dashboard menu options now work through **modal popups**:

- Dashboard
- Upload File
- Editor Workspace
- Preview Panel
- Version History
- File Metadata
- Settings
- Help / Supported Formats

## What changed

The left dashboard navigation no longer relies on separate pages or inline sections for these tools. Each option opens a Bootstrap modal and performs its function there.

## Included files

- `index.php` — the full single-file PHP application
- `README.md` — this setup guide

## Features in this single-file edition

- PHP 8+ single-file architecture
- Upload and storage handling
- File explorer / recent files
- Text editor workflow
- DOCX practical paragraph editing workflow
- Live SVG preview
- Image preview with rotate / flip / brightness / contrast
- PDF preview + text extraction
- EXE inspection-only metadata mode
- Version history and restore
- Audit logging
- Modal-based dashboard functions
- JO-branded UI

## Storage layout

When `index.php` runs, it creates the following folders next to itself:

```text
/storage
  /originals
  /versions
  /temp
  /logs
```

## Requirements

- PHP 8+
- Apache, Nginx, or PHP built-in server
- `ZipArchive` enabled for DOCX practical editing support
- `fileinfo` enabled for MIME detection

## Run locally

From the folder containing `index.php`:

```bash
php -S localhost:8000
```

Then open:

```text
http://localhost:8000/index.php
```

## How the modal workflow behaves

### Dashboard Modal
Shows overview cards and current system state.

### Upload File Modal
Supports file upload and drag-and-drop.

### Editor Workspace Modal
Shows the correct editor shell for the selected file type.

### Preview Panel Modal
Shows file preview when available.

### Version History Modal
Shows timestamped versions and allows restore.

### File Metadata Modal
Shows file size, type, MIME, and adapter-specific metadata.

### Settings Modal
Shows runtime and security notes.

### Help / Supported Formats Modal
Shows file-type support and editing limitations.

## Supported file types

- DOC / DOCX
- TXT / MD / CSV / JSON / XML
- SVG
- JPG / JPEG / PNG
- PDF
- EXE

## Important safe limitations

### DOC
Legacy `.doc` editing is restricted. Convert to `.docx` for practical editing.

### PDF
This version supports preview + extraction workflows, not arbitrary paragraph rewriting.

### EXE
EXE handling is inspection-only. Uploaded binaries are never executed.

## Notes

This is a **single-file practical starter**. It is designed to be understandable and portable, while still preserving the main universal editor workflow in one PHP file.

## Footer branding

The UI includes:

```text
Another Website by Julius Olatokunbo
```
