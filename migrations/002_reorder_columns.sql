-- Corrective migration for databases already created from an earlier
-- version of schema.sql, where column order (wrongly derived from ADOX's
-- alphabetically-enumerated Columns collection) didn't match the real
-- Access physical column order. Safe to run on a populated database --
-- MODIFY ... AFTER reorders columns in place without touching data.
-- Confirmed necessary by comparing this server's /prepod output
-- byte-for-byte against the live legacy server on real production data:
-- same values, different JSON key order.
--
-- Run this ONCE against any database created before this fix (including
-- production). A database created fresh from the current schema.sql does
-- not need it.

ALTER TABLE `Client`
  MODIFY `cln_name` VARCHAR(255) NULL AFTER `cln_id`,
  MODIFY `cln_idfox` VARCHAR(255) NULL AFTER `cln_name`,
  MODIFY `cln_phone` VARCHAR(255) NULL AFTER `cln_idfox`,
  MODIFY `cln_lvl` VARCHAR(255) NULL AFTER `cln_phone`,
  MODIFY `cln_info` VARCHAR(255) NULL AFTER `cln_lvl`;

ALTER TABLE `clubs`
  MODIFY `clb_name` VARCHAR(255) NULL AFTER `clb_id`,
  MODIFY `clb_info` VARCHAR(255) NULL AFTER `clb_name`,
  MODIFY `clb_patchindb` VARCHAR(255) NULL AFTER `clb_info`,
  MODIFY `clb_pass` VARCHAR(255) NULL AFTER `clb_patchindb`,
  MODIFY `clb_timeindb` DATETIME NULL AFTER `clb_pass`,
  MODIFY `clb_id1c` VARCHAR(255) NULL AFTER `clb_timeindb`,
  MODIFY `clb_indbf` TINYINT(1) NOT NULL DEFAULT 0 AFTER `clb_id1c`,
  MODIFY `clb_in1с` TINYINT(1) NOT NULL DEFAULT 0 AFTER `clb_indbf`;

ALTER TABLE `dance`
  MODIFY `dns_name` VARCHAR(255) NULL AFTER `dns_id`,
  MODIFY `id_dt` INT NULL AFTER `dns_name`,
  MODIFY `dns_color` VARCHAR(255) NULL AFTER `id_dt`;

ALTER TABLE `dancetype`
  MODIFY `dt_name` VARCHAR(255) NULL AFTER `dt_id`,
  MODIFY `dt_color` VARCHAR(255) NULL AFTER `dt_name`;

ALTER TABLE `DP_blok1_less`
  MODIFY `pb1_idprp` INT NULL AFTER `pb1_id`,
  MODIFY `pb1_idcln` INT NULL AFTER `pb1_idprp`,
  MODIFY `pb1_idlt` INT NULL AFTER `pb1_idcln`,
  MODIFY `pb1_aboutless` TEXT NULL AFTER `pb1_idlt`,
  MODIFY `pb1_recom` VARCHAR(255) NULL AFTER `pb1_aboutless`,
  MODIFY `pb1_plan` VARCHAR(255) NULL AFTER `pb1_recom`,
  MODIFY `pb1_date` DATETIME NULL AFTER `pb1_plan`,
  MODIFY `pb1_datles` DATETIME NULL AFTER `pb1_date`,
  MODIFY `pb1_status` VARCHAR(255) NULL AFTER `pb1_datles`,
  MODIFY `pb1_iddt` INT NULL AFTER `pb1_status`,
  MODIFY `pb1_idsn` INT NULL AFTER `pb1_iddt`,
  MODIFY `pb1_idwrk` INT NULL AFTER `pb1_idsn`,
  MODIFY `pb1_info` TEXT NULL AFTER `pb1_idwrk`,
  MODIFY `pb1_idd` VARCHAR(255) NULL AFTER `pb1_info`;

ALTER TABLE `DP_blok2_figur`
  MODIFY `pb2_idfigur` INT NULL AFTER `pb2_id`,
  MODIFY `pb2_daton` DATETIME NULL AFTER `pb2_idfigur`,
  MODIFY `pb2_idprpon` INT NULL AFTER `pb2_daton`,
  MODIFY `pb2_datoff` DATETIME NULL AFTER `pb2_idprpon`,
  MODIFY `pb2_idprpoff` INT NULL AFTER `pb2_datoff`,
  MODIFY `pb2_idcln` INT NULL AFTER `pb2_idprpoff`;

ALTER TABLE `exclude_1c`
  MODIFY `class_code` VARCHAR(255) NULL AFTER `ID`,
  MODIFY `class_name` VARCHAR(255) NULL AFTER `class_code`,
  MODIFY `room_code` VARCHAR(255) NULL AFTER `class_name`,
  MODIFY `room_name` VARCHAR(255) NULL AFTER `room_code`;

ALTER TABLE `figura`
  MODIFY `fgr_name` VARCHAR(255) NULL AFTER `fgr_id`,
  MODIFY `fgr_info` VARCHAR(255) NULL AFTER `fgr_name`,
  MODIFY `fgr_level` INT NULL AFTER `fgr_info`,
  MODIFY `fgr_iddance` INT NULL AFTER `fgr_level`;

ALTER TABLE `html_blok`
  MODIFY `blk_info` TEXT NULL AFTER `blk_id`,
  MODIFY `blk_body` TEXT NULL AFTER `blk_info`,
  MODIFY `blk_SQL` TEXT NULL AFTER `blk_body`,
  MODIFY `blk_tab` TEXT NULL AFTER `blk_SQL`,
  MODIFY `blk_tabrow1` VARCHAR(255) NULL AFTER `blk_tab`,
  MODIFY `blk_tabrow2` VARCHAR(255) NULL AFTER `blk_tabrow1`,
  MODIFY `blk_tabtit` VARCHAR(255) NULL AFTER `blk_tabrow2`,
  MODIFY `blk_form` TEXT NULL AFTER `blk_tabtit`;

ALTER TABLE `html_blokin`
  MODIFY `bdi_idblock` INT NULL AFTER `bdi_id`,
  MODIFY `bdi_num` INT NULL AFTER `bdi_idblock`,
  MODIFY `bdi_id_page` INT NULL AFTER `bdi_num`,
  MODIFY `bdi_info` TEXT NULL AFTER `bdi_id_page`;

ALTER TABLE `html_page`
  MODIFY `pg_name` VARCHAR(255) NULL AFTER `pg_id`,
  MODIFY `pg_id_head` TEXT NULL AFTER `pg_name`,
  MODIFY `pg_id_script` INT NULL AFTER `pg_id_head`,
  MODIFY `pg_id_style` INT NULL AFTER `pg_id_script`,
  MODIFY `pg_id_body` INT NULL AFTER `pg_id_style`;

ALTER TABLE `html_tabcell`
  MODIFY `tab_id_blk` INT NULL AFTER `tab_id`,
  MODIFY `tab_nametitle` VARCHAR(255) NULL AFTER `tab_id_blk`,
  MODIFY `tab_namedb` VARCHAR(255) NULL AFTER `tab_nametitle`,
  MODIFY `tab_num` VARCHAR(255) NULL AFTER `tab_namedb`,
  MODIFY `tab_htmltag` VARCHAR(255) NULL AFTER `tab_num`;

ALTER TABLE `LessObj`
  MODIFY `lo_name` VARCHAR(255) NULL AFTER `lo_id`,
  MODIFY `lo_idlt` INT NULL AFTER `lo_name`,
  MODIFY `lo_info` VARCHAR(255) NULL AFTER `lo_idlt`;

ALTER TABLE `LessType`
  MODIFY `it_name` VARCHAR(255) NULL AFTER `lt_id`;

ALTER TABLE `LessWrk`
  MODIFY `lw_name` VARCHAR(255) NULL AFTER `lw_id`,
  MODIFY `lw_idlt` INT NULL AFTER `lw_name`,
  MODIFY `lw_info` VARCHAR(255) NULL AFTER `lw_idlt`;

ALTER TABLE `lvl`
  MODIFY `lvl_name` VARCHAR(255) NULL AFTER `lvl_id`,
  MODIFY `lvl_info` VARCHAR(255) NULL AFTER `lvl_name`;

ALTER TABLE `muscul`
  MODIFY `mscl_name` VARCHAR(255) NULL AFTER `mscl_id`,
  MODIFY `mscl_info` VARCHAR(255) NULL AFTER `mscl_name`;

ALTER TABLE `practik`
  MODIFY `prkt_name` VARCHAR(255) NULL AFTER `prkt_id`,
  MODIFY `prkt_info` VARCHAR(255) NULL AFTER `prkt_name`;

ALTER TABLE `prepod`
  MODIFY `prp_name` VARCHAR(255) NULL AFTER `prp_id`,
  MODIFY `prp_idfox` VARCHAR(255) NULL AFTER `prp_name`,
  MODIFY `prp_phone` VARCHAR(255) NULL AFTER `prp_idfox`,
  MODIFY `prp_info` VARCHAR(255) NULL AFTER `prp_phone`,
  MODIFY `prp_pass` VARCHAR(255) NULL AFTER `prp_info`,
  MODIFY `prp_out` TINYINT(1) NOT NULL DEFAULT 0 AFTER `prp_pass`,
  MODIFY `prp_link` VARCHAR(255) NULL AFTER `prp_out`;

ALTER TABLE `progress`
  MODIFY `prgs_idlt` INT NULL AFTER `prgs_id`,
  MODIFY `prgs_idprp` INT NULL AFTER `prgs_idlt`,
  MODIFY `prgs_idcln` INT NULL AFTER `prgs_idprp`,
  MODIFY `prgs_iddns` INT NULL AFTER `prgs_idcln`,
  MODIFY `prgs_level` VARCHAR(255) NULL AFTER `prgs_iddns`,
  MODIFY `prgs_idfigur` INT NULL AFTER `prgs_level`,
  MODIFY `prgs_status` VARCHAR(255) NULL AFTER `prgs_idfigur`,
  MODIFY `prgs_date_create` DATETIME NULL AFTER `prgs_status`,
  MODIFY `prgs_date_cheng` DATETIME NULL AFTER `prgs_date_create`,
  MODIFY `prgs_recom` VARCHAR(255) NULL AFTER `prgs_date_cheng`,
  MODIFY `prgs_idLesObj` INT NULL AFTER `prgs_recom`,
  MODIFY `prgs_idLesWrk` INT NULL AFTER `prgs_idLesObj`,
  MODIFY `prgs_plannext` VARCHAR(255) NULL AFTER `prgs_idLesWrk`;

ALTER TABLE `purpose`
  MODIFY `clpp_idcln` INT NULL AFTER `clpp_id`,
  MODIFY `clpp_idprp_on` INT NULL AFTER `clpp_idcln`,
  MODIFY `clpp_idprp_off` INT NULL AFTER `clpp_idprp_on`,
  MODIFY `clpp_dateon` DATETIME NULL AFTER `clpp_idprp_off`,
  MODIFY `clpp_dateoff` DATETIME NULL AFTER `clpp_dateon`,
  MODIFY `clpp_dateplan` DATETIME NULL AFTER `clpp_dateoff`,
  MODIFY `clpp_purpose` VARCHAR(255) NULL AFTER `clpp_dateplan`,
  MODIFY `clpp_info` VARCHAR(255) NULL AFTER `clpp_purpose`;

ALTER TABLE `schedule`
  MODIFY `shdl_idcln` INT NULL AFTER `shdl_id`,
  MODIFY `shdl_dtleson` DATETIME NULL AFTER `shdl_idcln`,
  MODIFY `shdl_dtlesoff` DATETIME NULL AFTER `shdl_dtleson`,
  MODIFY `shdl_idclb` INT NULL AFTER `shdl_dtlesoff`,
  MODIFY `shdl_nameless` VARCHAR(255) NULL AFTER `shdl_idclb`,
  MODIFY `shdl_nameroom` VARCHAR(255) NULL AFTER `shdl_nameless`,
  MODIFY `shdl_idprp` INT NULL AFTER `shdl_nameroom`,
  MODIFY `shdl_dtlesend` INT NULL AFTER `shdl_idprp`,
  MODIFY `shdl_datecc` DATETIME NULL AFTER `shdl_dtlesend`,
  MODIFY `shdl_del` TINYINT(1) NOT NULL DEFAULT 0 AFTER `shdl_datecc`,
  MODIFY `shdl_relocat` TINYINT(1) NOT NULL DEFAULT 0 AFTER `shdl_del`;

ALTER TABLE `shownum`
  MODIFY `shw_name` VARCHAR(255) NULL AFTER `shw_id`,
  MODIFY `show_num` VARCHAR(255) NULL AFTER `shw_name`,
  MODIFY `show_info` VARCHAR(255) NULL AFTER `show_num`;

ALTER TABLE `smssendlog`
  MODIFY `ssl_date` DATETIME NULL AFTER `ssl_id`,
  MODIFY `ssl_text` VARCHAR(255) NULL AFTER `ssl_date`,
  MODIFY `ssl_idclpp` INT NULL AFTER `ssl_text`,
  MODIFY `ssl_2d` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ssl_idclpp`;

ALTER TABLE `sys_tab`
  MODIFY `tab_name` VARCHAR(255) NULL AFTER `id`,
  MODIFY `tab_idkey` VARCHAR(255) NULL AFTER `tab_name`;

ALTER TABLE `trenertype`
  MODIFY `trt_name` VARCHAR(255) NULL AFTER `trt_id`,
  MODIFY `trt_info` VARCHAR(255) NULL AFTER `trt_name`;
