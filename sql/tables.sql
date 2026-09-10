-- Asyntai AI Chatbot tables. Plain SQL that MySQL, MariaDB, SQLite and
-- PostgreSQL all accept.

CREATE TABLE /*_*/asyntai_settings (
	as_key VARCHAR(64) NOT NULL PRIMARY KEY,
	as_value TEXT NOT NULL
) /*$wgDBTableOptions*/;

CREATE TABLE /*_*/asyntai_pages (
	ap_page INTEGER NOT NULL PRIMARY KEY,
	ap_kb_id VARCHAR(64) NOT NULL,
	ap_rev INTEGER NOT NULL,
	ap_synced VARCHAR(14) NOT NULL
) /*$wgDBTableOptions*/;
