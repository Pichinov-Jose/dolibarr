-- Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.

ALTER TABLE llx_cardfields_survey ADD UNIQUE INDEX uk_cardfields_survey(entity, objectkey, mode, fieldkey);
