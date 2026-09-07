-- Without these, ScheduleDbfImportService/Schedule1cSyncService's per-row
-- upsert lookup and reconcileCancelledAndMoved's reconciliation query each
-- do a full table scan of `schedule` -- fine when the table is empty, but
-- it measurably stalls once real data is loaded (confirmed locally: a test
-- run against ~183k already-imported real schedule rows took well over two
-- minutes before these indexes existed). Both the DBF import and the 1C
-- sync run frequently via cron against the live table, so this matters for
-- more than just local dev.

CREATE INDEX idx_schedule_match ON schedule (shdl_idcln, shdl_idprp, shdl_idclb, shdl_nameless);
CREATE INDEX idx_schedule_reconcile ON schedule (shdl_del, shdl_datecc, shdl_relocat);
CREATE INDEX idx_schedule_idclb ON schedule (shdl_idclb);
