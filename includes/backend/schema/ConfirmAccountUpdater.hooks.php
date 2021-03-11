<?php
/**
 * Class containing updater functions for a ConfirmAccount environment
 */
class ConfirmAccountUpdaterHooks {

	/**
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function addSchemaUpdates( DatabaseUpdater $updater ) {
		$base = __DIR__;
		$type = $updater->getDB()->getType();
		if ( $type === 'mysql' || $type === 'sqlite' ) {
			$base = "$base/mysql";

			$updater->addExtensionTable( 'account_requests', "$base/ConfirmAccount.sql" );
			$updater->addExtensionField(
				'account_requests', 'acr_filename', "$base/patch-acr_filename.sql"
			);
			$updater->addExtensionTable( 'account_credentials', "$base/patch-account_credentials.sql" );
			$updater->addExtensionField( 'account_requests', 'acr_areas', "$base/patch-acr_areas.sql" );
			if ( $type !== 'sqlite' ) {
				$updater->modifyExtensionField(
					'account_requests', 'acr_email', "$base/patch-acr_email-varchar.sql"
				);
			}
			$updater->addExtensionIndex( 'account_requests', 'acr_email', "$base/patch-email-index.sql" );
			$updater->addExtensionField( 'account_requests', 'acr_agent', "$base/patch-acr_agent.sql" );
			$updater->dropExtensionIndex(
				'account_requests', 'acr_deleted_reg', "$base/patch-drop-acr_deleted_reg-index.sql"
			);

			$updater->addExtensionField( 'account_requests', 'acr_company', "$base/patch-acr-company.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_company', "$base/patch-account_credentials-company.sql" );

			$updater->addExtensionField( 'account_requests', 'acr_receive_emails', "$base/patch-acr-receive_emails.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_receive_emails', "$base/patch-account_credentials-receive_emails.sql" );

			$updater->addExtensionField( 'account_requests', 'acr_receive_newsletter', "$base/patch-acr-receive_newsletter.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_receive_newsletter', "$base/patch-account_credentials-receive_newsletter.sql" );

			$updater->addExtensionField( 'account_requests', 'acr_city', "$base/patch-acr-city.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_city', "$base/patch-account_credentials-city.sql" );

			$updater->addExtensionField( 'account_requests', 'acr_country', "$base/patch-acr-country.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_country', "$base/patch-account_credentials-country.sql" );

			$updater->addExtensionField( 'account_requests', 'acr_firstname', "$base/patch-acr-firstname.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_firstname', "$base/patch-account_credentials-firstname.sql" );

			$updater->addExtensionField( 'account_requests', 'acr_lastname', "$base/patch-acr-lastname.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_lastname', "$base/patch-account_credentials-lastname.sql" );

			$updater->addExtensionField( 'account_requests', 'acr_prefix', "$base/patch-acr-prefix.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_prefix', "$base/patch-account_credentials-prefix.sql" );

			$updater->addExtensionField( 'account_requests', 'acr_state', "$base/patch-acr-state.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_state', "$base/patch-account_credentials-state.sql" );

			$updater->addExtensionField( 'account_requests', 'acr_title', "$base/patch-acr-title.sql" );
			$updater->addExtensionField( 'account_credentials', 'acd_title', "$base/patch-account_credentials-title.sql" );

		} elseif ( $type === 'postgres' ) {
			$base = "$base/postgres";

			$updater->addExtensionUpdate(
				[ 'addTable', 'account_requests', "$base/ConfirmAccount.pg.sql", true ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_held', "TIMESTAMPTZ" ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_filename', "TEXT" ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_storage_key', "TEXT" ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_comment', "TEXT NOT NULL DEFAULT ''" ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_type', "INTEGER NOT NULL DEFAULT 0" ]
			);
			$updater->addExtensionUpdate(
				[ 'addTable', 'account_credentials', "$base/patch-account_credentials.sql", true ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_areas', "TEXT" ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_credentials', 'acd_areas', "TEXT" ]
			);
			$updater->addExtensionUpdate(
				[ 'addIndex', 'account_requests', 'acr_email', "$base/patch-email-index.sql", true ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_agent', "$base/patch-acr_agent.sql", true ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_company', "$base/patch-acr-company.sql", true ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_credentials', 'acd_company', "$base/patch-account_credentials-company.sql", true ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_receive_emails', "$base/patch-acr-receive_emails.sql", true ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_credentials', 'acd_receive_emails', "$base/patch-account_credentials-receive_emails.sql", true ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_requests', 'acr_receive_newsletter', "$base/patch-acr-receive_newsletter.sql", true ]
			);
			$updater->addExtensionUpdate(
				[ 'addPgField', 'account_credentials', 'acd_receive_newsletter', "$base/patch-account_credentials-receive_newsletter.sql", true ]
			);
		}
		return true;
	}
}
