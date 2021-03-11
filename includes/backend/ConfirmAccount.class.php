<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\FakeResultWrapper;

class ConfirmAccount {
	/**
	 * Move old stale requests to rejected list. Delete old rejected requests.
	 */
	public static function runAutoMaintenance() {
		global $wgRejectedAccountMaxAge, $wgConfirmAccountRejectAge, $wgConfirmAccountFSRepos;

		$dbw = wfGetDB( DB_MASTER );
		$repo = self::getFileRepo( $wgConfirmAccountFSRepos['accountreqs'] );

		# Select all items older than time $encCutoff
		$encCutoff = $dbw->addQuotes( $dbw->timestamp( time() - $wgRejectedAccountMaxAge ) );
		$res = $dbw->select( 'account_requests',
			[ 'acr_id', 'acr_storage_key' ],
			[ "acr_rejected < {$encCutoff}" ],
			__METHOD__
		);

		# Clear out any associated attachments and delete those rows
		foreach ( $res as $row ) {
			$key = $row->acr_storage_key;
			if ( $key ) {
				$path = $repo->getZonePath( 'public' ) . '/' .
					UserAccountRequest::relPathFromKey( $key );
				if ( $path && file_exists( $path ) ) {
					unlink( $path );
				}
			}
			$dbw->delete( 'account_requests', [ 'acr_id' => $row->acr_id ], __METHOD__ );
		}

		# Select all items older than time $encCutoff
		$encCutoff = $dbw->addQuotes( $dbw->timestamp( time() - $wgConfirmAccountRejectAge ) );
		# Old stale accounts will count as rejected. If the request was held, give it more time.
		$dbw->update( 'account_requests',
			[ 'acr_rejected' => $dbw->timestamp(),
				'acr_user' => 0, // dummy
				'acr_comment' => wfMessage( 'confirmaccount-autorej' )->inContentLanguage()->text(),
				'acr_deleted' => 1 ],
			[ "acr_rejected IS NULL",
				"acr_registration < {$encCutoff}",
				"acr_held < {$encCutoff} OR acr_held IS NULL" ],
			__METHOD__
		);

		# Clear cache for notice of how many account requests there are
		self::clearAccountRequestCountCache();
	}

	/**
	 * Flag a user's email as confirmed in the db
	 *
	 * @param string $name
	 */
	public static function confirmEmail( $name ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'account_requests',
			[ 'acr_email_authenticated' => $dbw->timestamp() ],
			[ 'acr_name' => $name ],
			__METHOD__ );
		# Clear cache for notice of how many account requests there are
		self::clearAccountRequestCountCache();
	}

	/**
	 * Generate and store a new email confirmation token, and return
	 * the URL the user can use to confirm.
	 * @param string $token
	 * @return string
	 */
	public static function confirmationTokenUrl( $token ) {
		$title = SpecialPage::getTitleFor( 'RequestAccount' );
		return $title->getCanonicalURL( [
			'action' => 'confirmemail',
			'wpEmailToken' => $token
		] );
	}

	/**
	 * Generate, store, and return a new email confirmation code.
	 * A hash (unsalted since it's used as a key) is stored.
	 * @param User $user
	 * @param string &$expiration
	 * @return string
	 */
	public static function getConfirmationToken( $user, &$expiration ) {
		global $wgConfirmAccountRejectAge;

		$expires = time() + $wgConfirmAccountRejectAge;
		$expiration = wfTimestamp( TS_MW, $expires );
		$token = MWCryptRand::generateHex( 32 );
		return $token;
	}

	/**
	 * Generate a new email confirmation token and send a confirmation
	 * mail to the user's given address.
	 *
	 * @param User $user
	 * @param string $ip User IP address
	 * @param string $token
	 * @param string $expiration
	 * @return true|Status True on success, a Status object on failure.
	 */
	public static function sendConfirmationMail( User $user, $ip, $token, $expiration ) {
		global $wgContLang;

		$url = self::confirmationTokenUrl( $token );
		$lang = $user->getOption( 'language' );
		return $user->sendMail(
			wfMessage( 'requestaccount-email-subj' )->inLanguage( $lang )->text(),
			wfMessage( 'requestaccount-email-body',
				$ip,
				$user->getName(),
				$url,
				$wgContLang->timeanddate( $expiration, false ),
				$wgContLang->date( $expiration, false ),
				$wgContLang->time( $expiration, false )
			)->inLanguage( $lang )->text()
		);
	}

	/**
	 * Send an email about the new account creation and the temporary password.
	 *
	 * @param User $user The new user account
	 * @param User $creatingUser The user who created the account (can be anonymous)
	 * @param string $password The temporary password
	 *
	 * @return \Status
	 * @throws MWException
	 */
	public static function sendNewAccountEmail( User $user, $password ) {
		$mainPageUrl = Title::newMainPage()->getCanonicalURL();
		$userLanguage = $user->getOption( 'language' );
		$subjectMessage = wfMessage( 'createaccount-title' )->inLanguage( $userLanguage );
		$bodyMessage = wfMessage( 'createaccount-text', '', $user->getName(), $password,
			'<' . $mainPageUrl . '>', 0 )
			->inLanguage( $userLanguage );
		$status = $user->sendMail( $subjectMessage->text(), $bodyMessage->text() );
		return $status;
	}

	/**
	 * Get request information from an email confirmation token
	 *
	 * @param string $code
	 * @return array
	 */
	public static function requestInfoFromEmailToken( $code ) {
		global $wgConfirmAdminEmailExtraFields;
		$dbr = wfGetDB( DB_REPLICA );
		# Create updated array with acr_ prepended because of database names
		$acrAdminEmailFields = array_merge( array_map( function ( $fieldName ) {
			return ( 'acr_' . $fieldName );
		}, $wgConfirmAdminEmailExtraFields ), [ 'acr_name', 'acr_email_authenticated' ] );
		# Get all specified user information from database
		$reqUserInfo = $dbr->selectRow( 'account_requests',
			$acrAdminEmailFields,
			[
				'acr_email_token' => md5( $code ),
				'acr_email_token_expires > ' . $dbr->addQuotes( $dbr->timestamp() ),
			] );
		# Split the essential array values and the possible body arguments
		$adminEmailBodyArguments = array_slice( (array)$reqUserInfo, 0, -2 );
		return [
			array_values( $adminEmailBodyArguments ),
			$reqUserInfo->acr_name,
			$reqUserInfo->acr_email_authenticated
		];
	}

	/**
	 * Get the number of account requests for a request type
	 * @param int $type
	 * @return array Assosiative array with 'open', 'held', 'type' keys mapping to integers
	 */
	public static function getOpenRequestCount( $type ) {
		$dbr = wfGetDB( DB_REPLICA );
		$open = (int)$dbr->selectField( 'account_requests', 'COUNT(*)',
			[ 'acr_type' => $type, 'acr_deleted' => 0, 'acr_held IS NULL' ],
			__METHOD__
		);
		$held = (int)$dbr->selectField( 'account_requests', 'COUNT(*)',
			[ 'acr_type' => $type, 'acr_deleted' => 0, 'acr_held IS NOT NULL' ],
			__METHOD__
		);
		$rej = (int)$dbr->selectField( 'account_requests', 'COUNT(*)',
			[ 'acr_type' => $type, 'acr_deleted' => 1, 'acr_user != 0' ],
			__METHOD__
		);
		return [ 'open' => $open, 'held' => $held, 'rejected' => $rej ];
	}

	/**
	 * Get the number of open email-confirmed account requests for a request type
	 * @param int|string $type A request type or '*' for all
	 * @return int
	 */
	public static function getOpenEmailConfirmedCount( $type = '*' ) {
		global $wgMemc;

		# Check cached results
		$key = $wgMemc->makeKey( 'confirmaccount', 'econfopencount', $type );
		$count = $wgMemc->get( $key );
		# Only show message if there are any such requests
		if ( $count === false ) {
			$conds = [
				'acr_deleted' => 0, // not rejected
				'acr_held IS NULL', // nor held
				'acr_email_authenticated IS NOT NULL' ]; // email confirmed
			if ( $type !== '*' ) {
				$conds['acr_type'] = (int)$type;
			}
			$dbw = wfGetDB( DB_MASTER );
			$count = (int)$dbw->selectField( 'account_requests', 'COUNT(*)', $conds, __METHOD__ );
			# Cache results (invalidated on change )
			$wgMemc->set( $key, $count, 3600 * 24 * 7 );
		}
		return $count;
	}

	/**
	 * Clear account request cache
	 * @return void
	 */
	public static function clearAccountRequestCountCache() {
		global $wgAccountRequestTypes, $wgMemc;

		$types = array_keys( $wgAccountRequestTypes );
		$types[] = '*'; // "all" types count
		foreach ( $types as $type ) {
			$key = $wgMemc->makeKey( 'confirmaccount', 'econfopencount', $type );
			$wgMemc->delete( $key );
		}
	}

	/**
	 * Verifies that it's ok to include the uploaded file
	 *
	 * @param string $tmpfile the full path of the temporary file to verify
	 * @param string $extension The filename extension that the file is to be served with
	 * @return Status object
	 */
	public static function verifyAttachment( $tmpfile, $extension ) {
		global $wgVerifyMimeType, $wgMimeTypeBlacklist;

		// magically determine mime type
		$magic = MediaWikiServices::getInstance()->getMimeAnalyzer();
		$mime = $magic->guessMimeType( $tmpfile, false );
		# check mime type, if desired
		if ( $wgVerifyMimeType ) {
			wfDebug( "\n\nmime: <$mime> extension: <$extension>\n\n" );
			# Check mime type against file extension
			if ( !UploadBase::verifyExtension( $mime, $extension ) ) {
				return Status::newFatal( 'filetype-mime-mismatch', $extension, $mime );
			}
			# Check mime type blacklist
			if ( isset( $wgMimeTypeBlacklist ) && $wgMimeTypeBlacklist !== null
				&& self::checkFileExtension( $mime, $wgMimeTypeBlacklist ) ) {
				return Status::newFatal( 'filetype-badmime', $mime );
			}
		}
		wfDebug( __METHOD__ . ": all clear; passing.\n" );
		return Status::newGood();
	}

	/**
	 * Perform case-insensitive match against a list of file extensions.
	 * Returns true if the extension is in the list.
	 *
	 * @param string $ext
	 * @param array $list
	 * @return bool
	 */
	protected static function checkFileExtension( $ext, $list ) {
		return in_array( strtolower( $ext ), $list );
	}

	/**
	 * Get the text to add to this users page for describing editing topics
	 * for each "area" a user can be in, as defined in MediaWiki:requestaccount-areas.
	 *
	 * @return array Associative mapping of the format:
	 *    (name => ('project' => x, 'userText' => y, 'grpUserText' => (request type => z)))
	 * Any of the ultimate values can be the empty string
	 */
	public static function getUserAreaConfig() {
		static $res; // process cache
		if ( $res !== null ) {
			return $res;
		}
		$res = [];
		// Message describing the areas a user can be interested in, the corresponding wiki page,
		// and any text that is automatically appended to the userpage on account acceptance.
		// Format is <name> | <wikipage> [| <text for all>] [| <text group0>] [| <text group1>] ...
		$msg = wfMessage( 'requestaccount-areas' )->inContentLanguage();
		if ( $msg->exists() ) {
			$areas = explode( "\n*", "\n" . $msg->text() );
			foreach ( $areas as $n => $area ) {
				$set = explode( "|", $area );
				if ( count( $set ) >= 2 ) {
					$name = trim( str_replace( '_', ' ', $set[0] ) );
					$res[$name] = [];

					$res[$name]['project'] = trim( $set[1] ); // name => WikiProject mapping
					if ( isset( $set[2] ) ) {
						$res[$name]['userText'] = trim( $set[2] ); // userpage text for all
					} else {
						$res[$name]['userText'] = '';
					}

					$res[$name]['grpUserText'] = []; // userpage text for certain request types
					$categories = array_slice( $set, 3 ); // keys start from 0 now in $categories
					foreach ( $categories as $i => $cat ) {
						$res[$name]['grpUserText'][$i] = trim( $cat ); // category for group $i
					}
				}
			}
		}
		return $res;
	}

	/**
	 * Get a block for this user if they are blocked from requesting accounts
	 * @param User $user
	 * @return Block|null
	 */
	public static function getAccountRequestBlock( User $user ) {
		global $wgAccountRequestWhileBlocked;

		$block = false;
		# If a user cannot make accounts, don't let them request them either
		if ( !$wgAccountRequestWhileBlocked ) {
			$block = $user->isBlockedFromCreateAccount();
		}

		return $block;
	}

	/**
	 * @return UserArray
	 */
	public static function getAdminsToNotify() {
		$groups = User::getGroupsWithPermission( 'confirmaccount-notify' );
		if ( !count( $groups ) ) {
			return UserArray::newFromResult( new FakeResultWrapper( [] ) );
		}

		$dbr = wfGetDB( DB_REPLICA );

		return UserArray::newFromResult( $dbr->select(
			[ 'user' ],
			[ '*' ],
			[ 'EXISTS (' .
				$dbr->selectSqlText( 'user_groups', '1',
					[ 'ug_user = user_id', 'ug_group' => $groups ] ) .
				')' ],
			__METHOD__,
			[ 'LIMIT' => 200 ] // sanity
		) );
	}

	/**
	 * @param array $info
	 * @return FileRepo
	 */
	public static function getFileRepo( $info ) {
		$repoName = $info['name'];
		$directory = $info['directory'];
		if ( method_exists( MediaWikiServices::class, 'getLockManagerGroupFactory' ) ) {
			// MediaWiki 1.34+
			$lockManagerGroup = MediaWikiServices::getInstance()->getLockManagerGroupFactory()
				->getLockManagerGroup( wfWikiID() );
		} else {
			$lockManagerGroup = LockManagerGroup::singleton( wfWikiID() );
		}
		$info['backend'] = new FSFileBackend( [
				'name' => $repoName . '-backend',
				'wikiId' => wfWikiID(),
				'lockManager' => $lockManagerGroup->get( 'fsLockManager' ),
				'containerPaths' => [
					"{$repoName}-public" => "{$directory}",
					"{$repoName}-temp" => "{$directory}/temp",
					"{$repoName}-thumb" => "{$directory}/thumb",
				],
				'fileMode' => 0644,
				'tmpDirectory' => wfTempDir()
			]
		);
		return new FileRepo( $info );
	}

	/**
	 * Initiate user auto approval process
	 *
	 * @param $name
	 *
	 * @throws ErrorPageError
	 */
	public static function autoApproveRequest( $name ) {
		// We did pass all the checks we need before, so now it's ok to
		// just proceed with user creation directly
		$request = UserAccountRequest::newFromName( $name, 'dbmaster' );
		$user = User::newFromName( $request->getName() );
		$user->setEmail( $request->getEmail() );
		$user->setRealName( $request->getRealName() );
		$user->addToDatabase();
		$password = self::setRandomPasswordForUser( $user );
		if ( $password === false ) {
			throw new ErrorPageError( 'createacct-error', new RawMessage( 'createaccount-error-password' ) );
		}
		$user->saveSettings();
		$confirmationParams = [
			'userName' => $request->getName(),
			'action' => 'complete',
			'reason' => '',
			'bio' => $request->getBio(),
			'type' => $request->getType(),
			'areas' => $request->getAreas(),
			'allowComplete' => true
		];
		$submission = new AccountConfirmSubmission(
			User::newFromId(1), // TODO: change this to not to set to admin by default
			$request,
			$confirmationParams
		);
		# Update the queue to reflect approval of this user
		// @TODO: actually, we can't properly handle if the below will fail
		// but we also can't call it earlier because it requires user to be already
		// preset in the database
		list( $status, $msg ) = $submission->submit( RequestContext::getMain() );
		if ( $status !== true ) {
			// ErrorPageError does not trigger rollback
			$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
			$lbFactory->rollbackMasterChanges( __METHOD__ );
			throw new ErrorPageError( 'createacct-error', new RawMessage( $msg ) );
		}
		// CreateAccount stuff is completed, send user password via email
		self::sendNewAccountEmail( $user, $password );
	}

	/**
	 * Set the password on a user
	 *
	 * This just sets the password in the database directly.
	 *
	 * @param User $user
	 *
	 * @return false|string
	 * @throws MWException
	 * @throws PasswordError
	 */
	public static function setRandomPasswordForUser( User $user ) {
		if ( !$user->getId() ) {
			throw new MWException( "Passed User has not been added to the database yet!" );
		}

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow(
			'user',
			[ 'user_password' ],
			[ 'user_id' => $user->getId() ],
			__METHOD__
		);

		if ( !$row ) {
			throw new MWException( "Passed User has an ID but is not in the database?" );
		}

		$passwordFactory = MediaWikiServices::getInstance()->getPasswordFactory();
		$password = PasswordFactory::generateRandomPasswordString();
		if ( !$passwordFactory->newFromCiphertext( $row->user_password )->verify( $password ) ) {
			$passwordHash = $passwordFactory->newFromPlaintext( $password );
			$dbw->update(
				'user',
				[ 'user_password' => $passwordHash->toString() ],
				[ 'user_id' => $user->getId() ],
				__METHOD__
			);
			return $password;
		}

		return false;
	}

	public static function getListOfPrefixes() {
		return [
			'DR' => 'Dr.',
			'MR' => 'Mr.',
			'MS' => 'Ms.',
			'SR' => 'Sir.'
		];
	}

	/**
	 * @return string[]
	 */
	public static function getListOfCountries() {
		return [
			"AF" => "Afghanistan",
			"AL" => "Albania",
			"DZ" => "Algeria",
			"AS" => "American Samoa",
			"AD" => "Andorra",
			"AO" => "Angola",
			"AI" => "Anguilla",
			"AQ" => "Antarctica",
			"AG" => "Antigua and Barbuda",
			"AR" => "Argentina",
			"AM" => "Armenia",
			"AW" => "Aruba",
			"AU" => "Australia",
			"AT" => "Austria",
			"AZ" => "Azerbaijan",
			"BS" => "Bahamas",
			"BH" => "Bahrain",
			"BD" => "Bangladesh",
			"BB" => "Barbados",
			"BY" => "Belarus",
			"BE" => "Belgium",
			"BZ" => "Belize",
			"BJ" => "Benin",
			"BM" => "Bermuda",
			"BT" => "Bhutan",
			"BO" => "Bolivia",
			"BA" => "Bosnia and Herzegovina",
			"BW" => "Botswana",
			"BV" => "Bouvet Island",
			"BR" => "Brazil",
			"IO" => "British Indian Ocean Territory",
			"BN" => "Brunei Darussalam",
			"BG" => "Bulgaria",
			"BF" => "Burkina Faso",
			"BI" => "Burundi",
			"KH" => "Cambodia",
			"CM" => "Cameroon",
			"CA" => "Canada",
			"CV" => "Cape Verde",
			"KY" => "Cayman Islands",
			"CF" => "Central African Republic",
			"TD" => "Chad",
			"CL" => "Chile",
			"CN" => "China",
			"CX" => "Christmas Island",
			"CC" => "Cocos (Keeling) Islands",
			"CO" => "Colombia",
			"KM" => "Comoros",
			"CG" => "Congo",
			"CD" => "Congo, the Democratic Republic of the",
			"CK" => "Cook Islands",
			"CR" => "Costa Rica",
			"CI" => "Cote D'Ivoire",
			"HR" => "Croatia",
			"CU" => "Cuba",
			"CY" => "Cyprus",
			"CZ" => "Czech Republic",
			"DK" => "Denmark",
			"DJ" => "Djibouti",
			"DM" => "Dominica",
			"DO" => "Dominican Republic",
			"EC" => "Ecuador",
			"EG" => "Egypt",
			"SV" => "El Salvador",
			"GQ" => "Equatorial Guinea",
			"ER" => "Eritrea",
			"EE" => "Estonia",
			"ET" => "Ethiopia",
			"FK" => "Falkland Islands (Malvinas)",
			"FO" => "Faroe Islands",
			"FJ" => "Fiji",
			"FI" => "Finland",
			"FR" => "France",
			"GF" => "French Guiana",
			"PF" => "French Polynesia",
			"TF" => "French Southern Territories",
			"GA" => "Gabon",
			"GM" => "Gambia",
			"GE" => "Georgia",
			"DE" => "Germany",
			"GH" => "Ghana",
			"GI" => "Gibraltar",
			"GR" => "Greece",
			"GL" => "Greenland",
			"GD" => "Grenada",
			"GP" => "Guadeloupe",
			"GU" => "Guam",
			"GT" => "Guatemala",
			"GN" => "Guinea",
			"GW" => "Guinea-Bissau",
			"GY" => "Guyana",
			"HT" => "Haiti",
			"HM" => "Heard Island and Mcdonald Islands",
			"VA" => "Holy See (Vatican City State)",
			"HN" => "Honduras",
			"HK" => "Hong Kong",
			"HU" => "Hungary",
			"IS" => "Iceland",
			"IN" => "India",
			"ID" => "Indonesia",
			"IR" => "Iran, Islamic Republic of",
			"IQ" => "Iraq",
			"IE" => "Ireland",
			"IL" => "Israel",
			"IT" => "Italy",
			"JM" => "Jamaica",
			"JP" => "Japan",
			"JO" => "Jordan",
			"KZ" => "Kazakhstan",
			"KE" => "Kenya",
			"KI" => "Kiribati",
			"KP" => "Korea, Democratic People's Republic of",
			"KR" => "Korea, Republic of",
			"KW" => "Kuwait",
			"KG" => "Kyrgyzstan",
			"LA" => "Lao People's Democratic Republic",
			"LV" => "Latvia",
			"LB" => "Lebanon",
			"LS" => "Lesotho",
			"LR" => "Liberia",
			"LY" => "Libyan Arab Jamahiriya",
			"LI" => "Liechtenstein",
			"LT" => "Lithuania",
			"LU" => "Luxembourg",
			"MO" => "Macao",
			"MK" => "Macedonia, the Former Yugoslav Republic of",
			"MG" => "Madagascar",
			"MW" => "Malawi",
			"MY" => "Malaysia",
			"MV" => "Maldives",
			"ML" => "Mali",
			"MT" => "Malta",
			"MH" => "Marshall Islands",
			"MQ" => "Martinique",
			"MR" => "Mauritania",
			"MU" => "Mauritius",
			"YT" => "Mayotte",
			"MX" => "Mexico",
			"FM" => "Micronesia, Federated States of",
			"MD" => "Moldova, Republic of",
			"MC" => "Monaco",
			"MN" => "Mongolia",
			"MS" => "Montserrat",
			"MA" => "Morocco",
			"MZ" => "Mozambique",
			"MM" => "Myanmar",
			"NA" => "Namibia",
			"NR" => "Nauru",
			"NP" => "Nepal",
			"NL" => "Netherlands",
			"AN" => "Netherlands Antilles",
			"NC" => "New Caledonia",
			"NZ" => "New Zealand",
			"NI" => "Nicaragua",
			"NE" => "Niger",
			"NG" => "Nigeria",
			"NU" => "Niue",
			"NF" => "Norfolk Island",
			"MP" => "Northern Mariana Islands",
			"NO" => "Norway",
			"OM" => "Oman",
			"PK" => "Pakistan",
			"PW" => "Palau",
			"PS" => "Palestinian Territory, Occupied",
			"PA" => "Panama",
			"PG" => "Papua New Guinea",
			"PY" => "Paraguay",
			"PE" => "Peru",
			"PH" => "Philippines",
			"PN" => "Pitcairn",
			"PL" => "Poland",
			"PT" => "Portugal",
			"PR" => "Puerto Rico",
			"QA" => "Qatar",
			"RE" => "Reunion",
			"RO" => "Romania",
			"RU" => "Russian Federation",
			"RW" => "Rwanda",
			"SH" => "Saint Helena",
			"KN" => "Saint Kitts and Nevis",
			"LC" => "Saint Lucia",
			"PM" => "Saint Pierre and Miquelon",
			"VC" => "Saint Vincent and the Grenadines",
			"WS" => "Samoa",
			"SM" => "San Marino",
			"ST" => "Sao Tome and Principe",
			"SA" => "Saudi Arabia",
			"SN" => "Senegal",
			"CS" => "Serbia and Montenegro",
			"SC" => "Seychelles",
			"SL" => "Sierra Leone",
			"SG" => "Singapore",
			"SK" => "Slovakia",
			"SI" => "Slovenia",
			"SB" => "Solomon Islands",
			"SO" => "Somalia",
			"ZA" => "South Africa",
			"GS" => "South Georgia and the South Sandwich Islands",
			"ES" => "Spain",
			"LK" => "Sri Lanka",
			"SD" => "Sudan",
			"SR" => "Suriname",
			"SJ" => "Svalbard and Jan Mayen",
			"SZ" => "Swaziland",
			"SE" => "Sweden",
			"CH" => "Switzerland",
			"SY" => "Syrian Arab Republic",
			"TW" => "Taiwan, Province of China",
			"TJ" => "Tajikistan",
			"TZ" => "Tanzania, United Republic of",
			"TH" => "Thailand",
			"TL" => "Timor-Leste",
			"TG" => "Togo",
			"TK" => "Tokelau",
			"TO" => "Tonga",
			"TT" => "Trinidad and Tobago",
			"TN" => "Tunisia",
			"TR" => "Turkey",
			"TM" => "Turkmenistan",
			"TC" => "Turks and Caicos Islands",
			"TV" => "Tuvalu",
			"UG" => "Uganda",
			"UA" => "Ukraine",
			"AE" => "United Arab Emirates",
			"GB" => "United Kingdom",
			"US" => "United States",
			"UM" => "United States Minor Outlying Islands",
			"UY" => "Uruguay",
			"UZ" => "Uzbekistan",
			"VU" => "Vanuatu",
			"VE" => "Venezuela",
			"VN" => "Viet Nam",
			"VG" => "Virgin Islands, British",
			"VI" => "Virgin Islands, U.s.",
			"WF" => "Wallis and Futuna",
			"EH" => "Western Sahara",
			"YE" => "Yemen",
			"ZM" => "Zambia",
			"ZW" => "Zimbabwe"
		];
	}

}
