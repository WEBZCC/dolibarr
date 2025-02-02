-- ============================================================================
-- Copyright (C) 2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--
-- ===========================================================================

--
-- Table to store each different IP / devices / puclib key used for login
--
create table llx_user_ipdevices
(
  rowid         integer PRIMARY KEY,
  fk_user       integer NOT NULL,		-- ID llx_user
  ip            varchar(64),
  user_agent    varchar(255) NULL,      -- user agent
)ENGINE=innodb;
