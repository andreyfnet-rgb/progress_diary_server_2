-- Schema for the GDPD progress-diary backend, ported from the Access
-- database DB_PD2.mdb. Table/column layout and view SQL were dumped
-- directly from the production .mdb via ADOX (not reconstructed from the
-- Delphi source), so this should match the real data 1:1.
--
-- Access -> MySQL type mapping used throughout:
--   adInteger (3)      -> INT
--   adVarWChar(n) (202)-> VARCHAR(n)
--   adLongVarWChar/Memo (203) -> TEXT
--   adDate (7)         -> DATETIME
--   adBoolean (11)     -> TINYINT(1)
--
-- Access has no real foreign keys defined in this database (verified via
-- the ADOX dump), so none are added here either -- keeps the one-time data
-- import order-independent. Nullability follows the ADOX-reported
-- Attributes flag (adColNullable) as observed.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Tables
-- ---------------------------------------------------------------------

CREATE TABLE Client (
    cln_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cln_idfox VARCHAR(255) NULL,
    cln_info VARCHAR(255) NULL,
    cln_lvl VARCHAR(255) NULL,
    cln_name VARCHAR(255) NULL,
    cln_phone VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clubs (
    clb_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clb_id1c VARCHAR(255) NULL,
    clb_in1с TINYINT(1) NOT NULL DEFAULT 0,
    clb_indbf TINYINT(1) NOT NULL DEFAULT 0,
    clb_info VARCHAR(255) NULL,
    clb_name VARCHAR(255) NULL,
    clb_pass VARCHAR(255) NULL,
    clb_patchindb VARCHAR(255) NULL,
    clb_timeindb DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dance (
    dns_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dns_color VARCHAR(255) NULL,
    dns_name VARCHAR(255) NULL,
    id_dt INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dancetype (
    dt_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    dt_color VARCHAR(255) NULL,
    dt_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE DP_blok1_less (
    pb1_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pb1_aboutless TEXT NULL,
    pb1_date DATETIME NULL,
    pb1_datles DATETIME NULL,
    pb1_idcln INT NULL,
    pb1_idd VARCHAR(255) NULL,
    pb1_iddt INT NULL,
    pb1_idlt INT NULL,
    pb1_idprp INT NULL,
    pb1_idsn INT NULL,
    pb1_idwrk INT NULL,
    pb1_info TEXT NULL,
    pb1_plan VARCHAR(255) NULL,
    pb1_recom VARCHAR(255) NULL,
    pb1_status VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE DP_blok2_figur (
    pb2_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pb2_datoff DATETIME NULL,
    pb2_daton DATETIME NULL,
    pb2_idcln INT NULL,
    pb2_idfigur INT NULL,
    pb2_idprpoff INT NULL,
    pb2_idprpon INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exclude_1c (
    ID INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    class_code VARCHAR(255) NULL,
    class_name VARCHAR(255) NULL,
    room_code VARCHAR(255) NULL,
    room_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE figura (
    fgr_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fgr_iddance INT NULL,
    fgr_info VARCHAR(255) NULL,
    fgr_level INT NULL,
    fgr_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- html_* tables back the legacy mini-CMS ("/admindp" page). Not used by
-- Phase 1's priority endpoints; ported for completeness/schema parity only.
CREATE TABLE html_blok (
    blk_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    blk_body TEXT NULL,
    blk_form TEXT NULL,
    blk_info TEXT NULL,
    blk_SQL TEXT NULL,
    blk_tab TEXT NULL,
    blk_tabrow1 VARCHAR(255) NULL,
    blk_tabrow2 VARCHAR(255) NULL,
    blk_tabtit VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE html_blokin (
    bdi_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bdi_id_page INT NULL,
    bdi_idblock INT NULL,
    bdi_info TEXT NULL,
    bdi_num INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE html_page (
    pg_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pg_id_body INT NULL,
    pg_id_head TEXT NULL,
    pg_id_script INT NULL,
    pg_id_style INT NULL,
    pg_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE html_tabcell (
    tab_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tab_htmltag VARCHAR(255) NULL,
    tab_id_blk INT NULL,
    tab_namedb VARCHAR(255) NULL,
    tab_nametitle VARCHAR(255) NULL,
    tab_num VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE LessObj (
    lo_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lo_idlt INT NULL,
    lo_info VARCHAR(255) NULL,
    lo_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE LessType (
    lt_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    it_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE LessWrk (
    lw_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lw_idlt INT NULL,
    lw_info VARCHAR(255) NULL,
    lw_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lvl (
    lvl_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lvl_info VARCHAR(255) NULL,
    lvl_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE muscul (
    mscl_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mscl_info VARCHAR(255) NULL,
    mscl_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE practik (
    prkt_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    prkt_info VARCHAR(255) NULL,
    prkt_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE prepod (
    prp_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    prp_idfox VARCHAR(255) NULL,
    prp_info VARCHAR(255) NULL,
    prp_link VARCHAR(255) NULL,
    prp_name VARCHAR(255) NULL,
    prp_out TINYINT(1) NOT NULL DEFAULT 0,
    prp_pass VARCHAR(255) NULL,
    prp_phone VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE progress (
    prgs_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    prgs_date_cheng DATETIME NULL,
    prgs_date_create DATETIME NULL,
    prgs_idcln INT NULL,
    prgs_iddns INT NULL,
    prgs_idfigur INT NULL,
    prgs_idLesObj INT NULL,
    prgs_idLesWrk INT NULL,
    prgs_idlt INT NULL,
    prgs_idprp INT NULL,
    prgs_level VARCHAR(255) NULL,
    prgs_plannext VARCHAR(255) NULL,
    prgs_recom VARCHAR(255) NULL,
    prgs_status VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE purpose (
    clpp_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clpp_dateoff DATETIME NULL,
    clpp_dateon DATETIME NULL,
    clpp_dateplan DATETIME NULL,
    clpp_idcln INT NULL,
    clpp_idprp_off INT NULL,
    clpp_idprp_on INT NULL,
    clpp_info VARCHAR(255) NULL,
    clpp_purpose VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE schedule (
    shdl_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shdl_datecc DATETIME NULL,
    shdl_del TINYINT(1) NOT NULL DEFAULT 0,
    shdl_dtlesend INT NULL,
    shdl_dtlesoff DATETIME NULL,
    shdl_dtleson DATETIME NULL,
    shdl_idclb INT NULL,
    shdl_idcln INT NULL,
    shdl_idprp INT NULL,
    shdl_nameless VARCHAR(255) NULL,
    shdl_nameroom VARCHAR(255) NULL,
    shdl_relocat TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE shownum (
    shw_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    show_info VARCHAR(255) NULL,
    show_num VARCHAR(255) NULL,
    shw_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE smssendlog (
    ssl_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ssl_2d TINYINT(1) NOT NULL DEFAULT 0,
    ssl_date DATETIME NULL,
    ssl_idclpp INT NULL,
    ssl_text VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sys_tab (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tab_idkey VARCHAR(255) NULL,
    tab_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE trenertype (
    trt_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trt_info VARCHAR(255) NULL,
    trt_name VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Views (translated 1:1 from the Access query definitions dumped via
-- ADOX; only syntax changes: True/False -> 1/0, "WITH OWNERACCESS OPTION"
-- dropped -- the JOIN structure and column list are unchanged)
-- ---------------------------------------------------------------------

CREATE VIEW calend AS
SELECT p.prgs_id, P.prgs_date_create, P.prgs_level, P.prgs_status, P.prgs_recom, PRP.prp_name, LT.it_name, DNS.dns_name, dns.dns_color, F.fgr_name, LO.lo_name, LW.lw_name, cln.cln_phone
FROM ((((((progress AS P LEFT JOIN prepod AS PRP ON PRP.prp_id = P.prgs_idprp) LEFT JOIN LessType AS LT ON LT.lt_id = P.prgs_idlt) LEFT JOIN LessWrk AS LW ON LW.lw_id = P.prgs_idLesWrk) LEFT JOIN LessObj AS LO ON LO.lo_id = P.prgs_idLesObj) LEFT JOIN figura AS F ON F.fgr_id = p.prgs_idfigur) LEFT JOIN dance AS DNS ON DNS.dns_id = p.prgs_iddns) LEFT JOIN Client AS CLN ON CLN.cln_id = p.prgs_idcln;

CREATE VIEW Calend_v2 AS
SELECT DP_blok1_less.pb1_date, DP_blok1_less.pb1_datles, DP_blok1_less.pb1_aboutless, LessType.it_name, DP_blok1_less.pb1_idd, dancetype.dt_name, prepod.prp_name, dancetype.dt_color, DP_blok1_less.pb1_recom, Client.cln_name, Client.cln_phone, prepod.prp_phone, prepod.prp_link
FROM (Client INNER JOIN (prepod INNER JOIN (LessType INNER JOIN DP_blok1_less ON LessType.lt_id = DP_blok1_less.pb1_idlt) ON prepod.prp_id = DP_blok1_less.pb1_idprp) ON Client.cln_id = DP_blok1_less.pb1_idcln) INNER JOIN dancetype ON DP_blok1_less.pb1_iddt = dancetype.dt_id;

CREATE VIEW Figurles AS
SELECT f.*, client.cln_phone
FROM (SELECT frg.*, DP_blok2_figur.pb2_daton, DP_blok2_figur.pb2_datoff, DP_blok2_figur.pb2_idcln FROM (SELECT figura.*, lvl.lvl_name, dance.*, dancetype.* FROM ((figura LEFT JOIN lvl ON figura.fgr_level=lvl.lvl_id) LEFT JOIN dance ON figura.fgr_iddance=dance.dns_id) LEFT JOIN dancetype ON dancetype.dt_id = dance.id_dt) AS frg LEFT JOIN DP_blok2_figur ON frg.fgr_id = DP_blok2_figur.pb2_idfigur) AS f LEFT JOIN client ON f.pb2_idcln = client.cln_id
-- Original Access SQL qualified this as "frg.dt_name, frg.fgr_level,
-- frg.fgr_id" -- Jet tolerates reaching two subquery levels down since f.*
-- already re-exposes those columns unqualified; MySQL requires referencing
-- them through the actual output names instead.
ORDER BY dt_name, fgr_level, fgr_id;

CREATE VIEW get_inrecPD AS
SELECT DP_blok1_less.pb1_id, DP_blok1_less.pb1_idcln, DP_blok1_less.pb1_idprp, DP_blok1_less.pb1_date, DP_blok1_less.pb1_aboutless, LessType.it_name, DP_blok1_less.pb1_idd, dancetype.dt_name
FROM ((prepod INNER JOIN (Client INNER JOIN DP_blok1_less ON Client.cln_id = DP_blok1_less.pb1_idcln) ON prepod.prp_id = DP_blok1_less.pb1_idprp) INNER JOIN LessType ON DP_blok1_less.pb1_idlt = LessType.lt_id) INNER JOIN dancetype ON DP_blok1_less.pb1_iddt = dancetype.dt_id
ORDER BY DP_blok1_less.pb1_id;

CREATE VIEW log_sms_send AS
SELECT smssendlog.*, purpose.clpp_dateplan, prepod.prp_name, prepod.prp_phone, Client.cln_name
FROM ((purpose INNER JOIN smssendlog ON purpose.clpp_id = smssendlog.ssl_idclpp) LEFT JOIN prepod ON purpose.clpp_idprp_on = prepod.prp_id) LEFT JOIN Client ON purpose.clpp_idcln = Client.cln_id;

CREATE VIEW purpose_all AS
SELECT p.clpp_id, p.clpp_dateon, p.clpp_dateoff, p.clpp_dateplan, p.clpp_purpose, p.clpp_info, c.cln_name, c.cln_id, pr.prp_name AS prp_off, pr2.prp_name AS prp_on, c.cln_phone
FROM ((purpose AS p LEFT JOIN client AS c ON p.clpp_idcln = c.cln_id) LEFT JOIN prepod AS pr ON p.clpp_idprp_off = pr.prp_id) LEFT JOIN prepod AS pr2 ON p.clpp_idprp_on = pr2.prp_id;

CREATE VIEW shed AS
SELECT Client.cln_name, Client.cln_phone, prepod.prp_id, Client.cln_idfox, prepod.prp_name, prepod.prp_phone, clubs.clb_name, schedule.shdl_dtleson, schedule.shdl_dtlesoff, schedule.shdl_datecc, schedule.shdl_nameless, schedule.shdl_nameroom, schedule.shdl_idcln, schedule.shdl_idprp, schedule.shdl_dtlesend, schedule.shdl_del
FROM clubs INNER JOIN (Client INNER JOIN (prepod INNER JOIN schedule ON prepod.prp_id = schedule.shdl_idprp) ON Client.cln_id = schedule.shdl_idcln) ON clubs.clb_id = schedule.shdl_idclb
WHERE schedule.shdl_del = 0
ORDER BY schedule.shdl_dtleson;

CREATE VIEW sms_send_2d AS
SELECT purpose.clpp_dateplan, prepod.prp_phone, purpose.clpp_purpose, Client.cln_name, purpose.clpp_id, prepod.prp_name
FROM (purpose LEFT JOIN prepod ON purpose.clpp_idprp_on = prepod.prp_id) LEFT JOIN Client ON purpose.clpp_idcln = Client.cln_id
WHERE prepod.prp_phone IS NOT NULL
  AND purpose.clpp_id NOT IN (SELECT ssl_idclpp FROM smssendlog WHERE ssl_2d = 1)
  AND purpose.clpp_dateoff IS NULL
ORDER BY purpose.clpp_dateplan;

CREATE VIEW sms_send_d AS
SELECT purpose.clpp_dateplan, prepod.prp_phone, purpose.clpp_purpose, Client.cln_name, purpose.clpp_id, prepod.prp_name
FROM (purpose LEFT JOIN prepod ON purpose.clpp_idprp_on = prepod.prp_id) LEFT JOIN Client ON purpose.clpp_idcln = Client.cln_id
WHERE prepod.prp_phone IS NOT NULL
  AND purpose.clpp_id NOT IN (SELECT ssl_idclpp FROM smssendlog WHERE ssl_2d = 0)
  AND purpose.clpp_dateoff IS NULL
ORDER BY purpose.clpp_dateplan;

CREATE VIEW wv_figur AS
SELECT dancetype.*, dance.*, figura.*, lvl.*
FROM lvl INNER JOIN (dancetype INNER JOIN (dance INNER JOIN figura ON dance.dns_id = figura.fgr_iddance) ON dancetype.dt_id = dance.id_dt) ON lvl.lvl_id = figura.fgr_level;
