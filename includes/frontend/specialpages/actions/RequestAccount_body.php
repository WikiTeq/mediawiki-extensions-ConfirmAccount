<?php

use MediaWiki\Auth\TemporaryPasswordPrimaryAuthenticationProvider;
use MediaWiki\Extensions\Mailchimp\Mailchimp;
use MediaWiki\MediaWikiServices;

class RequestAccountPage extends SpecialPage {
	protected $mUsername; // string
	protected $mRealName; // string
	protected $mEmail; // string
	protected $mBio; // string
	protected $mPrefix; // string
	protected $mFirstName; // string
	protected $mLastName; // string
	protected $mTitle; // string
	protected $mCompany; // string
	protected $mCity; // string
	protected $mState; // string
	protected $mCountry; // string
	protected $mReceiveEmails; // string
	protected $mReceiveNewsletter; // string
	protected $mNotes; // string
	protected $mUrls; // string
	protected $mToS; // bool
	protected $mType; // integer
	/** @var array */
	protected $mAreas;

	protected $mPrevAttachment; // string
	protected $mForgotAttachment; // bool
	protected $mSrcName; // string
	protected $mFileSize; // integer
	protected $mTempPath; // string

	function __construct() {
		parent::__construct( 'RequestAccount' );
	}

	public function doesWrites() {
		return true;
	}

	function execute( $par ) {
		global $wgAccountRequestTypes, $wgConfirmAccountDefaultCountry;

		$reqUser = $this->getUser();
		$request = $this->getRequest();

		$this->getOutput()->addModules( 'ext.requestAccount' );

		$block = ConfirmAccount::getAccountRequestBlock( $reqUser );
		if ( $block ) {
			throw new UserBlockedError( $block );
		}

		$this->checkReadOnly();

		$this->setHeaders();

		$this->mRealName = trim( $request->getText( 'wpRealName' ) );
		# We may only want real names being used
		$this->mUsername = !$this->hasItem( 'UserName' )
			? $this->mRealName
			: $request->getText( 'wpUsername' );
		$this->mUsername = trim( $this->mUsername );
		# CV/resume attachment...
		if ( $this->hasItem( 'CV' ) ) {
			$this->initializeUpload( $request );
			$this->mPrevAttachment = $request->getText( 'attachment' );
			$this->mForgotAttachment = $request->getBool( 'forgotAttachment' );
		}
		# Other identifying fields...
		$this->mEmail = trim( $request->getText( 'wpEmail' ) );
		$this->mBio = $this->hasItem( 'Biography' ) ? $request->getText( 'wpBio', '' ) : '';
		$this->mCompany = $this->hasItem( 'Company' ) ? $request->getText( 'wpCompany', '' ) : '';

		$this->mCountry = $this->hasItem( 'Country' ) ? $request->getText( 'wpCountry', $wgConfirmAccountDefaultCountry ) : '';
		$this->mCity = $this->hasItem( 'City' ) ? $request->getText( 'wpCity', '' ) : '';
		$this->mState = $this->hasItem( 'State' ) ? $request->getText( 'wpState', '' ) : '';
		$this->mPrefix = $this->hasItem( 'Prefix' ) ? $request->getText( 'wpPrefix', '' ) : '';
		$this->mTitle = $this->hasItem( 'Title' ) ? $request->getText( 'wpTitle', '' ) : '';
		$this->mFirstName = $this->hasItem( 'FirstName' ) ? $request->getText( 'wpFirstName', '' ) : '';
		$this->mLastName = $this->hasItem( 'LastName' ) ? $request->getText( 'wpLastName', '' ) : '';

		$this->mReceiveEmails = $this->hasItem( 'ReceiveEmails' ) ? $request->getBool( 'wpReceiveEmails' ) : false;
		$this->mReceiveNewsletter = $this->hasItem( 'ReceiveNewsletter' ) ? $request->getBool( 'wpReceiveNewsletter' ) : false;
		$this->mCompany = $this->hasItem( 'Company' ) ? $request->getText( 'wpCompany', '' ) : '';
		$this->mNotes = $this->hasItem( 'Notes' ) ? $request->getText( 'wpNotes', '' ) : '';
		$this->mUrls = $this->hasItem( 'Links' ) ? $request->getText( 'wpUrls', '' ) : '';
		# Site terms of service...
		$this->mToS = $this->hasItem( 'TermsOfService' ) ? $request->getBool( 'wpToS' ) : false;
		# Which account request queue this belongs in...
		$this->mType = $request->getInt( 'wpType' );
		$this->mType = isset( $wgAccountRequestTypes[$this->mType] ) ? $this->mType : 0;
		# Load areas user plans to be active in...
		$this->mAreas = [];
		if ( $this->hasItem( 'AreasOfInterest' ) ) {
			foreach ( ConfirmAccount::getUserAreaConfig() as $name => $conf ) {
				$formName = "wpArea-" . htmlspecialchars( str_replace( ' ', '_', $name ) );
				$this->mAreas[$name] = $request->getInt( $formName, -1 );
			}
		}
		# We may be confirming an email address here
		$emailCode = $request->getText( 'wpEmailToken' );

		$action = $request->getVal( 'action' );
		if ( $request->wasPosted()
			&& $reqUser->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			$this->mPrevAttachment = $this->mPrevAttachment
				? $this->mPrevAttachment
				: $this->mSrcName;
			$this->doSubmit();
		} elseif ( $action == 'confirmemail' ) {
			$this->confirmEmailToken( $emailCode );
		} else {
			$this->showForm();
		}

		$this->getOutput()->addModules( 'ext.confirmAccount' ); // CSS
	}

	protected function showForm( $msg = '', $forgotFile = 0 ) {
		global $wgAccountRequestTypes, $wgMakeUserPageFromBio;

		$reqUser = $this->getUser();

		$this->mForgotAttachment = $forgotFile;

		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( "requestaccount" )->escaped() );
		# Output failure message if any
		if ( $msg ) {
			$out->addHTML( '<div class="errorbox">' . $msg . '</div><div class="visualClear"></div>' );
		}
		# Give notice to users that are logged in
		if ( $reqUser->getID() ) {
			$out->addWikiMsg( 'requestaccount-dup' );
		}

		$out->addWikiMsg( 'requestaccount-text' );

		$form = Xml::openElement( 'form', [ 'method' => 'post', 'name' => 'accountrequest',
			'action' => $this->getPageTitle()->getLocalUrl(), 'enctype' => 'multipart/form-data' ] );

		$form .= '<fieldset><legend>' . $this->msg( 'requestaccount-leg-user' )->escaped() . '</legend>';
		$form .= $this->msg( 'requestaccount-acc-text' )->parseAsBlock() . "\n";
		$form .= '<table style="padding:4px;">';
		if ( $this->hasItem( 'UserName' ) ) {
			$form .= "<tr><td>"
					 . $this->createLabel( 'wpUsername', 'username', $this->isRequired( 'Username' ) )
					 . "</td>";
			$form .= "<td>"
					 . $this->createField( 'wpUsername', 'input', $this->mUsername, [], $this->isRequired( 'Username' ) )
					 . "</td></tr>\n";
		} else {
			$form .= "<tr><td>" . $this->msg( 'username' )->escaped() . "</td>";
			$form .= "<td>" . $this->msg( 'requestaccount-same' )->escaped() . "</td></tr>\n";
		}
		$form .= "<tr><td>"
				 . $this->createLabel( 'wpEmail', 'requestaccount-email', $this->isRequired( 'Email' ) )
				 . "</td>";
		$form .= "<td>"
				 . $this->createField( 'wpEmail', 'input', $this->mEmail, [], $this->isRequired( 'Email' ) )
				 . "</td></tr>\n";
		if ( count( $wgAccountRequestTypes ) > 1 ) {
			$form .= "<tr><td>" . $this->msg( 'requestaccount-reqtype' )->escaped() . "</td><td>";
			$options = [];
			foreach ( $wgAccountRequestTypes as $i => $params ) {
				// Give grep a chance to find the usages: requestaccount-level-0, requestaccount-level-1
				$options[] = Xml::option(
					$this->msg( "requestaccount-level-$i" )->text(), $i, ( $i == $this->mType )
				);
			}
			$form .= Xml::openElement( 'select', [ 'name' => "wpType" ] );
			$form .= implode( "\n", $options );
			$form .= Xml::closeElement( 'select' ) . "\n";
			$form .= '</td></tr>';
		}

		if ( $this->hasItem( 'Prefix' ) ) {
			$form .= "<tr><td>"
					 . $this->createLabel( 'wpPrefix', 'requestaccount-prefix', $this->isRequired( 'Prefix' ) )
					 . "</td>";
			$form .= "<td>" .
					 $this->createField(
						 'wpPrefix',
						 'select',
						 $this->mPrefix,
						 [ 'id' => 'wpPrefix', 'options' => ConfirmAccount::getListOfPrefixes() ],
						 $this->isRequired( 'Prefix' )
					 ) . "</td></tr>\n";
		}

		if ( $this->hasItem( 'FirstName' ) ) {
			$form .= "<tr><td>"
					 . $this->createLabel( 'wpFirstName', 'requestaccount-firstname', $this->isRequired( 'FirstName' ) )
					 . "</td>";
			$form .= "<td>" .
					 $this->createField(
						 'wpFirstName',
						 'input',
						 $this->mFirstName,
						 [ 'id' => 'wpFirstName' ],
						 $this->isRequired( 'FirstName' )
					 ) . "</td></tr>\n";
		}

		if ( $this->hasItem( 'LastName' ) ) {
			$form .= "<tr><td>"
					 . $this->createLabel( 'wpLastName', 'requestaccount-lastname', $this->isRequired( 'LastName' ) )
					 . "</td>";
			$form .= "<td>" .
					 $this->createField(
						 'wpLastName',
						 'input',
						 $this->mLastName,
						 [ 'id' => 'wpLastName' ],
						 $this->isRequired( 'LastName' )
					 ) . "</td></tr>\n";
		}

		if ( $this->hasItem( 'Title' ) ) {
			$form .= "<tr><td>"
					 . $this->createLabel( 'wpTitle', 'requestaccount-title', $this->isRequired( 'Title' ) )
					 . "</td>";
			$form .= "<td>" .
					 $this->createField(
						 'wpTitle',
						 'input',
						 $this->mTitle,
						 [ 'id' => 'wpTitle' ],
						 $this->isRequired( 'Title' )
					 ) . "</td></tr>\n";
		}

		if ( $this->hasItem( 'Company' ) ) {
			$form .= "<tr><td>"
					 . $this->createLabel( 'wpCompany', 'requestaccount-company', $this->isRequired( 'Company' ) )
					 . "</td>";
			$form .= "<td>" .
					 $this->createField(
						 'wpCompany',
						 'input',
						 $this->mCompany,
						 [ 'id' => 'wpCompany' ],
						 $this->isRequired( 'Company' )
					 ) . "</td></tr>\n";
		}

		if ( $this->hasItem( 'City' ) ) {
			$form .= "<tr><td>"
					 . $this->createLabel( 'wpCity', 'requestaccount-city', $this->isRequired( 'City' ) )
					 . "</td>";
			$form .= "<td>" .
					 $this->createField(
						 'wpCity',
						 'input',
						 $this->mCity,
						 [ 'id' => 'wpCity' ],
						 $this->isRequired( 'City' )
					 ) . "</td></tr>\n";
		}

		if ( $this->hasItem( 'State' ) ) {
			$form .= "<tr><td>"
					 . $this->createLabel( 'wpState', 'requestaccount-state', $this->isRequired( 'State' ) )
					 . "</td>";
			$form .= "<td>" .
					 $this->createField(
						 'wpState',
						 'input',
						 $this->mState,
						 [ 'id' => 'wpState' ],
						 $this->isRequired( 'State' )
					 ) . "</td></tr>\n";
		}

		if ( $this->hasItem( 'Country' ) ) {
			$form .= "<tr><td>"
					 . $this->createLabel( 'wpCountry', 'requestaccount-country', $this->isRequired( 'Country' ) )
					 . "</td>";
			$form .= "<td>" .
					 $this->createField(
						 'wpCountry',
						 'select',
						 $this->mCountry,
						 [
						 	'id' => 'wpCountry',
							 'options' => ConfirmAccount::getListOfCountries()
						 ],
						 $this->isRequired( 'Country' )
					 ) . "</td></tr>\n";
		}

		$form .= '</table></fieldset>';

		$userAreas = ConfirmAccount::getUserAreaConfig();
		if ( $this->hasItem( 'AreasOfInterest' ) && count( $userAreas ) > 0 ) {
			$form .= '<fieldset>';
			$form .= '<legend>' . $this->msg( 'requestaccount-leg-areas' )->escaped() . '</legend>';
			$form .= $this->msg( 'requestaccount-areas-text' )->parseAsBlock() . "\n";

			$form .= "<div style='height:150px; overflow:scroll; background-color:#f9f9f9;'>";
			$form .= "<table style='border-spacing: 5px; padding: 0px; background-color: #f9f9f9;'>
			<tr valign='top'>";
			$count = 0;
			foreach ( $userAreas as $name => $conf ) {
				$count++;
				if ( $count > 5 ) {
					$form .= "</tr><tr style='vertical-align:top;'>";
					$count = 1;
				}
				$formName = "wpArea-" . htmlspecialchars( str_replace( ' ', '_', $name ) );
				if ( $conf['project'] != '' ) {
					$linkRenderer = $this->getLinkRenderer();
					$pg = $linkRenderer->makeLink(
						Title::newFromText( $conf['project'] ),
						$this->msg( 'requestaccount-info' )->text()
					);
				} else {
					$pg = '';
				}
				$form .= "<td>" .
					Xml::checkLabel( $name, $formName, $formName, $this->mAreas[$name] > 0 ) .
					" {$pg}</td>\n";
			}
			$form .= "</tr></table></div>";
			$form .= '</fieldset>';
		}

		if ( $this->hasItem( 'Biography' ) || $this->hasItem( 'RealName' ) ) {
			$form .= '<fieldset>';
			$form .= '<legend>' . $this->msg( 'requestaccount-leg-person' )->escaped() . '</legend>';
			if ( $this->hasItem( 'RealName' ) ) {
				$form .= '<table style="padding:4px;">';
				$form .= "<tr><td>"
						 . $this->createLabel( 'wpRealName', 'requestaccount-real', $this->isRequired( 'RealName' ) )
						 . "</td>";
				$form .= "<td>" . Xml::input(
					'wpRealName', 35, $this->mRealName, [ 'id' => 'wpRealName' ]
				) . "</td></tr>\n";
				$form .= '</table>';
			}
			if ( $this->hasItem( 'Biography' ) ) {
				if ( $wgMakeUserPageFromBio ) {
					$form .= $this->msg( 'requestaccount-bio-text-i' )->parseAsBlock() . "\n";
				}
				$form .= $this->msg( 'requestaccount-bio-text' )->parseAsBlock() . "\n";
				$form .= "<p>" . $this->msg( 'requestaccount-bio' )->parse() . "\n";
				$form .= "<textarea tabindex='1' name='wpBio' id='wpBio' rows='12' cols='80'
				style='width: 100%; background-color: #f9f9f9;'>" .
					htmlspecialchars( $this->mBio ) . "</textarea></p>\n";
			}
			$form .= '</fieldset>';
		}

		if ( $this->hasItem( 'CV' ) || $this->hasItem( 'Notes' ) || $this->hasItem( 'Links' ) ) {
			$form .= '<fieldset>';
			$form .= '<legend>' . $this->msg( 'requestaccount-leg-other' )->escaped() . '</legend>';
			$form .= $this->msg( 'requestaccount-ext-text' )->parseAsBlock() . "\n";
			if ( $this->hasItem( 'CV' ) ) {
				$form .= "<p>" . $this->msg( 'requestaccount-attach' )->escaped() . " ";
				$form .= Xml::input( 'wpUploadFile', 35, '',
					[ 'id' => 'wpUploadFile', 'type' => 'file' ] ) . "</p>\n";
			}
			if ( $this->hasItem( 'Notes' ) ) {
				$form .= "<p>"
						 . $this->createLabel( 'wpNotes', 'requestaccount-notes', $this->isRequired( 'Notes' ) )
						 . "\n";
				$form .= $this->createField(
					'wpNotes',
					'textarea',
					htmlspecialchars( $this->mNotes ),
					[
						'tabindex' => '1',
						'id' => 'wpNotes',
						'rows' => '3',
						'cols' => '80',
						'style' => 'width: 100%; background-color: #f9f9f9;'
					],
					$this->isRequired( 'Notes' )
				) . "</p>\n";
			}
			if ( $this->hasItem( 'Links' ) ) {
				$form .= "<p>"
						 . $this->createLabel( 'wpUrls', 'requestaccount-urls', $this->isRequired( 'Links' ) )
						 . "\n";
				$form .= $this->createField(
					'wpUrls',
					'textarea',
					htmlspecialchars( $this->mUrls ),
					[
						'tabindex' => '1',
						'id' => 'wpUrls',
						'rows' => '2',
						'cols' => '80',
						'style' => 'width: 100%; background-color: #f9f9f9;'
					],
					$this->isRequired( 'Links' )
				) . "</p>\n";
			}
			$form .= '</fieldset>';
		}

		if ( $this->hasItem( 'ReceiveEmails' ) || $this->hasItem( 'ReceiveEmails' ) ) {
			$form .= '<fieldset>';
			$form .= '<legend>' . $this->msg( 'requestaccount-leg-emails-options' )->escaped() . '</legend>';

			if( $this->hasItem( 'ReceiveEmails') ) {
				$form .= "<p>"
						 . $this->createField( 'wpReceiveEmails', 'check', $this->mReceiveEmails, [], $this->isRequired( 'ReceiveEmails' ) )
						 . $this->createLabel( 'wpReceiveEmails', 'requestaccount-receive-emails', $this->isRequired( 'ReceiveEmails' ) )
						 . "</p>\n";
			}

			if ( $this->hasItem( 'ReceiveNewsletter' ) ) {
				$form .= "<p>"
						 . $this->createField( 'wpReceiveNewsletter', 'check', $this->mReceiveNewsletter, [], $this->isRequired( 'ReceiveNewsletter' ) )
						 . $this->createLabel( 'wpReceiveNewsletter', 'requestaccount-receive-newsletter', $this->isRequired( 'ReceiveNewsletter' ) )
						 . "</p>\n";
			}

			$form .= '</fieldset>';
		}


		if ( $this->hasItem( 'TermsOfService' ) ) {
			$form .= '<fieldset>';
			$form .= '<legend>' . $this->msg( 'requestaccount-leg-tos' )->escaped() . '</legend>';
			$form .= "<p>"
					 . Xml::check( 'wpToS', $this->mToS, [ 'id' => 'wpToS', 'required' => 'yes' ] )
					 . $this->createLabel( 'wpToS', 'requestaccount-tos', $this->isRequired( 'TermsOfService' ) )
					 . "</p>\n";
			$form .= '</fieldset>';
		}

		# FIXME: do this better...
		global $wgConfirmAccountCaptchas, $wgCaptchaClass, $wgCaptchaTriggers;
		if ( $wgConfirmAccountCaptchas && isset( $wgCaptchaClass )
			&& $wgCaptchaTriggers['createaccount'] && !$reqUser->isAllowed( 'skipcaptcha' ) ) {
			/** @var SimpleCaptcha $captcha */
			$captcha = new $wgCaptchaClass;

			$formInformation = $captcha->getFormInformation();
			$formMetainfo = $formInformation;
			unset( $formMetainfo['html'] );
			$captcha->addFormInformationToOutput( $out, $formMetainfo );

			# Hook point to add captchas
			$form .= '<fieldset>';
			$form .= $this->msg( 'captcha-createaccount' )->parseAsBlock();
			$form .= $formInformation['html'];
			$form .= '</fieldset>';
		}
		$form .= Html::Hidden( 'title', $this->getPageTitle()->getPrefixedDBKey() ) . "\n";
		$form .= Html::Hidden( 'wpEditToken', $reqUser->getEditToken() ) . "\n";
		$form .= Html::Hidden( 'attachment', $this->mPrevAttachment ) . "\n";
		$form .= Html::Hidden( 'forgotAttachment', $this->mForgotAttachment ) . "\n";
		$form .= "<p>" . Xml::submitButton( $this->msg( 'requestaccount-submit' )->text() ) . "</p>";
		$form .= Xml::closeElement( 'form' );

		$out->addHTML( $form );

		$out->addWikiMsg( 'requestaccount-footer' );
	}

	protected function hasItem( $name ) {
		global $wgConfirmAccountRequestFormItems;

		return $wgConfirmAccountRequestFormItems[$name]['enabled'];
	}

	/**
	 * Tests if the field is marked as required (UI only)
	 *
	 * @param string $name
	 *
	 * @return bool
	 */
	protected function isRequired( $name ) {
		global $wgConfirmAccountRequestFormItemsRequired;

		// ToS is always required,
		// RealName being used as a fallback to UserName if the first
		// is omitted, so always set it as required
		$defaultRequired = [
			'TermsOfService' => true,
			'Username' => true,
			'Realname' => true,
			'Email' => true
		];

		$testRequired = array_merge( $wgConfirmAccountRequestFormItemsRequired, $defaultRequired );

		return isset($testRequired[$name])
			? $testRequired[$name] : false;
	}

	protected function createField( $name, $type, $value, $attribs, $required = false ) {
		if ( $required ) {
			$attribs['required'] = 'yes';
		}
		$attribs['id'] = $name;
		if ( $type == 'textarea' ) {
			return Xml::textarea( $name, $value, $attribs['cols'], $attribs['rows'], $attribs );
		}
		if ( $type == 'input' ) {
			return Xml::input( $name, false, $value, $attribs );
		}
		if ( $type == 'select' ) {
			$options = '';
			if( !$required ) {
				$options .= Html::rawElement('option', [], '');
			}
			foreach ( $attribs['options'] as $key => $v ) {
				$optAttribs = [ 'value' => $key ];
				if( $key == $value ) {
					$optAttribs['selected'] = 'selected';
				}
				$options .= Html::rawElement('option', $optAttribs, $v );
			}
			unset( $attribs['options'] );
			$attribs['name'] = $name;
			return Html::rawElement(
				'select',
				$attribs,
				$options
			);
		}
		return call_user_func( [ Xml::class, $type ], $name, $value, $attribs );
	}

	protected function createLabel( $id, $msg, $required = false ) {
		return '<label for="' . $id . '" class="'
			   . ( $required ? 'label-mandatory' : '' )
			   . '">' . $this->msg( $msg )->parse() . '</label>';
	}

	protected function doSubmit() {
		# Now create a dummy user ($u) and check if it is valid
		$name = trim( $this->mUsername );
		$u = User::newFromName( $name, 'creatable' );
		if ( !$u ) {
			$this->showForm( $this->msg( 'noname' )->escaped() );
			return;
		}
		# Set some additional data so the AbortNewAccount hook can be
		# used for more than just username validation
		$u->setEmail( $this->mEmail );
		$u->setRealName( $this->mRealName );
		# FIXME: Hack! If we don't want captchas for requests, temporarily turn it off!
		global $wgConfirmAccountCaptchas, $wgCaptchaTriggers;
		if ( !$wgConfirmAccountCaptchas && isset( $wgCaptchaTriggers ) ) {
			$old = $wgCaptchaTriggers['createaccount'];
			$wgCaptchaTriggers['createaccount'] = false;
		}
		$abortError = '';
		if ( !Hooks::run( 'AbortNewAccount', [ $u, &$abortError ] ) ) {
			// Hook point to add extra creation throttles and blocks
			wfDebug( "RequestAccount::doSubmit: a hook blocked creation\n" );
			$this->showForm( $abortError );
			return;
		}
		# Set it back!
		if ( !$wgConfirmAccountCaptchas && isset( $wgCaptchaTriggers ) ) {
			$wgCaptchaTriggers['createaccount'] = $old;
		}

		# Build submission object...
		$areaSet = []; // make a simple list of interests
		foreach ( $this->mAreas as $area => $val ) {
			if ( $val > 0 ) {
				$areaSet[] = $area;
			}
		}

		$submission = new AccountRequestSubmission(
			$this->getUser(),
			[
				'userName'                  => $name,
				'realName'                  => $this->mRealName,
				'tosAccepted'               => $this->mToS,
				'email'                     => $this->mEmail,
				'bio'                       => $this->mBio,
				'company'                   => $this->mCompany,
				'country'                   => $this->mCountry,
				'city'                   	=> $this->mCity,
				'state'                   	=> $this->mState,
				'prefix'                   	=> $this->mPrefix,
				'title'                   	=> $this->mTitle,
				'firstname'                 => $this->mFirstName,
				'lastname'                  => $this->mLastName,
				'receiveEmails'             => $this->mReceiveEmails,
				'receiveNewsletter'         => $this->mReceiveNewsletter,
				'notes'                     => $this->mNotes,
				'urls'                      => $this->mUrls,
				'type'                      => $this->mType,
				'areas'                     => $areaSet,
				'registration'              => wfTimestampNow(),
				'ip'                        => $this->getRequest()->getIP(),
				'xff'                       => $this->getRequest()->getHeader( 'X-Forwarded-For' ),
				'agent'                     => $this->getRequest()->getHeader( 'User-Agent' ),
				'attachmentPrevName'        => $this->mPrevAttachment,
				'attachmentSrcName'         => $this->mSrcName,
				'attachmentDidNotForget'    => $this->mForgotAttachment, // confusing name :)
				'attachmentSize'            => $this->mFileSize,
				'attachmentTempPath'        => $this->mTempPath
			]
		);

		# Actually submit!
		list( $status, $msg ) = $submission->submit( $this->getContext() );
		# Account for state changes
		$this->mForgotAttachment = $submission->getAttachmentDidNotForget();
		$this->mPrevAttachment = $submission->getAttachtmentPrevName();
		# Check for error messages
		if ( $status !== true ) {
			$this->showForm( $msg );
			return;
		}

		# Done!
		$this->showSuccess();
	}

	protected function showSuccess() {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( "requestaccount" )->escaped() );
		$out->addWikiMsg( 'requestaccount-sent' );
		$out->returnToMain();
	}

	/**
	 * Initialize the uploaded file from PHP data
	 * @param WebRequest $request
	 */
	protected function initializeUpload( $request ) {
		$file = new WebRequestUpload( $request, 'wpUploadFile' );
		$this->mTempPath = $file->getTempName();
		$this->mFileSize = $file->getSize();
		$this->mSrcName  = $file->getName();
	}

	/**
	 * (a) Try to confirm an email address via a token
	 * (b) Notify $wgConfirmAccountContact on success
	 * @param string $code The token
	 * @return void
	 */
	protected function confirmEmailToken( $code ) {
		global $wgConfirmAccountContact, $wgPasswordSender, $wgConfirmAccountApproveOnEmailConfirmation;

		$reqUser = $this->getUser();
		$out = $this->getOutput();
		# Confirm if this token is in the pending requests
		list( $bodyArguments, $name,
			$email_authenticated ) = ConfirmAccount::requestInfoFromEmailToken( $code );
		if ( $name && $email_authenticated === null ) {
			# Flag user as email confirmed in the account_requests table
			ConfirmAccount::confirmEmail( $name );
			# Send notification email to admins
			$adminsNotify = ConfirmAccount::getAdminsToNotify();
			$adminsNotify->rewind();
			# Send an email to admin after email has been confirmed
			if ( $adminsNotify->count() || $wgConfirmAccountContact != '' ) {
				$title = SpecialPage::getTitleFor( 'ConfirmAccounts' );
				$subject = $this->msg(
					'requestaccount-email-subj-admin' )->inContentLanguage()->escaped();
				$body = $this->msg(
					'requestaccount-email-body-admin', $name, $title->getCanonicalURL(),
					...$bodyArguments )->inContentLanguage()->text();
				# Actually send the email...
				if ( $wgConfirmAccountContact != '' ) {
					$source = new MailAddress( $wgPasswordSender, wfMessage( 'emailsender' )->text() );
					$target = new MailAddress( $wgConfirmAccountContact );
					$result = UserMailer::send( $target, $source, $subject, $body );
					if ( !$result->isOK() ) {
						wfDebug( "Could not sent email to admin at $target\n" );
					}
				}
				# Send an email to all users with "confirmaccount-notify" rights
				foreach ( $adminsNotify as $adminNotify ) {
					if ( $adminNotify->canReceiveEmail() ) {
						$adminNotify->sendMail( $subject, $body );
					}
				}
			}

			# Auto approve
			if( $wgConfirmAccountApproveOnEmailConfirmation ) {
				ConfirmAccount::autoApproveRequest( $name );
				$this->getOutput()->addWikiMsg('confirmaccount-auto-created');
				$this->getOutput()->addReturnTo(
					Title::newFromText( 'UserLogin', NS_SPECIAL )
				);
			}else{
				$out->addWikiMsg( 'requestaccount-econf' );
				$out->returnToMain();
			}
		} else {
			# Maybe the user confirmed after account was created...
			$user = User::newFromConfirmationCode( $code, User::READ_LATEST );
			if ( is_object( $user ) ) {
				$user->confirmEmail();
				$user->saveSettings();
				$message = $reqUser->isLoggedIn()
					? 'confirmemail_loggedin'
					: 'confirmemail_success';
				$out->addWikiMsg( $message );
				if ( !$reqUser->isLoggedIn() ) {
					$title = SpecialPage::getTitleFor( 'Userlogin' );
					$out->returnToMain( true, $title );
				}
			} else {
				$out->addWikiMsg( 'confirmemail_invalid' );
			}
		}
	}

	protected function getGroupName() {
		return 'login';
	}
}
