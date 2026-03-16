# JO Universal File Editor — Single File Edition

This ZIP contains a **single-file PHP version** of the JO Universal File Editor.

## Included files

- `index.php` — the complete one-file application
- `README.md` — setup and usage notes

## What this single-file edition supports

The app provides one unified editor shell with internal file-type-specific modes:

- Text files: `.txt`, `.md`, `.csv`, `.json`, `.xml`
- Word files: `.docx` with practical paragraph-based editing
- Legacy `.doc`: restricted, with clear UI notice
- SVG: raw markup editing + live preview
- JPG / JPEG / PNG: browser-assisted rotate / flip / brightness / contrast
- PDF: preview + text extraction workflow
- EXE: safe inspection-only mode

## Main features

- Upload file
- File explorer / recent files
- Editor workspace
- Preview panel
- File metadata
- Version history
- Download current file
- Restore previous version
- Audit log
- Drag-and-drop upload
- Monaco editor for text-like formats
- JO-branded UI:
  - black background
  - white text
  - green action buttons

## Storage structure

When you first run `index.php`, it creates:

```text
/storage
  /originals
  /versions
  /temp
  /logs
```

### Meaning

- `storage/originals` — uploaded working files
- `storage/versions` — timestamped save versions
- `storage/temp` — reserved temp area
- `storage/logs/audit.log` — audit trail

## Requirements

- PHP 8+
- `ZipArchive` enabled for `.docx` workflow
- writable directory for `storage/`

## Run locally

### Option 1 — PHP built-in server

```bash
php -S localhost:8000
```

Then open:

```text
http://localhost:8000/index.php
```

### Option 2 — Apache / Nginx
Place `index.php` in your web root and browse to it normally.

## Important limitations

### DOC / DOCX
- `.docx` is supported with a practical text-based workflow
- `.doc` binary editing is **not** fully supported
- the UI clearly tells the user to convert `.doc` to `.docx`

### PDF
- full arbitrary paragraph editing is **not** supported
- current workflow supports:
  - preview
  - extraction
  - safe extension path for annotation / overlay / stamping

### EXE
- inspection only
- hashes and header info are shown
- uploaded binaries are never executed
- no freeform binary editing is provided

### Images
- this edition uses browser-assisted adjustment and save-back
- crop and watermark can be added later, but rotate / flip / brightness / contrast are included as a practical starter

## Security notes

- file extension validation
- file size limit: 25MB
- uploaded files stored outside direct application logic folders
- CSRF protection on save / restore / upload
- audit logging on upload / save / version / restore
- EXE files are never executed

## Versioning

Every save creates a timestamped copy in:

```text
storage/versions/{fileId}/
```

You can restore older versions from the right-hand version history panel.

## Branding

Title:
`JO Universal File Editor`

Footer:
`Another Website by Julius Olatokunbo`

## Suggested future upgrades

- richer PDF annotation tools
- true image crop / watermark UI
- advanced DOCX structure extraction
- private login / role-based access
- multi-user audit labels
- thumbnail generation
- metadata editing for PDFs
- icon/resource replacement sandbox for EXE inspection workflows

## Summary

This ZIP is the **single-file refactor** of the larger multi-file project:

- one `index.php`
- one `README.md`
- browser-based file workbench
- unified shell
- file-specific safe workflows
