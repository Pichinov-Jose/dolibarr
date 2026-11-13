-- Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
ALTER TABLE llx_productenrichment_job ADD INDEX idx_productenrichment_job_status (status, entity);
ALTER TABLE llx_productenrichment_job ADD INDEX idx_productenrichment_job_product (fk_product);
