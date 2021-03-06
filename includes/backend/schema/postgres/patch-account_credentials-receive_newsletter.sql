BEGIN;

ALTER TABLE account_credentials
    ADD acd_receive_newsletter INTEGER NOT NULL DEFAULT 0;

COMMIT;
