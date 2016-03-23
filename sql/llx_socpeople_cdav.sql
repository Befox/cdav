-- ============================================================================
-- Copyright (C) 2015 Jérôme ANDANSON
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
-- along with this program. If not, see <http://www.gnu.org/licenses/>.
--
-- Table of "actioncomm_cdav" for cdav module
-- ============================================================================

CREATE TABLE IF NOT EXISTS `llx_socpeople_cdav` (
  `fk_object` int(11) NOT NULL,
  `uuidext` varchar(255) NOT NULL,
  PRIMARY KEY (`fk_object`),
  KEY `uuidext` (`uuidext`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Storage of external UUID created by externals applications';
