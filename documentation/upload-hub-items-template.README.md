CSV template for Upload Hub Items (Kingdom Nexus)

Location:
- `inc/core/resources/knx-items/upload-hub-items-template.csv`

Purpose:
- Blank CSV template ready to fill for use with the "Upload CSV" flow on the Edit Hub Items page.
- The upload endpoint expects `hub_id` and `knx_nonce` to be provided by the UI (the template does NOT include hub_id).

Columns (header row must remain exactly as below, case-insensitive):
- `name` (required): Item name (string). Must be unique within the hub to avoid being skipped.
- `description` (optional): Long or short description (string).
- `price` (required): Decimal value, use dot as decimal separator (e.g. `8.50`).
- `category_id` (optional): Numeric category id (preferred if known).
- `category_name` (optional): If `category_id` is empty, provide a category name. If category does not exist it will be created automatically.
- `status` (optional): `available` or `inactive` (defaults to `available`).
- `image_url` (leave empty): For this deployment images will be uploaded manually via the item editor — leave this column blank.

Notes and recommendations:
- File encoding: UTF-8 (without BOM).
- Line endings: LF.
- Do not include additional columns. Columns order is flexible if you map by header, but keep header names.
- Avoid duplicate `name` values for items in the same hub — duplicates are skipped by default.
- Maximum CSV upload size: 10 MB (server-side limit currently enforced).

Example header (already present in the CSV):
```
name,description,price,category_id,category_name,status,image_url
```

When ready, upload the CSV from the Edit Hub Items page for the target hub (the UI sends `hub_id` and `knx_nonce`). If you want, I can add an example row (commented) or create a downloadable error-report file after upload processing.