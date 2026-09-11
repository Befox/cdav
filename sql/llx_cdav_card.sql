-- Protocol metadata only; native objects remain the source of business data.
CREATE TABLE IF NOT EXISTS llx_cdav_card (
 rowid integer AUTO_INCREMENT PRIMARY KEY,
 entity integer NOT NULL DEFAULT 1,
 kind char(2) NOT NULL,
 fk_object integer NOT NULL,
 uri varbinary(255) NOT NULL,
 uid varchar(255) NOT NULL,
 UNIQUE KEY uk_cdav_uri (entity, kind, uri),
 UNIQUE KEY uk_cdav_object (entity, kind, fk_object)
) ENGINE=innodb;
