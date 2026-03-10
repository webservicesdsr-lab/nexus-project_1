# CSV Uploader KNX — Canonical Implementation Plan

> **Date**: 7 de marzo de 2026  
> **Branch**: `updates-march`  
> **Status**: Planning — No code written yet  
> **Rule**: Database needs NO changes. Collaborator folder is REFERENCE ONLY — never touch.

---

## 1. Objective

Upgrade the existing `api-upload-hub-items-csv.php` to support **two CSV formats**:

| Format | Columns | Source | Modifiers? |
|--------|---------|--------|------------|
| **Simple (current)** | 6 cols: `name, price, category_id/category_name, description, status, image_url` | Manual / admin | ❌ No |
| **Scraper (new)** | 13 cols: `vendor_slug, product_id, product_name, base_price, description, product_cat, image_url, addon_group, addon_type, addon_min, addon_max, addon_option_label, addon_option_price` | Python scraper | ✅ Yes → maps to `knx_item_modifiers` + `knx_modifier_options` |

The system auto-detects the format by reading the CSV header row. Both formats go through the **same endpoint**, same nonce, same permissions.

Additionally: add a `conflict_mode` parameter (`skip` | `update`) so re-uploads can either skip duplicates (current behavior) or update existing items + replace their modifiers.

---

## 2. Bugs to Fix in Current Code

| Bug | Location | Current | Fix |
|-----|----------|---------|-----|
| **Status enum mismatch** | `api-upload-hub-items-csv.php` line 173 | Accepts `available`/`inactive`, but `knx_hub_items.status` enum is `active`/`inactive` | Map `available` → `active`; default to `active` |
| **Price rejects $0** | `api-upload-hub-items-csv.php` line 113 | `$price <= 0` skips the row | Change to `$price < 0` (allow $0.00 for quote-based items) |

---

## 3. Files Involved

### 3.1 Files to EDIT (main repo)

| File | Path | Purpose |
|------|------|---------|
| **CSV API** | `inc/core/resources/knx-items/api-upload-hub-items-csv.php` (196 lines) | The single file receiving all CSV logic upgrades |
| **JS upload flow** | `inc/modules/items/edit-hub-items.js` (lines 324–403) | Add `conflict_mode` to FormData + display summary |
| **Upload modal UI** | `inc/modules/items/edit-hub-items.php` | Add conflict-mode radio selector in `#knxUploadCsvModal` |

### 3.2 Files to READ as PATTERN REFERENCE (main repo — do NOT edit)

| File | Path | What to copy |
|------|------|-------------|
| **Modifier insert** | `inc/core/resources/knx-items/api-modifiers.php` lines 137–158 | NULL handling for `item_id` and `max_selection`; `$fmt` array pattern |
| **Option insert** | `inc/core/resources/knx-items/api-modifiers.php` lines 293–305 | `modifier_options` INSERT with `price_adjustment`, `sort_order` auto-increment |
| **Menu read** | `inc/core/resources/knx-items/api-menu-read.php` lines 150–213 | How modifiers + options are read back (verification after import) |

### 3.3 Files loaded by `kingdom-nexus.php` (context)

```
Line 218: knx_require('inc/core/resources/knx-items/api-upload-hub-items-csv.php');
Line 219: knx_require('inc/core/resources/knx-items/api-hub-items.php');
Line 220: knx_require('inc/core/resources/knx-items/api-modifiers.php');
Line 221: knx_require('inc/core/resources/knx-items/api-item-addons.php');
Line 222: knx_require('inc/core/resources/knx-items/api-menu-read.php');
```

### 3.4 Collaborator files (REFERENCE ONLY — never touch)

| File | Path | Value |
|------|------|-------|
| `class-csv-importer.php` | `colaborator-version/inc/modules/import/class-csv-importer.php` (889 lines) | Grouping philosophy, error handling patterns |
| `api-import-items.php` | `colaborator-version/inc/core/resources/knx-hubs/api-import-items.php` (365 lines) | Route + validation approach |

---

## 4. Database Tables & Columns (Canonical Schema)

> Source: `nexus-schema-y05.sql` — the ONLY schema authority.  
> Prefix in production: `y05_` (in PHP: `$wpdb->prefix . 'knx_...'`)

### 4.1 Tables the CSV uploader WRITES to

#### `knx_hub_items`
```
id              bigint UNSIGNED  PK AUTO_INCREMENT
hub_id          bigint UNSIGNED  NOT NULL  FK→knx_hubs
category_id     bigint UNSIGNED  DEFAULT NULL  FK→knx_items_categories (ON DELETE SET NULL)
name            varchar(191)     NOT NULL
description     text
price           decimal(10,2)    NOT NULL DEFAULT '0.00'
image_url       varchar(500)     DEFAULT NULL
status          enum('active','inactive')  DEFAULT 'active'
sort_order      int UNSIGNED     DEFAULT '0'
created_at      timestamp        DEFAULT CURRENT_TIMESTAMP
updated_at      timestamp        ON UPDATE CURRENT_TIMESTAMP
```

#### `knx_items_categories`
```
id              bigint UNSIGNED  PK AUTO_INCREMENT
hub_id          bigint UNSIGNED  NOT NULL  FK→knx_hubs
name            varchar(191)     NOT NULL
sort_order      int UNSIGNED     DEFAULT '0'
status          enum('active','inactive')  DEFAULT 'active'
created_at      timestamp        DEFAULT CURRENT_TIMESTAMP
updated_at      timestamp        ON UPDATE CURRENT_TIMESTAMP
```

#### `knx_item_modifiers`
```
id              bigint UNSIGNED  PK AUTO_INCREMENT
item_id         bigint UNSIGNED  DEFAULT NULL  FK→knx_hub_items (NULL = global)
hub_id          bigint UNSIGNED  NOT NULL  FK→knx_hubs
name            varchar(191)     NOT NULL
type            varchar(20)      DEFAULT 'single'   ('single' | 'multiple')
required        tinyint(1)       DEFAULT '0'
min_selection   int UNSIGNED     DEFAULT '0'
max_selection   int UNSIGNED     DEFAULT NULL  (NULL = unlimited)
is_global       tinyint(1)       DEFAULT '0'
sort_order      int UNSIGNED     DEFAULT '0'
created_at      timestamp        DEFAULT CURRENT_TIMESTAMP
updated_at      timestamp        ON UPDATE CURRENT_TIMESTAMP
```

#### `knx_modifier_options`
```
id              bigint UNSIGNED  PK AUTO_INCREMENT
modifier_id     bigint UNSIGNED  NOT NULL  FK→knx_item_modifiers (ON DELETE CASCADE)
name            varchar(191)     NOT NULL
price_adjustment decimal(10,2)   DEFAULT '0.00'
is_default      tinyint(1)       DEFAULT '0'
sort_order      int UNSIGNED     DEFAULT '0'
created_at      timestamp        DEFAULT CURRENT_TIMESTAMP
updated_at      timestamp        ON UPDATE CURRENT_TIMESTAMP
```

#### `knx_item_global_modifiers`
```
id                  bigint UNSIGNED  PK AUTO_INCREMENT
item_id             bigint UNSIGNED  NOT NULL  FK→knx_hub_items (ON DELETE CASCADE)
global_modifier_id  bigint UNSIGNED  NOT NULL  FK→knx_item_modifiers (ON DELETE CASCADE)
created_at          timestamp        DEFAULT CURRENT_TIMESTAMP
UNIQUE KEY uk_item_global (item_id, global_modifier_id)
```

**Role**: Junction/tracking table. When a global modifier (`is_global=1`) is **cloned** to a specific item via `knx_api_clone_global_modifier()` in `api-modifiers.php` (lines 396–490), it:
1. Creates a new per-item modifier (`is_global=0`) copying the global's name/type/options
2. Copies all `knx_modifier_options` rows to the new modifier
3. Inserts a row into `knx_item_global_modifiers` linking `item_id` → `global_modifier_id` to prevent duplicate clones

**CSV uploader interaction**: The CSV uploader does **NOT** write to this table because all imported modifiers are per-item (`is_global=0`, `item_id=actual_id`). However, if an item already has a cloned global modifier and the CSV uses `conflict_mode=update`, the `DELETE FROM knx_item_modifiers WHERE item_id=%d` will CASCADE-delete the related `knx_item_global_modifiers` row — which is correct behavior (the re-import replaces everything).

### 4.2 Tables the CSV uploader READS (for lookups)

| Table | Purpose |
|-------|---------|
| `knx_items_categories` | Resolve `category_name` → `category_id`, or verify `category_id` exists for hub |
| `knx_hub_items` | Duplicate check by `name` + `hub_id` |
| `knx_item_global_modifiers` | **Indirectly affected** — CASCADE-deleted when `conflict_mode=update` removes an item's modifiers via `DELETE FROM knx_item_modifiers WHERE item_id=%d` |

### 4.3 Tables NOT used by this uploader (addon tables — legacy/orphan)

| Table | Status in Schema | Status in Code | Verdict |
|-------|-----------------|----------------|---------|
| `knx_addon_groups` | **NO CREATE TABLE** in canonical schema | Referenced by `api-item-addons.php` (3 routes) + `api-menu-read.php` (lines 82, 230–270) | **IGNORE** — do not use |
| `knx_addons` | **Orphaned fragment** (lines 231–244, no CREATE TABLE header) | Referenced by same 2 files above | **IGNORE** — do not use |
| `knx_item_addon_groups` | Has CREATE TABLE (line 334) but FK points to non-existent `knx_addon_groups` | Junction table used by same 2 files | **IGNORE** — do not use |

---

## 5. Addon Tables — Future Removal Impact Analysis

If these 3 tables are dropped in the future, **exactly 2 main-repo PHP files break**:

### 5.1 `api-item-addons.php` — FULL RETIREMENT

| Route | Function | Impact |
|-------|----------|--------|
| `GET /knx/v1/get-item-addon-groups` | `knx_api_get_item_addon_groups()` | Queries all 3 addon tables — fails |
| `POST /knx/v1/assign-addon-group-to-item` | `knx_api_assign_addon_group_to_item()` | Inserts into `knx_item_addon_groups` — fails |
| `POST /knx/v1/remove-addon-group-from-item` | `knx_api_remove_addon_group_from_item()` | Deletes from `knx_item_addon_groups` — fails |

**Fix**: Delete entire file (145 lines) + remove `knx_require` at `kingdom-nexus.php` line 221.

### 5.2 `api-menu-read.php` — SURGICAL REMOVAL

| Lines | What it does | Impact |
|-------|-------------|--------|
| 81–83 | Declares `$t_group_map`, `$t_groups`, `$t_addons` table variables | Dead references |
| 145 | Initializes `$r['addon_groups'] = []` per item | Unused key |
| 214–278 | Queries `knx_item_addon_groups` → `knx_addon_groups` → `knx_addons`, attaches `addon_groups[]` to each item | **SQL failures** |
| 338 | Includes `'addon_groups' => $r['addon_groups']` in response | Sends empty arrays (harmless but dead weight) |

**Fix**: Remove lines 81–83, 145, 214–278, and the `addon_groups` key from line 338. The `modifiers[]` section (lines 150–213) stays intact.

### 5.3 What is NOT affected

| Area | Files Checked | Addon References |
|------|--------------|-----------------|
| Cart system | `inc/core/cart/**` | **Zero** |
| Orders system | `inc/core/knx-orders/**` | **Zero** |
| All functions | `inc/functions/**` | **Zero** |
| Public menu frontend | `inc/public/menu/menu-script.js`, `menu-shortcode.php` | **Zero** |
| Item editor UI | `inc/modules/items/**` | **Zero** |
| Modifiers API | `api-modifiers.php` | **Zero** — uses only `knx_item_modifiers` + `knx_modifier_options` |
| Item CRUD APIs | `api-hub-items.php`, `api-update-item.php` | **Zero** |

### 5.4 Schema cleanup needed

| Action | File | Lines |
|--------|------|-------|
| Remove `CREATE TABLE y05_knx_item_addon_groups` | `nexus-schema-y05.sql` | ~334–345 |
| Remove orphaned `knx_addons` column fragment | `nexus-schema-y05.sql` | ~231–244 |
| Remove `DROP TABLE IF EXISTS y05_knx_item_addon_groups` | `nexus-schema-y05.sql` | ~299 |

**Verdict**: Dropping is **safe and low-risk** — 2 files, ~80 lines of code to remove, zero downstream consumers in cart/orders/functions/frontend.

---

## 6. Scraper CSV → Modifier Tables Mapping

The Python scraper produces a **flat-row format** where each row represents one addon option. Items repeat across multiple rows.

### 6.1 CSV Column → DB Column Mapping

| Scraper CSV Column | → DB Table | → DB Column | Notes |
|-------------------|-----------|-------------|-------|
| `product_name` | `knx_hub_items` | `name` | Sanitize with `sanitize_text_field()` |
| `base_price` | `knx_hub_items` | `price` | `floatval()`, allow $0.00 |
| `description` | `knx_hub_items` | `description` | `sanitize_textarea_field()` |
| `product_cat` | `knx_items_categories` | `name` | Resolve or auto-create (existing logic) |
| `image_url` | `knx_hub_items` | `image_url` | `esc_url_raw()`, store as-is |
| `addon_group` | `knx_item_modifiers` | `name` | One modifier per unique `addon_group` per item |
| `addon_type` | `knx_item_modifiers` | `type` | Map: `single` → `single`, `multiple` → `multiple` |
| `addon_min` | `knx_item_modifiers` | `min_selection` | `intval()`, default 0 |
| `addon_max` | `knx_item_modifiers` | `max_selection` | `intval()`, NULL if empty/0 |
| `addon_option_label` | `knx_modifier_options` | `name` | `sanitize_text_field()` |
| `addon_option_price` | `knx_modifier_options` | `price_adjustment` | `floatval()`, default 0.00 |
| `vendor_slug` | — | — | Not stored; informational only (identifies source hub) |
| `product_id` | — | — | **Grouping key** — all rows with same `product_id` = same item |

### 6.2 Grouping Logic

```
CSV rows with same `product_id`:
  → 1 INSERT into knx_hub_items
  → N INSERTs into knx_item_modifiers (one per unique addon_group)
      → M INSERTs into knx_modifier_options (one per addon_option_label within that group)
```

Example: If `product_id=42` has 3 rows with `addon_group="Size"` and 2 rows with `addon_group="Toppings"`:
- 1 item inserted
- 2 modifiers inserted ("Size" with 3 options, "Toppings" with 2 options)
- 5 modifier options inserted total

### 6.3 Modifier Insert Rules

All imported modifiers are **per-item** (not global):
- `item_id` = the newly inserted item's ID
- `is_global` = `0`
- `hub_id` = the hub_id from the upload request
- `required` = `1` if `addon_min >= 1`, else `0`
- `sort_order` = auto-increment per item

### 6.4 NULL Handling Pattern (from `api-modifiers.php` lines 153–158)

```php
// For item_id: NULL when global, actual ID when per-item
if (is_null($ins['item_id'])) { $fmt[0] = '%s'; }

// For max_selection: NULL means unlimited
if (is_null($ins['max_selection'])) { $fmt[6] = '%s'; }
```

This pattern MUST be replicated exactly in the CSV uploader.

---

## 7. Auth & Security Pattern

Existing pattern from `api-upload-hub-items-csv.php` — **do not change**:

```php
register_rest_route('knx/v1', '/upload-hub-items-csv', [
    'methods'  => 'POST',
    'callback' => knx_rest_wrap('knx_api_upload_hub_items_csv'),
    'permission_callback' => knx_rest_permission_roles([
        'super_admin', 'manager', 'hub_management', 'menu_uploader'
    ]),
]);

// Inside the callback:
$nonce = sanitize_text_field($r->get_param('knx_nonce'));
if (!wp_verify_nonce($nonce, 'knx_edit_hub_nonce')) { ... }
```

---

## 8. Phased Implementation

### Phase 1 — Smart Header Detection + Bug Fixes

**File**: `inc/core/resources/knx-items/api-upload-hub-items-csv.php`

**Changes**:
1. After reading the header row, detect format by checking if `product_id` column exists
2. Fix status enum: map `available` → `active`; reject anything not in `['active','inactive']`; default to `active`
3. Fix price: change `$price <= 0` to `$price < 0`
4. Add `conflict_mode` parameter: `$r->get_param('conflict_mode')` → `'skip'` (default) or `'update'`
5. If format is simple (6-col), continue with existing logic (with bug fixes applied)
6. If format is scraper (13-col), proceed to Phase 2 logic

**Smoke Test**:
- Upload a simple 6-col CSV with a $0.00 item → should insert (not reject)
- Upload a simple CSV with `status=available` → item should be stored as `active`
- Upload a simple CSV with duplicate names → should skip with `conflict_mode=skip`

---

### Phase 2 — Scraper Row Grouping

**File**: `inc/core/resources/knx-items/api-upload-hub-items-csv.php`

**Changes**:
1. When scraper format detected: read ALL rows into memory first (don't process line-by-line)
2. Group rows by `product_id`:
   ```php
   $grouped = []; // key = product_id
   while (($row = fgetcsv($handle)) !== false) {
       $data = array_combine($cols, $row);
       $pid = trim($data['product_id']);
       if (!isset($grouped[$pid])) {
           $grouped[$pid] = [
               'item' => $data,    // first row = item data
               'options' => []     // all rows = addon options
           ];
       }
       $grouped[$pid]['options'][] = $data;
   }
   ```
3. For each group: extract item fields from first row, collect all addon options

**Smoke Test**:
- Upload a 13-col CSV with 3 products (product_id: A, B, C), each with 2-4 addon rows
- API returns `processed: 3` (not the raw row count)
- Verify grouping: product A has correct number of options collected

---

### Phase 3 — Modifier Insertion

**File**: `inc/core/resources/knx-items/api-upload-hub-items-csv.php`

**Changes**:
1. After inserting each item, collect unique `addon_group` values from its option rows
2. For each unique `addon_group`, INSERT into `knx_item_modifiers`:
   ```php
   $ins = [
       'item_id'       => $item_id,
       'hub_id'        => $hub_id,
       'name'          => sanitize_text_field($addon_group),
       'type'          => $addon_type,        // 'single' or 'multiple'
       'required'      => ($addon_min >= 1) ? 1 : 0,
       'min_selection' => intval($addon_min),
       'max_selection' => $addon_max > 0 ? intval($addon_max) : null,
       'is_global'     => 0,
       'sort_order'    => $mod_sort++,
       'created_at'    => current_time('mysql'),
       'updated_at'    => current_time('mysql'),
   ];
   $fmt = ['%d','%d','%s','%s','%d','%d','%d','%d','%d','%s','%s'];
   if (is_null($ins['max_selection'])) { $fmt[6] = '%s'; }
   ```
3. For each option row within that group, INSERT into `knx_modifier_options`:
   ```php
   $wpdb->insert($t_mod_opts, [
       'modifier_id'      => $modifier_id,
       'name'             => sanitize_text_field($option_label),
       'price_adjustment' => floatval($option_price),
       'is_default'       => 0,
       'sort_order'       => $opt_sort++,
       'created_at'       => current_time('mysql'),
       'updated_at'       => current_time('mysql'),
   ], ['%d','%s','%f','%d','%d','%s','%s']);
   ```

**Smoke Test**:
- Upload scraper CSV → verify `knx_item_modifiers` rows created with correct `item_id`, `name`, `type`, `min_selection`, `max_selection`
- Verify `knx_modifier_options` rows created with correct `modifier_id`, `name`, `price_adjustment`
- Call `GET /knx/v1/read-hub-menu?hub_id=X` → items should include `modifiers[]` with nested `options[]`
- Verify sort_order is sequential per modifier and per option

---

### Phase 4 — Conflict Mode (skip / update)

**File**: `inc/core/resources/knx-items/api-upload-hub-items-csv.php`

**Changes**:
1. Accept `conflict_mode` param: `skip` (default) or `update`
2. For **both** CSV formats, when duplicate detected by `name` + `hub_id`:
   - `skip` → current behavior: skip row, add to errors array
   - `update` → `$wpdb->update()` the existing item's `price`, `description`, `image_url`, `status`, `category_id`
3. For scraper format in `update` mode:
   - Delete existing modifiers for that item: `DELETE FROM knx_item_modifiers WHERE item_id = %d`
     - CASCADE chain: `knx_modifier_options` rows deleted (FK `modifier_id` ON DELETE CASCADE)
     - CASCADE chain: `knx_item_global_modifiers` rows deleted (FK `global_modifier_id` ON DELETE CASCADE)
   - Re-insert modifiers + options from CSV data
   - **Note**: This intentionally breaks the clone-tracking link. After a CSV update, the item's modifiers are owned by the CSV, not by any global modifier. This is correct behavior.
4. Track `$updated` count separately in summary

**Response shape updated**:
```json
{
  "success": true,
  "message": "CSV processed",
  "data": {
    "processed": 50,
    "inserted": 30,
    "updated": 15,
    "skipped": 3,
    "errors": [...]
  }
}
```

**Smoke Test**:
- Upload CSV once → all items inserted
- Upload same CSV with `conflict_mode=skip` → all items skipped, `inserted: 0, skipped: N`
- Upload same CSV with `conflict_mode=update` → all items updated, `updated: N, inserted: 0`
- After update: verify modifiers were replaced (not duplicated)

---

### Phase 5 — UI Updates

**Files**: `inc/modules/items/edit-hub-items.php` + `inc/modules/items/edit-hub-items.js`

**Changes in `edit-hub-items.php`** (inside `#knxUploadCsvModal`):
1. Add radio buttons for conflict mode:
   ```html
   <div class="knx-csv-conflict-mode">
     <label><input type="radio" name="conflict_mode" value="skip" checked> Skip duplicates</label>
     <label><input type="radio" name="conflict_mode" value="update"> Update existing items</label>
   </div>
   ```

**Changes in `edit-hub-items.js`** (CSV upload flow, lines ~370+):
1. Read selected conflict mode and append to FormData:
   ```javascript
   const conflictMode = uploadCsvForm.querySelector('input[name="conflict_mode"]:checked')?.value || 'skip';
   fd.append("conflict_mode", conflictMode);
   ```
2. Display summary after upload:
   ```javascript
   if (data.success && data.data) {
     const d = data.data;
     knxToast(`Inserted: ${d.inserted}, Updated: ${d.updated || 0}, Skipped: ${d.skipped}`, "success");
   }
   ```

**Smoke Test**:
- Open item editor → click "Upload CSV" → modal shows conflict mode radios
- Select "Update existing items" → upload CSV → FormData includes `conflict_mode=update`
- After upload → toast shows `Inserted: X, Updated: Y, Skipped: Z`

---

## 9. Complete Smoke Test Checklist

| # | Test | Format | conflict_mode | Expected |
|---|------|--------|---------------|----------|
| 1 | Upload simple CSV, new items | Simple (6-col) | skip | All inserted, `status=active` |
| 2 | Upload simple CSV, $0.00 item | Simple (6-col) | skip | Item inserted (not rejected) |
| 3 | Upload simple CSV, duplicates | Simple (6-col) | skip | Duplicates skipped |
| 4 | Upload simple CSV, duplicates | Simple (6-col) | update | Existing items updated |
| 5 | Upload scraper CSV, new items | Scraper (13-col) | skip | Items + modifiers + options inserted |
| 6 | Upload scraper CSV, duplicates | Scraper (13-col) | skip | Duplicates skipped |
| 7 | Upload scraper CSV, duplicates | Scraper (13-col) | update | Items updated, modifiers replaced |
| 8 | Verify menu API after scraper import | — | — | `GET /read-hub-menu` returns items with `modifiers[].options[]` |
| 9 | Upload invalid CSV (bad headers) | — | — | Error: `invalid_csv_format` |
| 10 | Upload empty CSV | — | — | Error: `empty_csv` |
| 11 | Upload CSV > 10MB | — | — | Error: `file_too_large` |
| 12 | Upload without nonce | — | — | 403 `invalid_nonce` |
| 13 | Category auto-creation | Simple or Scraper | skip | New category created, item linked |
| 14 | UI: conflict mode radio | — | — | Radio buttons visible, value sent in FormData |
| 15 | UI: summary toast | — | — | Toast shows inserted/updated/skipped counts |
| 16 | Global modifier cascade on update | Scraper (13-col) | update | Item with previously cloned global modifier: after update, `knx_item_global_modifiers` row is gone, new modifiers from CSV are present |

---

## 10. Order Snapshot Safety

> **Question**: Does updating/deleting `knx_item_modifiers` rows via `conflict_mode=update` break existing orders?

**Answer: No. Existing orders are fully immune.** The checkout system uses two frozen JSON snapshots that are written at order time and never re-read from the modifier tables:

| Field | Table | Written at | Read from modifiers? |
|-------|-------|-----------|----------------------|
| `modifiers_json` | `knx_cart_items` | Cart add (frontend) | ❌ Stored as JSON blob |
| `modifiers_json` | `knx_order_items` | Order creation (line 777) | ❌ Copied from cart JSON |
| `cart_snapshot` (JSON) | `knx_orders` | Order creation (line 755) | ❌ Built from cart items |

### The complete flow

```
1. Customer adds item to cart
   → Modifiers selected by user saved as JSON in knx_cart_items.modifiers_json
   → No FK to knx_item_modifiers — already decoupled

2. Checkout / create order  (api-create-order-mvp.php)
   → Reads knx_cart_items.modifiers_json  (the JSON blob, not the modifier tables)
   → Copies to knx_order_items.modifiers_json  (immutable from this point)
   → Embeds in knx_orders.cart_snapshot  (immutable JSON, version: 'v5')

3. CSV update (conflict_mode=update)
   → DELETE FROM knx_item_modifiers WHERE item_id = %d
   → CASCADE deletes knx_modifier_options + knx_item_global_modifiers
   → Re-inserts modifiers + options from CSV
   → Does NOT touch: knx_cart_items, knx_order_items, knx_orders
   → Existing orders read their own frozen JSON — unaffected

4. api-get-order.php reads knx_order_items.modifiers_json (line 213–225)
   → The JSON is the snapshot, not a live JOIN to modifier tables
```

### What the CSV update DOES affect

- **Active carts** — A customer who has this item in cart RIGHT NOW will still see the old modifier selections (from their cart JSON). The menu API will return new modifiers, but the cart is not refreshed automatically. This is normal cart behavior, not a bug introduced by the CSV importer.
- **Future orders** — Any order placed after the CSV update will use the new modifiers from the menu API.

---

## 11. What We Are NOT Doing

| Decision | Reason |
|----------|--------|
| NOT using `knx_addon_groups`, `knx_addons`, `knx_item_addon_groups` | No CREATE TABLE for 2 of 3 in canonical schema; orphaned system |
| NOT creating new DB tables | User confirmed: "I AM SURE THAT THE CURRENT DATABASE DOESN'T NEED ANY CHANGE" |
| NOT creating new helper functions | Per `READ-THIS-BEFORE-MAKING-ANY-CHANGE.md`: "No new helpers" |
| NOT touching collaborator folder | User directive: "DON'T TOUCH ANYTHING FROM THEIR FOLDER" |
| NOT creating global modifiers from CSV | All CSV imports are per-item (`is_global = 0`) |
| NOT sideloading images | Store `image_url` as-is; media library sideload is a future enhancement |
| NOT adding batch transactions | Per-item error isolation is preferred over global rollback |
| NOT modifying the REST route path | Same endpoint: `POST /wp-json/knx/v1/upload-hub-items-csv` |
| NOT changing permission_callback | Same roles: `super_admin, manager, hub_management, menu_uploader` |
| NOT changing the nonce | Same: `knx_edit_hub_nonce` |
