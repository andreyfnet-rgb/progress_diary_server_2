-- The 1C schedule feed sends each client's own 1C card Id
-- ({"Client": {"Id": "000072388", "Name": ..., "Phone": ...}}), but the sync
-- used to identify clients by phone only. Two 1C cards sharing one phone
-- (a data-entry mistake, or relatives) then landed on a single Client row,
-- and the row kept whichever name arrived first -- e.g. Елисеев's student
-- Архипова showing up as "Кулакова Екатерина" in the diary. cln_id1c lets
-- Schedule1cSyncService match on the 1C card itself.

ALTER TABLE Client ADD COLUMN cln_id1c VARCHAR(32) NULL;
CREATE INDEX idx_cln_id1c ON Client (cln_id1c);
CREATE INDEX idx_cln_phone ON Client (cln_phone);
