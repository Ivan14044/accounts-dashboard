## 1. Implement Export Modal HTML
- [ ] 1.1 Add the new `#exportSettingsModal` HTML structure to the layout (either via a new file `export-modal.php` in modals or modifying `toolbar.php`).
- [ ] 1.2 Update the toolbar export buttons. It's better to unify export behind a single "Export" button, or update existing SVG/Text buttons to trigger the modal.

## 2. Implement Frontend Logic
- [ ] 2.1 Add JavaScript logic to handle the display of the modal.
- [ ] 2.2 Disable the "Selected" radio option if 0 items are selected.
- [ ] 2.3 Construct the correct form parameters (`select=all`, `format`, `limit`, etc.) and submit them accurately using the existing hidden form injection method in JavaScript.

## 3. Implement Backend Logic
- [ ] 3.1 In `export.php`, retrieve and validate the `limit` GET parameter.
- [ ] 3.2 Ensure the `limit` falls within valid boundaries (positive integer).
- [ ] 3.3 Apply `$totalRows = min($totalRows, (int)$limit)` before the stream loops to enforce the constraint.
