-- Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
ALTER TABLE llx_productenrichment_cache ADD UNIQUE INDEX uk_productenrichment_cache_ean (ean, entity);
