ALTER TABLE payments
    ADD COLUMN pix_qrcode MEDIUMTEXT NULL AFTER pix_payload;
