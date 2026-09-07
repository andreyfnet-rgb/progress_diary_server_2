-- Schema for the salary side of the backend, sourced from the SEPARATE
-- Access database 1cdbgdsweek1c.mdb (not DB_PD2.mdb -- see prop.ini's
-- separate [db] path_sal). That file has 30 tables, most of them leftover
-- clutter from unrelated systems ("aup_*", "Bar_*", "1old_*",
-- "temp_1c_del*"); only a handful are actually reachable from the API
-- (SrvMetod.pas's get_dat_sal/get_act_sal/getdatashow0722), all dumped via
-- ADOX from the real file.
--
-- Deliberate design choice (see the plan doc): rather than reimplementing
-- Access's week-bucketing views (wv_stpprep_week, wv_datein_group_week --
-- built on `DatePart("ww", date, 2, 1) & Year(date)`, Access-specific
-- syntax with no direct MySQL equivalent) as MySQL views, these are
-- ordinary TABLES here, refreshed daily by exporting the Access VIEW'S
-- OUTPUT rows directly (tools/export-access-to-csv.ps1, still to be
-- built) into CSV and importing that. This sidesteps needing to prove a
-- hand-translated MySQL week-numbering formula produces byte-identical
-- week keys to Access's -- we just copy the numbers Access already
-- computed. Only the two tables get_dat_sal actually queries are
-- included; wv_prepod/wv_stvprep/wv_datain (used by getactsal's
-- getdatashow0722, not yet ported) can be added the same way later.
--
-- The week/year key format ("sweekno"/"pweekno") is a plain
-- concatenation of ISO week number and calendar year with no separator
-- (e.g. week 36 of 2026 -> "362026"), matching the original's
-- IntToStr(WeekOfTheYear(d)) + IntToStr(YearOf(d)) exactly (PHP's
-- date('W') is also ISO-8601, so the read side computes the same key with
-- date('W') . date('Y')).
--
-- Phone numbers in this database are stored WITH dashes
-- ("7-926-599-01-31"), unlike the main DB.PD2 database's plain-digit
-- phone columns -- confirmed by SrvMetod.pas's get_dat_sal, which
-- reformats the incoming phone with PhoneFormatting::insertSalaryPhoneDashes
-- before querying.

SET NAMES utf8mb4;

CREATE TABLE stavka (
    id INT NOT NULL PRIMARY KEY,
    idplus INT NULL,
    mnojstv VARCHAR(50) NULL,
    namestv VARCHAR(255) NULL,
    notinclud TINYINT(1) NOT NULL DEFAULT 0,
    plan INT NULL,
    raschet TINYINT(1) NOT NULL DEFAULT 0,
    showsg1 TINYINT(1) NOT NULL DEFAULT 0,
    sqlfield VARCHAR(255) NULL,
    sqlfield_1c VARCHAR(255) NULL,
    sumplan TINYINT(1) NOT NULL DEFAULT 0,
    trio TINYINT(1) NOT NULL DEFAULT 0,
    wherein_1с TEXT NULL,
    wherein_1с_parent TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Materialized snapshot of the wv_stpprep_week Access view's output.
CREATE TABLE wv_stpprep_week (
    sweekno VARCHAR(20) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    stv_id INT NOT NULL,
    maxpok INT NULL,
    midpok INT NULL,
    minpok INT NULL,
    wherein_1с TEXT NULL,
    datado DATETIME NULL,
    KEY idx_week_phone_stv (sweekno, phone, stv_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Materialized snapshot of the wv_datein_group_week Access view's output.
CREATE TABLE wv_datein_group_week (
    pweekno VARCHAR(20) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    id INT NOT NULL,
    spok INT NULL,
    KEY idx_week_phone_id (pweekno, phone, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
