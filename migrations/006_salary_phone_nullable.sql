-- The real 1cdbgdsweek1c.mdb export has rows with no phone at all (587 of
-- 77257 in wv_stpprep_week, 72 of 23705 in wv_datein_group_week) --
-- confirmed genuine Access NULLs, not empty strings. get_dat_sal always
-- filters by a specific phone value, so these rows are simply never
-- matched by anything; they don't need excluding, just don't need to
-- reject the whole daily import over.

ALTER TABLE wv_stpprep_week MODIFY phone VARCHAR(32) NULL;
ALTER TABLE wv_datein_group_week MODIFY phone VARCHAR(32) NULL;
