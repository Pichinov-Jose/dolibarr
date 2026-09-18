-- Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
-- GPL v3+
ALTER TABLE llx_giftvoucher ADD UNIQUE INDEX uk_giftvoucher_ref (ref, entity);
ALTER TABLE llx_giftvoucher ADD INDEX idx_giftvoucher_status (status);
ALTER TABLE llx_giftvoucher ADD INDEX idx_giftvoucher_validite (date_validite);
