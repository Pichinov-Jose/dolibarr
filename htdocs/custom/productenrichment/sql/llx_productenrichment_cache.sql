-- Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

-- Enrichment results by EAN (UPCitemdb, eBay, AI), shared by every screen of the module
CREATE TABLE llx_productenrichment_cache
(
  rowid       integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity      integer DEFAULT 1 NOT NULL,
  ean         varchar(32) NOT NULL,
  info        mediumtext,
  sources     varchar(32),
  datec       datetime,
  tms         timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
