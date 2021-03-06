BEGIN;

ALTER TABLE account_credentials
    ADD acd_company TEXT;

COMMIT;
