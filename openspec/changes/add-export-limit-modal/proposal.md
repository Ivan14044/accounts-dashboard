# Change: Add custom count limit for data export

## Why
Currently, users can only export either specific selected rows or all rows matching the current filter. This is inflexible when a user wants to export an exact amount (e.g., exactly 50 or 300 accounts) without manually selecting them. Adding a count input provides better flexibility and improves the UX.

## What Changes
- Add a modal for export settings. This modal will replace the direct action of the CSV/TXT toolbar buttons if nothing is selected or replace them with a unified "Export" button.
- The modal allows choosing the format (CSV or TXT).
- The modal allows choosing the export scope: "All by filter", "Selected", or "Custom count".
- Update the export processor (`export.php`) to accept a `limit` parameter to restrict the number of exported items.

## Impact
- Affected specs: `data-export`
- Affected code:
  - `templates/partials/dashboard/toolbar.php` / New modal in `templates/partials/dashboard/modals/`
  - `assets/js/modules/dashboard-inline.js` or `dashboard-init.js`
  - `export.php`
