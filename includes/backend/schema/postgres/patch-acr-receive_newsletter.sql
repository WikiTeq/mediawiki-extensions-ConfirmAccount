BEGIN;

ALTER TABLE account_requests
	ADD acr_receive_newsletter INTEGER NOT NULL DEFAULT 0;

COMMIT;
