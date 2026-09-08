-- ScheduleDbfImportService/Schedule1cSyncService never set shdl_dtlesend on
-- INSERT, so every newly-synced lesson row ended up NULL there. Every row
-- from the original Access migration has a real 0/1/2 value (0 = not yet
-- taught, matching the convention dp.galladance.com's own pd.php relies on
-- when it filters a teacher's pending lessons with shdl_dtlesend=0 -- NULL
-- never equals 0 in SQL, so today's real lessons were silently invisible
-- on the teacher's own page). Backfill first (a column can't be made
-- NOT NULL while NULLs still exist), then lock in the default so this
-- can't regress again even if some future code path forgets to set it.

UPDATE schedule SET shdl_dtlesend = 0 WHERE shdl_dtlesend IS NULL;

ALTER TABLE schedule MODIFY shdl_dtlesend INT NOT NULL DEFAULT 0;
