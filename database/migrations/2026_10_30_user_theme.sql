-- CRM theme: each person's own choice of dark mode (Night Shift).
-- NULL = the standard light look (Peacock). Set by the switch in the sidebar.
ALTER TABLE users ADD COLUMN theme VARCHAR(10) NULL DEFAULT NULL;
