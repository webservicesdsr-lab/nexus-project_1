DELETE FROM y05_knx_users
WHERE role = 'customer';

ALTER TABLE y05_knx_users AUTO_INCREMENT = 2;
