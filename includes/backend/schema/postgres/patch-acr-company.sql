BEGIN;

ALTER TABLE account_requests
	ADD acr_company TEXT;

COMMIT;
