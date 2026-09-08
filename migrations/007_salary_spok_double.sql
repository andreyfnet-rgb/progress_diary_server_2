-- wv_datein_group_week.spok is genuinely a Double in the real Access view
-- output (confirmed directly via OleDb, e.g. "24463.6596679688"), not an
-- integer count as the original schema assumed. Storing it as INT was
-- silently truncating the fractional part (MySQL only warns, doesn't
-- reject, under a permissive SQL mode) until a locale where CStr()
-- formats decimals with a comma (e.g. Russian Windows) turned that into
-- an outright import failure instead of silent data loss.

ALTER TABLE wv_datein_group_week MODIFY spok DOUBLE NULL;
