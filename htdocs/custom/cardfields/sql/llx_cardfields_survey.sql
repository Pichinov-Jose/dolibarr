-- Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- Fields found on the cards by the survey of the module Champs par fiche (cardfields)

CREATE TABLE llx_cardfields_survey(
  rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity integer DEFAULT 1 NOT NULL,
  objectkey varchar(32) NOT NULL,
  mode varchar(8) NOT NULL,
  fieldkey varchar(32) NOT NULL,
  label varchar(255),
  data text,
  position integer DEFAULT 0,
  datec datetime,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
