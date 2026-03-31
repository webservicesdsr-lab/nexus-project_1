-- DRIVERS-RESET.SQL
-- Aggressive drivers wipe (NO BACKUPS) — Use only when delivering a clean white-label app.
-- WARNING: This will permanently remove driver profiles and their related mappings
-- (driver hubs, driver cities, availability and driver notifications). It will NOT
-- touch hubs, items, orders (except cascade effects where defined by FK), or users.
-- BEFORE RUNNING: Replace `y05_` with your DB prefix if different (e.g. `wp_`).
-- Run on staging first and ensure you have an external dump if you need to recover.

SET FOREIGN_KEY_CHECKS = 0;

-- 1) Remove driver-related pivot and availability rows
DELETE FROM y05_knx_driver_hubs;
DELETE FROM y05_knx_driver_cities;
DELETE FROM y05_knx_driver_availability;

-- 2) Remove driver notifications (if any) — safe to delete here
DELETE FROM y05_knx_driver_notifications;

-- 3) Remove driver profiles
DELETE FROM y05_knx_drivers;

-- 4) Reset AUTO_INCREMENT counters so new drivers start from 1
ALTER TABLE y05_knx_drivers AUTO_INCREMENT = 1;
ALTER TABLE y05_knx_driver_hubs AUTO_INCREMENT = 1;
ALTER TABLE y05_knx_driver_cities AUTO_INCREMENT = 1;

-- 5) If you also maintain a canonical driver_ops table that references drivers, leave it intact
-- (it typically references orders and user IDs). Clean only if desired:
-- DELETE FROM y05_knx_driver_ops;
-- ALTER TABLE y05_knx_driver_ops AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- Post-checks: quick counts (should be zero for drivers and related mappings)
SELECT COUNT(*) AS drivers_remaining FROM y05_knx_drivers;
SELECT COUNT(*) AS driver_hubs_remaining FROM y05_knx_driver_hubs;
SELECT COUNT(*) AS driver_cities_remaining FROM y05_knx_driver_cities;
SELECT COUNT(*) AS driver_availability_remaining FROM y05_knx_driver_availability;
SELECT COUNT(*) AS driver_notifications_remaining FROM y05_knx_driver_notifications;

-- End of DRIVERS-RESET.SQL
