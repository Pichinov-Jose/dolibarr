-- Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.

-- Queue of the mass enrichment and journal of every enrichment applied to a product
-- status: pending, done, notfound, noean, error
-- origin: mass (product list), card (product card), form (creation form)
CREATE TABLE llx_productenrichment_job
(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity          integer DEFAULT 1 NOT NULL,
  fk_product      integer NOT NULL,
  ean             varchar(32),
  origin          varchar(16) DEFAULT 'mass' NOT NULL,
  status          varchar(16) DEFAULT 'pending' NOT NULL,
  options         text,
  applied         text,
  message         text,
  datec           datetime,
  date_done       datetime,
  fk_user_creat   integer,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
