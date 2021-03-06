BEGIN;

ALTER TABLE account_credentials
    ADD acd_receive_emails INTEGER NOT NULL DEFAULT 0;

COMMIT;
