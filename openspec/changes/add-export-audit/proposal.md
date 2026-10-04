# Change: Record account exports

## Why
The action log records edits and deletions, but cannot answer who exported account data or which statuses were included.

## What Changes
- Record authenticated account CSV/TXT and action-journal CSV export requests in MySQL before returning data.
- Store login, IP, time, table, format, scope, selected columns, actual row count, status counts, and outcome. Do not store account secrets or file contents.
- Show a paginated export journal linked from the existing action journal.
- Record each served TXT chunk; a chunk event means rows were returned to the browser, not proof that the final local file was saved.

## Impact
- Affected specs: data-export
- Affected code: export.php, export_chunk.php, includes/ExportAudit.php, export_logs.php, admin_logs.php
- A new export_audit table is created on first audited request.
