-- Adds the three tables get_act_sal/getdatashow0722 (the "Мой доход"
-- mobile-app income screen's real data source) needs, alongside the
-- existing get_dat_sal pipeline (wv_stpprep_week/wv_datein_group_week).
-- Also used by the already-existing (but until now unpopulated) `stavka`
-- table from salary_schema.sql. See salary_schema.sql for the full
-- rationale.

CREATE TABLE IF NOT EXISTS wv_prepod (
    prepod_id INT NOT NULL,
    phone VARCHAR(32) NULL,
    nameprep VARCHAR(255) NULL,
    namecat VARCHAR(255) NULL,
    nameclb VARCHAR(255) NULL,
    KEY idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wv_datain (
    idprep INT NOT NULL,
    idstv INT NOT NULL,
    dataindo DATETIME NULL,
    pok DOUBLE NULL,
    sumplan TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_prep_date (idprep, dataindo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wv_stvprep (
    idprep INT NOT NULL,
    idstav INT NOT NULL,
    datado DATETIME NULL,
    minpok INT NULL,
    midpok INT NULL,
    maxpok INT NULL,
    mnojstv VARCHAR(50) NULL,
    trio TINYINT(1) NOT NULL DEFAULT 0,
    plan INT NULL,
    raschet TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_prep_date (idprep, datado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
