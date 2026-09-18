-- Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
-- GPL v3+
CREATE TABLE llx_giftvoucher (
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer DEFAULT 1 NOT NULL,
  ref varchar(64) NOT NULL,
  amount double(24,8) DEFAULT 0 NOT NULL,
  type_voucher varchar(16) DEFAULT 'gift' NOT NULL,
  date_emission date NULL,
  date_validite date NULL,
  date_exerce datetime NULL,
  status integer DEFAULT 1 NOT NULL,
  fk_soc integer NULL,
  fk_facture_emission integer NULL,
  fk_facture integer NULL,
  fk_paiement integer NULL,
  note_public text NULL,
  date_creation datetime NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_creat integer NULL,
  fk_user_exerce integer NULL,
  import_key varchar(14) NULL
) ENGINE=innodb;
