# 🔍 KNX-AUDIT-01 — Pickup Address Source Tracing

**Date:** 2026-02-04  
**Scope:** Checkout → Snapshot → Driver OPS  
**Status:** ✅ COMPLETE (Read-only investigation)

---

## 📋 EXECUTIVE SUMMARY

**Finding:** Checkout UI successfully displays hub name and address, but the **driver-available-orders endpoint does NOT expose these fields** because it only SELECT scalar columns from `knx_orders` without JOINing `knx_hubs` or parsing snapshots.

**Root Cause:**  
- Checkout shortcode reads **LIVE hub data** directly from `knx_hubs` table (not from snapshot)
- `cart_snapshot` and `totals_snapshot` **DO contain hub_name**, but driver endpoint doesn't parse them
- Driver endpoint SELECT does not JOIN `knx_hubs` table

**Impact:**  
- Driver UI shows "Pickup address unavailable" because `pickup_address_text` is missing
- Frontend renderer is correctly waiting for backend-authoritative fields
- No frontend bug — this is a **backend contract gap**

---

## 🎯 TASK 01 — Checkout Pickup Address Source (CONFIRMED)

### Finding: Checkout uses LIVE hub query (NOT snapshot)

**File:** `inc/public/checkout/checkout-shortcode.php`  
**Lines:** 88-98, 296-308

#### Evidence A: Hub Query (Direct DB Read)

```php
// Lines 88-98
if (!empty($cart->hub_id)) {
    $hub = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, name, address, phone, logo_url
             FROM {$table_hubs}
             WHERE id = %d",
            $cart->hub_id
        )
    );
}
```

#### Evidence B: Header Rendering

```php
// Lines 296-308
<header class="knx-co-hero">
    <h1 class="knx-co-hero__title"><?php echo esc_html($hub->name); ?></h1>
    
    <?php if (!empty($hub->address)): ?>
        <div class="knx-co-hero__location">
            <svg>...</svg>
            <span><?php echo esc_html($hub->address); ?></span>
        </div>
    <?php endif; ?>
</header>
```

### Key Discovery

| Field | Source | Variable | Table Column |
|-------|--------|----------|--------------|
| **Hub Name** | `knx_hubs.name` | `$hub->name` | `name` VARCHAR(191) |
| **Hub Address** | `knx_hubs.address` | `$hub->address` | `address` TEXT |
| **Hub Coords** | `knx_hubs.latitude/longitude` | Not queried | `latitude`/`longitude` DECIMAL |

**Conclusion:** Checkout renders pickup from **LIVE hub table**, not snapshot.

---

## 🎯 TASK 02 — Snapshot Structure Inspection (CONFIRMED)

### Finding: Snapshots DO contain hub_name but NOT full hub address

**File:** `inc/core/knx-orders/api-create-order-mvp.php`  
**Lines:** 807-816

#### Evidence: cart_snapshot Creation

```php
// Lines 807-816
$cart_snapshot = [
    'hub_id'        => $hub_id,
    'hub_name'      => (string) ($hub->name ?? ''),  // ✅ HUB NAME CAPTURED
    'session_token' => (string) $session_token,
    'items'         => $snapshot_items,
    'subtotal'      => $subtotal,
    'item_count'    => $item_count,
    'created_at'    => $now,
];
```

**Missing from cart_snapshot:**
- ❌ `hub_address` (pickup address text)
- ❌ `hub_lat` / `hub_lng` (pickup coordinates)

**File:** `inc/functions/totals-engine.php`  
**Lines:** 440-520

#### Evidence: totals_snapshot Structure

```php
// Lines 440-458
$snapshot = [
    'calculated_at'     => current_time('mysql'),
    'hub_id'            => $hub_id,
    'city_id'           => $city_id,
    'fulfillment_type'  => $fulfillment_type,
    'subtotal'          => $totals['subtotal'],
    'tax_rate'          => $totals['tax_rate'],
    'tax_amount'        => $totals['tax_amount'],
    'delivery_fee'      => $totals['delivery_fee'],
    'software_fee'      => $totals['software_fee'],
    'software_fee_rule' => $software_rule,
    'tip_amount'        => $totals['tip_amount'],
    'total'             => $totals['total'],
];
```

**Delivery Snapshot (for delivery orders only):**

```php
// Lines 460-495 (delivery_snapshot sub-object)
$delivery_snapshot = [
    'distance_km'      => ...,  // KNX-A0.6 canonical distance
    'distance_miles'   => ...,
    'delivery_fee'     => ...,  // KNX-A0.7 fee lock
    'fee_method'       => ...,
    'rate'             => [...],
    'distance'         => [...],  // legacy format
    'eta'              => [...],  // legacy ETA
];
```

**Missing from totals_snapshot:**
- ❌ Hub address text
- ❌ Pickup coordinates
- ✅ `delivery_snapshot` contains distance/fee for **delivery address**, not pickup

**Address Snapshot (Phase 4.2):**

```php
// Lines ~755-765 in api-create-order-mvp.php
$breakdown_v5['address'] = [
    'version'    => 'v1',
    'address_id' => ...,
    'label'      => ...,  // Customer delivery address label
    'lat'        => ...,
    'lng'        => ...,
    'frozen_at'  => ...,
];
```

This is the **customer delivery address** snapshot, not the hub pickup address.

---

## 🎯 TASK 03 — Canonical Mapping Proposal

### Current Reality Table

| Field | Checkout Source | Snapshot Contains? | Driver API Exposes? | Frontend Receives? |
|-------|-----------------|-------------------|---------------------|-------------------|
| **Hub Name** | `knx_hubs.name` (LIVE) | ✅ `cart_snapshot.hub_name` | ❌ Not exposed | ❌ Missing |
| **Hub Address** | `knx_hubs.address` (LIVE) | ❌ Not captured | ❌ Not exposed | ❌ Missing |
| **Hub Coords** | `knx_hubs.latitude/longitude` (LIVE) | ❌ Not captured | ❌ Not exposed | ❌ Missing |
| **Delivery Address** | Customer address snapshot | ✅ `breakdown_v5.address` | ✅ `o.delivery_address` | ✅ Present |

### Root Cause Analysis

#### Why pickup is missing for AVAILABLE orders:

1. **Snapshot Gap:** `cart_snapshot` captures `hub_name` but NOT `hub_address` or coords
2. **Endpoint Gap:** `api-driver-available-orders.php` SELECT does NOT:
   - JOIN `knx_hubs` table
   - Parse `cart_snapshot` JSON
   - Expose `hub_name`, `pickup_address_text`, `pickup_lat/lng`
3. **Frontend Correctly Waiting:** Driver UI expects backend-authoritative `pickup_address_text` — it's NOT broken

#### Why checkout shows it correctly:

- Checkout queries `knx_hubs` **LIVE** on every page load (lines 88-98)
- Checkout does NOT rely on snapshots for header display

---

## 🚀 CANONICAL SOLUTION (Proposal)

### Option A: JOIN knx_hubs in driver endpoint (RECOMMENDED)

**Advantages:**
- ✅ No snapshot changes needed
- ✅ Works for AVAILABLE orders (pre-snapshot)
- ✅ Simple LEFT JOIN in SQL
- ✅ Provides LIVE hub data (current address/coords)

**Implementation:**

**File:** `inc/core/resources/knx-ops/api-driver-available-orders.php`

**Current SELECT (broken):**

```php
$sql = "SELECT
    o.id,
    o.order_number,
    o.hub_id,
    o.delivery_address,
    o.total,
    ...
FROM {$orders_table} o
LEFT JOIN {$driver_ops_table} dop ON dop.order_id = o.id
WHERE ...";
```

**Proposed SELECT (fixed):**

```php
$hubs_table = $wpdb->prefix . 'knx_hubs';

$sql = "SELECT
    o.id,
    o.order_number,
    o.hub_id,
    o.delivery_address,
    o.delivery_address AS delivery_address_text,  -- Canonical alias
    o.total,
    -- HUB INFO (LIVE)
    h.name AS hub_name,
    h.address AS pickup_address_text,
    h.latitude AS pickup_lat,
    h.longitude AS pickup_lng,
    -- ADDRESS SOURCE
    'live' AS address_source,
    ...
FROM {$orders_table} o
LEFT JOIN {$driver_ops_table} dop ON dop.order_id = o.id
LEFT JOIN {$hubs_table} h ON h.id = o.hub_id  -- ← NEW JOIN
WHERE ...";
```

**Expected Response:**

```json
{
  "success": true,
  "data": {
    "orders": [
      {
        "id": "10",
        "order_number": "ORD-F1DD475D3F",
        "hub_id": "1",
        "hub_name": "Corner Pizza",                      // ← NEW
        "pickup_address_text": "55 Main St, Chicago",    // ← NEW
        "pickup_lat": "41.8781",                          // ← NEW
        "pickup_lng": "-87.6298",                         // ← NEW
        "delivery_address": "123 Live St, Suite 1",
        "delivery_address_text": "123 Live St, Suite 1",  // ← Alias
        "address_source": "live",                         // ← NEW
        "total": "73.37",
        ...
      }
    ]
  }
}
```

### Option B: Extend cart_snapshot (NOT RECOMMENDED for AVAILABLE orders)

**Problem:** Available orders happen **BEFORE** cart snapshot is created  
**When to use:** Only for ACCEPTED/IN-PROGRESS orders where snapshot is already locked

---

## ✅ VERIFICATION CHECKLIST

### Backend Changes Required

- [ ] Add `$hubs_table = $wpdb->prefix . 'knx_hubs';` variable
- [ ] Add `LEFT JOIN {$hubs_table} h ON h.id = o.hub_id`
- [ ] Add SELECT columns:
  - [ ] `h.name AS hub_name`
  - [ ] `h.address AS pickup_address_text`
  - [ ] `h.latitude AS pickup_lat`
  - [ ] `h.longitude AS pickup_lng`
- [ ] Add `o.delivery_address AS delivery_address_text` alias
- [ ] Add `'live' AS address_source` literal

### Frontend Validation (No Changes Needed)

- [x] Frontend already expects `pickup_address_text` ✅
- [x] Frontend already expects `delivery_address_text` ✅
- [x] Frontend already has fallback logic for missing fields ✅
- [x] Frontend does NOT parse snapshots ✅

### Test Cases

1. **HTTP Test — Available Order:**
   ```bash
   GET /wp-json/knx/v1/ops/driver-available-orders
   
   Expected response includes:
   - hub_name: "Corner Pizza"
   - pickup_address_text: "55 Main St, Chicago"
   - pickup_lat: 41.8781
   - pickup_lng: -87.6298
   - delivery_address_text: "123 Live St..."
   - address_source: "live"
   ```

2. **UI Test — Driver Card:**
   - Load driver OPS dashboard
   - Card should show: "PICKUP: 55 Main St, Chicago"
   - Card should NOT show: "Pickup address unavailable"

3. **Backward Compatibility:**
   - Existing `delivery_address` field preserved
   - New fields are additive (non-breaking)

---

## 📊 SCHEMA REFERENCE

### knx_hubs Table

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | BIGINT UNSIGNED | NOT NULL | PK |
| `name` | VARCHAR(191) | NOT NULL | Hub name |
| `address` | TEXT | YES | Full address text |
| `latitude` | DECIMAL(10,7) | YES | Coordinates |
| `longitude` | DECIMAL(10,7) | YES | Coordinates |

**Source:** `nexus-schema-y05.sql` lines 319-365

### knx_orders Table (Relevant Columns)

| Column | Type | Notes |
|--------|------|-------|
| `hub_id` | BIGINT UNSIGNED | FK to knx_hubs |
| `delivery_address` | TEXT | Customer address |
| `cart_snapshot` | JSON | Contains `hub_name` only |
| `totals_snapshot` | JSON | Contains delivery distance/fee |

---

## 🔒 SNAPSHOT INTEGRITY NOTES

### Current Snapshot Contract

**cart_snapshot (api-create-order-mvp.php:807-816):**
```json
{
  "hub_id": 1,
  "hub_name": "Corner Pizza",     // ✅ Name captured
  "session_token": "...",
  "items": [...],
  "subtotal": 54.24,
  "item_count": 3,
  "created_at": "2026-02-04 01:25:39"
}
```

**Missing (not captured at order creation):**
- Hub address text
- Hub coordinates

**Recommendation:** DO NOT modify snapshot for available orders. Use LIVE hub JOIN instead.

---

## 🎓 KEY LEARNINGS

1. **Checkout uses LIVE hub data** — not snapshot (intentional for current address display)
2. **cart_snapshot contains hub_name** — but not full address (architectural gap)
3. **Available orders must use LIVE hub JOIN** — snapshots don't exist yet
4. **Frontend is correctly written** — waiting for backend-authoritative fields
5. **Simple LEFT JOIN solves the problem** — no complex snapshot parsing needed

---

## 📝 NEXT STEPS

### Immediate Action (Backend Team)

1. Update `inc/core/resources/knx-ops/api-driver-available-orders.php`:
   - Add `LEFT JOIN knx_hubs h ON h.id = o.hub_id`
   - Add canonical SELECT columns (hub_name, pickup_address_text, pickup_lat/lng)
   - Add `address_source: 'live'` literal

2. Test endpoint response includes all new fields

3. Verify driver UI now shows pickup address correctly

### Future Consideration (Optional)

- Extend `cart_snapshot` to include full hub address for audit/history
- Add snapshot-based pickup for ACCEPTED orders (use snapshot when available)
- Document canonical address source rules in API contract

---

**End of Audit Report**  
**Status:** ✅ Investigation Complete | 🔧 Implementation Pending
