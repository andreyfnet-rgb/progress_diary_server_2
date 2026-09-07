-- sys_tab (migrated verbatim from the real Access DB_PD2.mdb) is missing
-- entries for `prepod` and `clubs`. Without a sys_tab row, GenericTableService
-- (and, identically, the original Delphi put_dattab) cannot resolve the key
-- column for an id-based UPDATE and silently fails the row while still
-- returning "ok" overall -- discovered while testing the new admindp
-- teachers page's "deactivate" button (PUT /putdattab?tab=prepod with an
-- {"id":...} row), which never actually wrote anything on either the old
-- or new server for exactly this reason.

INSERT INTO sys_tab (tab_name, tab_idkey) VALUES ('prepod', 'prp_id');
INSERT INTO sys_tab (tab_name, tab_idkey) VALUES ('clubs', 'clb_id');
