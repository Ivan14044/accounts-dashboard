## Context
The dashboard authenticates with database connection credentials and uses `user@host` as the session login. Multiple people using one connection string are therefore indistinguishable by login. The accepted first version records that login and `REMOTE_ADDR` IP. Account exports have a streaming file endpoint and a browser-assembled TXT endpoint; the action journal has its own CSV endpoint.

## Decisions
- Create one `export_audit` row per server request before returning data. A file export finalizes after streaming; each TXT ID-list or row-chunk request has its own row. This records what the server returned without claiming that a browser saved a local file.
- Count statuses from the rows actually read for export, including when the status column was not selected for the TXT file. Store only counts and selected column names, never exported values or credentials.
- A missing audit table is created using the existing `AuditLogger` self-migration pattern. Failure to create or insert blocks data export.
- Show the journal to authenticated users, matching access to the existing `admin_logs.php` page. Use prepared statements for filters and escape every displayed value.

## Limits
- Existing exports cannot be reconstructed from the database.
- Shared login and IP do not prove which individual used the browser. Direct SQL clients bypass application-level audit.
- A completed entry means the server finished generating the response. It cannot prove the browser saved the file.
