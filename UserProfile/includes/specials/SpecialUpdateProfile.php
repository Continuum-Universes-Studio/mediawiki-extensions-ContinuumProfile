<?php

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use function Eris\Generator\int;

/**
 * A special page to allow users to update their social profile
 *
 * @file
 * @ingroup Extensions
 * @author David Pean <david.pean@gmail.com>
 * @copyright Copyright © 2007, Wikia Inc.
 * @license GPL-2.0-or-later
 */

class SpecialUpdateProfile extends UnlistedSpecialPage {

	public function __construct() {
		parent::__construct( 'UpdateProfile' );
	}

	/**
	 * Initialize the user_profile records for a given user (either the current
	 * user or someone else).
	 *
	 * @param UserIdentity|null $user User object; null by default (=current user)
	 */
	function initProfile( $user = null ) {
		if ( $user === null ) {
			$user = $this->getUser();
		}

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$s = $dbw->selectRow(
			'user_profile',
			[ 'up_actor' ],
			[ 'up_actor' => $user->getActorId() ],
			__METHOD__
		);
		if ( $s === false ) {
			$dbw->insert(
				'user_profile',
				[ 'up_actor' => $user->getActorId() ],
				__METHOD__
			);
		}
	}

	/**
	 * Show the special page
	 *
	 * @param string|null $section
	 */
	public function execute( $section ) {
		global $wgUpdateProfileInRecentChanges, $wgUserProfileThresholds, $wgAutoConfirmCount, $wgEmailConfirmToEdit;

		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		// This feature is only available for logged-in users.
		$this->requireLogin();

		// Database operations require write mode
		$this->checkReadOnly();

		// No need to allow blocked users to access this page, they could abuse it, y'know.
		$block = $user->getBlock();
		if ( $block ) {
			throw new UserBlockedError( $block );
		}

		// Set the page title, robot policies, etc.
		$this->setHeaders();
		$out->setHTMLTitle( $this->msg( 'pagetitle', $this->msg( 'edit-profile-title' ) ) );

		/**
		 * Create thresholds based on user stats
		 */
		if ( is_array( $wgUserProfileThresholds ) && count( $wgUserProfileThresholds ) > 0 ) {
			$can_create = true;

			$stats = new UserStats( $user->getId(), $user->getName() );
			$stats_data = $stats->getUserStats();

			$thresholdReasons = [];
			foreach ( $wgUserProfileThresholds as $field => $threshold ) {
				// If the threshold is greater than the user's amount of whatever
				// statistic we're looking at, then it means that they can't use
				// this special page.
				// Why, oh why did I want to be so fucking smart with these
				// field names?! This str_replace() voodoo all over the place is
				// outright painful.
				$correctField = str_replace( '-', '_', $field );
				if ( $stats_data[$correctField] < $threshold ) {
					$can_create = false;
					$thresholdReasons[$threshold] = $field;
				}
			}

			$hasEqualEditThreshold = isset( $wgUserProfileThresholds['edit'] ) && $wgUserProfileThresholds['edit'] == $wgAutoConfirmCount;
			$can_create = ( $user->isAllowed( 'createpage' ) && $hasEqualEditThreshold ) ? true : $can_create;

			// Ensure we enforce profile creation exclusively to members who confirmed their email
			if ( $user->getEmailAuthenticationTimestamp() === null && $wgEmailConfirmToEdit === true ) {
				$can_create = false;
			}

			// Boo, go away!
			if ( !$can_create ) {
				$out->setPageTitle( $this->msg( 'user-profile-create-threshold-title' )->text() );
				$thresholdMessages = [];
				foreach ( $thresholdReasons as $requiredAmount => $reason ) {
					// Replace underscores with hyphens for consistency in i18n
					// message names.
					$reason = str_replace( '_', '-', $reason );
					/**
					 * For grep:
					 * user-profile-create-threshold-edits
					 * user-profile-create-threshold-votes
					 * user-profile-create-threshold-comments
					 * user-profile-create-threshold-comment-score-plus
					 * user-profile-create-threshold-comment-score-minus
					 * user-profile-create-threshold-recruits
					 * user-profile-create-threshold-friend-count
					 * user-profile-create-threshold-foe-count
					 * user-profile-create-threshold-weekly-wins
					 * user-profile-create-threshold-monthly-wins
					 * user-profile-create-threshold-only-confirmed-email
					 * user-profile-create-threshold-poll-votes
					 * user-profile-create-threshold-picture-game-votes
					 * user-profile-create-threshold-quiz-created
					 * user-profile-create-threshold-quiz-answered
					 * user-profile-create-threshold-quiz-correct
					 * user-profile-create-threshold-quiz-points
					 */
					$thresholdMessages[] = $this->msg( 'user-profile-create-threshold-' . $reason )->numParams( $requiredAmount )->parse();
				}
				// Set a useful message of why.
				if ( $user->getEmailAuthenticationTimestamp() === null && $wgEmailConfirmToEdit === true ) {
					$thresholdMessages[] = $this->msg( 'user-profile-create-threshold-only-confirmed-email' )->text();
				}
				$out->addHTML(
					$this->msg( 'user-profile-create-threshold-reason',
						$this->getLanguage()->commaList( $thresholdMessages )
					)->parse()
				);
				return;
			}
		}

		// Add CSS & JS
		$out->addModuleStyles( [
			'ext.socialprofile.clearfix',
			'ext.socialprofile.userprofile.tabs.css',
			'ext.socialprofile.special.updateprofile.css'
		] );
		$out->addModules( 'ext.userProfile.updateProfile' );

		if ( $request->wasPosted() && $user->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
			// Save field visibilities if any were posted, regardless of the JS flag
			$postedVis = $this->getPostedFieldVisibilities();
			if ( !empty( $postedVis ) ) {
				foreach ( $postedVis as $fieldKey => $visibility ) {
					$normalized = $this->normalizePrivacyValue( $visibility, 'public' );

					if ( class_exists('SPUserSecurity') && method_exists('SPUserSecurity','setPrivacy') ) {
						SPUserSecurity::setPrivacy( $user, $fieldKey, $normalized );
					} else {
						// Fallback: write directly to user_fields_privacy
						$this->savePrivacyDirect( $user->getId(), $fieldKey, $normalized );
					}
				}
			}


			if ( !$section ) {
				$section = 'basic';
			}
			switch ( $section ) {
				case 'basic':
					$this->saveProfileBasic( $user );
					$this->saveBasicSettings( $user );
					break;
				case 'personal':
					$this->saveProfilePersonal( $user );
					break;
				case 'custom':
					$this->saveProfileCustom( $user );
					break;
				case 'preferences':
					$this->saveSocialPreferences();
					break;
			}

			UserProfile::clearCache( $user );

			$log = new LogPage( 'profile' );
			if ( !$wgUpdateProfileInRecentChanges ) {
				$log->updateRecentChanges = false;
			}
			$log->addEntry(
				'profile',
				$user->getUserPage(),
				$this->msg( 'user-profile-update-log-section' )
					->inContentLanguage()->text() .
					" '{$section}'",
				[],
				$user
			);
			$out->addHTML(
				'<span class="profile-on">' .
				$this->msg( 'user-profile-update-saved' )->escaped() .
				'</span><br /><br />'
			);

			// create the user page if it doesn't exist yet
			$title = Title::makeTitle( NS_USER, $user->getName() );
			if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
				// MW 1.36+
				$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			} else {
				// @phan-suppress-next-line PhanUndeclaredStaticMethod
				$page = WikiPage::factory( $title );
			}
			if ( !$page->exists() ) {
				if ( method_exists( $page, 'doUserEditContent' ) ) {
					// MW 1.36+
					$page->doUserEditContent(
						ContentHandler::makeContent( '', $title ),
						$this->getUser(),
						'create user page',
						EDIT_SUPPRESS_RC
					);
				} else {
					// @phan-suppress-next-line PhanUndeclaredMethod Removed in MW 1.41
					$page->doEditContent(
						ContentHandler::makeContent( '', $title ),
						'create user page',
						EDIT_SUPPRESS_RC
					);
				}
			}
		}

		if ( !$section ) {
			$section = 'basic';
		}
		switch ( $section ) {
			case 'basic':
				$out->addHTML( $this->displayBasicForm( $user ) );
				break;
			case 'personal':
				$out->addHTML( $this->displayPersonalForm( $user ) );
				break;
			case 'custom':
				$out->addHTML( $this->displayCustomForm( $user ) );
				break;
			case 'preferences':
				$out->addHTML( $this->displayPreferencesForm() );
				break;
		}
	}

	/**
	 * Save basic settings about the user (real name, e-mail address) into the
	 * database.
	 *
	 * @param User $user Representing the current user
	 */
	function saveBasicSettings( $user ) {
		global $wgEmailAuthentication;

		$request = $this->getRequest();

		$user->setRealName( $request->getVal( 'real_name' ) );
		$user->setEmail( $request->getVal( 'email' ) );

		if ( $user->getEmail() != $request->getVal( 'email' ) ) {
			$user->mEmailAuthenticated = null; # but flag as "dirty" = unauthenticated
		}

		if ( $wgEmailAuthentication && !$user->isEmailConfirmed() && $user->getEmail() ) {
			# Mail a temporary password to the dirty address.
			# User can come back through the confirmation URL to re-enable email.
			$status = $user->sendConfirmationMail();
			if ( $status->isGood() ) {
				$this->getOutput()->addWikiMsg( 'confirmemail_sent' );
			} else {
				$this->getOutput()->addWikiTextAsInterface( $status->getWikiText( 'confirmemail_sendfailed' ) );
			}
		}
		$user->saveSettings();
	}

	/**
	 * Save social preferences into the database.
	 */
	function saveSocialPreferences() {
		$request = $this->getRequest();
		$user = $this->getUser();

		$notify_friend = $request->getInt( 'notify_friend', 0 );
		$notify_family = $request->getInt( 'notify_family', 0 );
		$notify_gift = $request->getInt( 'notify_gift', 0 );
		$notify_challenge = $request->getInt( 'notify_challenge', 0 );
		$notify_honorifics = $request->getInt( 'notify_honorifics', 0 );
		$notify_message = $request->getInt( 'notify_message', 0 );
		$show_year_of_birth = $request->getInt( 'show_year_of_birth', 0 );

		$userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
		$userOptionsManager->setOption( $user, 'notifygift', $notify_gift );
		$userOptionsManager->setOption( $user, 'notifyfriendrequest', $notify_friend );
		$userOptionsManager->setOption( $user, 'notifyfamilyrequest', $notify_family );
		$userOptionsManager->setOption( $user, 'notifychallenge', $notify_challenge );
		$userOptionsManager->setOption( $user, 'notifyhonorifics', $notify_honorifics );
		$userOptionsManager->setOption( $user, 'notifymessage', $notify_message );
		$userOptionsManager->setOption( $user, 'showyearofbirth', $show_year_of_birth );
		$userOptionsManager->saveOptions( $user );

		// Allow extensions like UserMailingList do their magic here
		$this->getHookContainer()->run( 'SpecialUpdateProfile::saveSettings_pref', [ $this, $request ] );
	}

	public static function formatBirthdayDB( $birthday ) {
		$dob = explode( '/', $birthday );
		if ( count( $dob ) == 2 || count( $dob ) == 3 ) {
			$year = $dob[2] ?? '00';
			$month = $dob[0];
			$day = $dob[1];
			$birthday_date = $year . '-' . $month . '-' . $day;
		} else {
			$birthday_date = null;
		}
		return $birthday_date;
	}

	public static function formatBirthday( $birthday, $showYOB = false ) {
		$dob = explode( '-', $birthday );
		if ( count( $dob ) == 3 ) {
			$month = $dob[1];
			$day = $dob[2];
			$birthday_date = $month . '/' . $day;
			if ( $showYOB ) {
				$year = $dob[0];
				$birthday_date .= '/' . $year;
			}
		} else {
			$birthday_date = '';
		}
		return $birthday_date;
	}

	/**
	 * Save the basic user profile info fields into the database.
	 *
	 * @param UserIdentity|null $user User object, null by default (=the current user)
	 */
	function saveProfileBasic( $user = null ) {
		if ( $user === null ) {
			$user = $this->getUser();
		}

		$this->initProfile( $user );
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$request = $this->getRequest();

		// As for why the rest of the fields are done below instead of here...that's got to do with T373265
		// tl,dr summary: we do NOT want to overwrite hidden/otherwise not-viewable-by-the-current-user
		// data when a privileged user (who is *not* allowed to view said user profile data, however) uses
		// Special:EditProfile (sic) to edit another user's profile
		$basicProfileData = [
			'up_birthday' => self::formatBirthdayDB( $request->getVal( 'birthday' ) ),
		];

		if ( $request->getVal( 'location_city' ) ) {
			$basicProfileData['up_location_city'] = $request->getVal( 'location_city' );
		}
		if ( $request->getVal( 'location_state' ) ) {
			$basicProfileData['up_location_state'] = $request->getVal( 'location_state' );
		}
		if ( $request->getVal( 'location_country' ) ) {
			$basicProfileData['up_location_country'] = $request->getVal( 'location_country' );
		}

		if ( $request->getVal( 'hometown_city' ) ) {
			$basicProfileData['up_hometown_city'] = $request->getVal( 'hometown_city' );
		}
		if ( $request->getVal( 'hometown_state' ) ) {
			$basicProfileData['up_hometown_state'] = $request->getVal( 'hometown_state' );
		}
		if ( $request->getVal( 'hometown_country' ) ) {
			$basicProfileData['up_hometown_country'] = $request->getVal( 'hometown_country' );
		}

		if ( $request->getVal( 'about' ) ) {
			$basicProfileData['up_about'] = $request->getVal( 'about' );
		}
		if ( $request->getVal( 'occupation' ) ) {
			$basicProfileData['up_occupation'] = $request->getVal( 'occupation' );
		}
		if ( $request->getVal( 'tagline' ) ) {
			$basicProfileData['up_tagline'] = $request->getVal( 'tagline' );
		}
		if ( $request->getVal( 'schools' ) ) {
			$basicProfileData['up_schools'] = $request->getVal( 'schools' );
		}
		if ( $request->getVal( 'places' ) ) {
			$basicProfileData['up_places_lived'] = $request->getVal( 'places' );
		}
		if ( $request->getVal( 'websites' ) ) {
			$basicProfileData['up_websites'] = $request->getVal( 'websites' );
		}
		if ( $request->getVal( 'universes' ) ) {
			$basicProfileData['up_universes'] = $request->getVal( 'universes' );
		}
		if ( $request->getVal( 'pets' ) ) {
			$basicProfileData['up_pets'] = $request->getVal( 'pets' );
		}
		if ( $request->getVal( 'hobbies' ) ) {
			$basicProfileData['up_hobbies'] = $request->getVal( 'hobbies' );
		}
		if ( $request->getVal( 'heroes' ) ) {
			$basicProfileData['up_heroes'] = $request->getVal( 'heroes' );
		}
		if ( $request->getVal( 'quote' ) ) {
			$basicProfileData['up_quote'] = $request->getVal( 'quote' );
		}
		if ($request->getVal('private_birthyear') !== null) {
			$basicProfileData['private_birthyear'] = intval($request->getVal('private_birthyear'));
		}


		$dbw->update(
			'user_profile',
			/* SET */$basicProfileData,
			/* WHERE */[ 'up_actor' => $user->getActorId() ],
			__METHOD__
		);

		// BasicProfileChanged hook
		$basicProfileData['up_name'] = $request->getVal( 'real_name' );
		$basicProfileData['up_email'] = $request->getVal( 'email' );
		$this->getHookContainer()->run( 'BasicProfileChanged', [ $user, $basicProfileData ] );
		// end of the hook

		UserProfile::clearCache( $user );
	}

	/**
	 * Save the four custom (site-specific) user profile fields into the
	 * database.
	 *
	 * @param UserIdentity|null $user
	 */
	function saveProfileCustom( $user = null ) {
		if ( $user === null ) {
			$user = $this->getUser();
		}

		$this->initProfile( $user );
		$request = $this->getRequest();

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$dbw->update(
			'user_profile',
			/* SET */[
				'up_custom_1' => $request->getVal( 'custom1' ),
				'up_custom_2' => $request->getVal( 'custom2' ),
				'up_custom_3' => $request->getVal( 'custom3' ),
				'up_custom_4' => $request->getVal( 'custom4' ),
				'up_custom_5' => $request->getVal( 'custom5' ),
			],
			/* WHERE */[ 'up_actor' => $user->getActorId() ],
			__METHOD__
		);

		UserProfile::clearCache( $user );
	}

	/**
	 * Save the user's personal info (interests, such as favorite music or
	 * TV programs or video games, etc.) into the database.
	 *
	 * @param UserIdentity|null $user
	 */
	function saveProfilePersonal( $user = null ) {
		if ( $user === null ) {
			$user = $this->getUser();
		}

		$this->initProfile( $user );
		$request = $this->getRequest();

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		$interestsData = [
			'up_companies' => $request->getVal( 'companies' ),
			'up_movies' => $request->getVal( 'movies' ),
			'up_music' => $request->getVal( 'music' ),
			'up_tv' => $request->getVal( 'tv' ),
			'up_books' => $request->getVal( 'books' ),
			'up_magazines' => $request->getVal( 'magazines' ),
			'up_video_games' => $request->getVal( 'videogames' ),
			'up_snacks' => $request->getVal( 'snacks' ),
			'up_drinks' => $request->getVal( 'drinks' ),
			'up_universes' => $request->getVal( 'universes' ),
			'up_pets' => $request->getVal( 'pets' ),
			'up_hobbies' => $request->getVal( 'hobbies' ),
			'up_heroes' => $request->getVal( 'heroes' )

		];

		$dbw->update(
			'user_profile',
			/* SET */$interestsData,
			/* WHERE */[ 'up_actor' => $user->getActorId() ],
			__METHOD__
		);

		// PersonalInterestsChanged hook
		$this->getHookContainer()->run( 'PersonalInterestsChanged', [ $user, $interestsData ] );
		// end of the hook

		UserProfile::clearCache( $user );
	}

	/**
	 * @param User $user
	 *
	 * @return string
	 */
	function displayBasicForm( $user ) {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$s = $dbr->selectRow( 'user_profile',
			[
				'up_location_city', 'up_location_state', 'up_location_country',
				'up_hometown_city', 'up_hometown_state', 'up_hometown_country',
				'up_birthday', 'up_occupation', 'up_tagline', 'up_about', 'up_schools',
				'up_places_lived', 'up_websites', 'private_birthyear', 'up_quote'
			],
			[ 'up_actor' => $user->getActorId() ],
			__METHOD__
		);

		$showYOB = true;
		if ( $s !== false ) {
			$location_city = $s->up_location_city;
			$location_state = $s->up_location_state;
			$location_country = $s->up_location_country;
			$about = $s->up_about;
			$occupation = $s->up_occupation;
			$tagline = $s->up_tagline ?? '';
			$hometown_city = $s->up_hometown_city;
			$hometown_state = $s->up_hometown_state;
			$hometown_country = $s->up_hometown_country;
			$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
			$showYOB = $userOptionsLookup->getIntOption( $user, 'showyearofbirth', (int)!isset( $s->up_birthday ) ) == 1;
			$birthday = self::formatBirthday( $s->up_birthday, $showYOB );
			$schools = $s->up_schools;
			$places = $s->up_places_lived;
			$websites = $s->up_websites;
			$quote = $s->up_quote;
		}

		if ( !isset( $location_country ) ) {
			$location_country = $this->msg( 'user-profile-default-country' )->inContentLanguage()->escaped();
		}
		if ( !isset( $hometown_country ) ) {
			$hometown_country = $this->msg( 'user-profile-default-country' )->inContentLanguage()->escaped();
		}

		$s = $dbr->selectRow(
			'user',
			[ 'user_real_name', 'user_email' ],
			[ 'user_id' => $user->getId() ],
			__METHOD__
		);

		$real_name = '';
		$email = '';
		if ( $s !== false ) {
			$real_name = $s->user_real_name;
			$email = $s->user_email;
		}

		$countries = explode( "\n*", $this->msg( 'userprofile-country-list' )->inContentLanguage()->text() );
		array_shift( $countries );

		$this->getOutput()->setPageTitle( $this->msg( 'edit-profile-title' )->escaped() );

		$form = UserProfile::getEditProfileNav( $this->msg( 'user-profile-section-personal' )->escaped() );
		$form .= '<form action="" method="post" enctype="multipart/form-data" name="profile">';
		// NoJS thing -- JS sets this to false, which means that in execute() we skip updating
		// profile field visibilities for users with JS enabled can do and have already done that
		// with the nice JS-enabled drop-down (instead of having to rely on a plain ol'
		// <select> + form submission, as no-JS users have to)
		$form .= Html::hidden( 'should_update_field_visibilities', true );
		$form .= '<div class="profile-info clearfix">';
		$form .= '<div class="profile-update">
			<p class="profile-update-title">' . $this->msg( 'user-profile-personal-info' )->escaped() . '</p>';
		$form .= $this->renderTextFieldRow( 'user-profile-personal-name', 'real_name', $real_name, 'text', 25 );
		$form .= $this->renderBigTextFieldRow( 'user-profile-personal-tagline', 'tagline', $tagline, 3, 25 );
		$form .= $this->renderTextFieldRow( 'email', 'email', $email, 'text', 25 );
		if ( !$user->mEmailAuthenticated ) {
			$confirm = SpecialPage::getTitleFor( 'Confirmemail' );
			$form .= '<br />';
			$form .= " <a href=\"{$confirm->getFullURL()}\">" . $this->msg( 'confirmemail' )->escaped() . '</a>';
		}
		if ( !$user->mEmailAuthenticated ) {
			$form .= '<p class="profile-update-unit-left"></p>
				<p class="profile-update-unit-small">' .
					$this->msg( 'user-profile-personal-email-needs-auth' )->escaped() .
				'</p>';
		}

		$form .= '<div class="profile-update">
			<p class="profile-update-title">' . $this->msg( 'user-profile-personal-location' )->escaped() . '</p>';
		$form .= $this->renderTextFieldRow( 'user-profile-personal-city', 'location_city', $location_city, 'text', 25 );
		$form .= $this->renderCountryDropdownRow(
			'user-profile-personal-country',
			'location_country',
			$location_country,
			$countries,
			$location_state
		);

		$form .= '<div class="profile-update">';
		$form .= '<p class="profile-update-title">' . $this->msg( 'user-profile-personal-hometown' )->escaped() . '</p>';
		$form .= $this->renderTextFieldRow( 'user-profile-personal-hometown-city', 'hometown_city', $hometown_city, 'text', 25 );
		$form .= $this->renderCountryDropdownRow(
			'user-profile-personal-country',
			'hometown_country',
			$hometown_country,
			$countries,
			$hometown_state,
			true
		);
		$form .= '</div>';

		$form .= '<div class="profile-update">';
		$s = $dbr->selectRow('user_profile', [ 'private_birthyear' ], [ 'up_actor' => $user->getActorId() ], __METHOD__ );
		$private_birthyear = ( $s !== false && isset( $s->private_birthyear ) ) ? $s->private_birthyear : null;

		$form .= $this->renderBirthdayFields( $birthday, $private_birthyear, $showYOB );

		$form .= '<div class="profile-update" id="profile-update-personal-aboutme">
			<p class="profile-update-title">' . $this->msg( 'user-profile-personal-aboutme' )->escaped() . '</p>';
			$form .= $this->renderBigTextFieldRow(
				'user-profile-personal-aboutme',
				'about',
				$about,
				5,
				75
			);

		$form .= '<div class="profile-update" id="profile-update-personal-work">
			<p class="profile-update-title">' . $this->msg( 'user-profile-personal-work' )->escaped() . '</p>';
		$form .= $this->renderBigTextFieldRow(
			'user-profile-personal-occupation',
			'occupation',
			$occupation,
			5,
			25
		);

		$form .= '<div class="profile-update" id="profile-update-personal-education">
			<p class="profile-update-title">' . $this->msg( 'user-profile-personal-education' )->escaped() . '</p>';
		$form .= $this->renderBigTextFieldRow(
			'user-profile-personal-schools',
			'schools',
			$schools,
			3,
			25
		);

		$form .= '<div class="profile-update" id="profile-update-personal-places">
			<p class="profile-update-title">' . $this->msg( 'user-profile-personal-places' )->escaped() . '</p>';
		$form .= $this->renderBigTextFieldRow(
			'user-profile-personal-placeslived',
			'places',
			$places,
			3,
			25
		);

		$form .= '<div class="profile-update" id="profile-update-personal-web">
			<p class="profile-update-title">' . $this->msg( 'user-profile-personal-web' )->escaped() . '</p>';
		$form .= $this->renderBigTextFieldRow(
			'user-profile-personal-websites',
			'websites',
			$websites,
			3,
			25
		);
		$form .= '<div class="profile-update" id="profile-update-personal-quote">
			<p class="profile-update-title">' . $this->msg( 'user-profile-personal-quote' )->escaped() . '</p>';
		$form .= $this->renderBigTextFieldRow(
			'user-profile-personal-quote',
			'quote',
			$quote,
			3,
			75
		);
		$form .= '
			<input type="submit" class="site-button" value="' . $this->msg( 'user-profile-update-button' )->escaped() . '" size="20" />
			</div>
			<input type="hidden" name="wpEditToken" value="' . htmlspecialchars( $this->getUser()->getEditToken(), ENT_QUOTES ) . '" />
		</form>';

		return $form;
	}

	/**
	 * @param UserIdentity $user
	 *
	 * @return string
	 */
	function displayPersonalForm( $user ) {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$s = $dbr->selectRow(
			'user_profile',
			[
				'up_about', 'up_places_lived', 'up_websites', 'up_relationship',
				'up_occupation', 'up_tagline', 'up_companies', 'up_schools', 'up_movies',
				'up_tv', 'up_music', 'up_books', 'up_video_games',
				'up_magazines', 'up_snacks', 'up_drinks', 'up_universes',
				'up_pets', 'up_hobbies', 'up_heroes', 'up_quote'
			],
			[
				// @phan-suppress-next-line PhanUndeclaredMethod Removed in MW 1.41
				'up_actor' => $user->getActorId()
			],
			__METHOD__
		);

		if ( $s !== false ) {
			$places = $s->up_places_lived;
			$websites = $s->up_websites;
			$relationship = $s->up_relationship;
			$companies = $s->up_companies;
			$schools = $s->up_schools;
			$movies = $s->up_movies;
			$tv = $s->up_tv;
			$music = $s->up_music;
			$books = $s->up_books;
			$videogames = $s->up_video_games;
			$magazines = $s->up_magazines;
			$snacks = $s->up_snacks;
			$drinks = $s->up_drinks;
			$universes = $s->up_universes;
			$pets = $s->up_pets;
			$hobbies = $s->up_hobbies;
			$heroes = $s->up_heroes;
		}

		$this->getOutput()->setPageTitle( $this->msg( 'user-profile-section-interests' )->escaped() );

		$form = UserProfile::getEditProfileNav( $this->msg( 'user-profile-section-interests' )->escaped() );
		$form .= '<form action="" method="post" enctype="multipart/form-data" name="profile">';
		// NoJS thing -- JS sets this to false, which means that in execute() we skip updating
		// profile field visibilities for users with JS enabled can do and have already done that
		// with the nice JS-enabled drop-down (instead of having to rely on a plain ol'
		// <select> + form submission, as no-JS users have to)
		$form .= Html::hidden( 'should_update_field_visibilities', true );
		$form .= '<div class="profile-info profile-info-other-info clearfix">';
		$form .= '<div class="profile-update">';
		$form .= $this->renderProfileRow( 'user-profile-interests-movies', 'movies', $movies );
		$form .= $this->renderProfileRow( 'user-profile-interests-tv', 'tv', $tv );
		$form .= $this->renderProfileRow( 'user-profile-interests-music', 'music', $music );
		$form .= $this->renderProfileRow( 'user-profile-interests-books', 'books', $books );
		$form .= $this->renderProfileRow( 'user-profile-interests-magazines', 'magazines', $magazines );
		$form .= $this->renderProfileRow( 'user-profile-interests-videogames', 'videogames', $videogames );
		$form .= '</div>';
		$form .= '<div class="profile-info">';
		$form .= '<p class="profile-update-title">' . $this->msg( 'user-profile-interests-eats' )->escaped() . '</p>';
		$form .= $this->renderProfileRow( 'user-profile-interests-foodsnacks', 'snacks', $snacks );
		$form .= $this->renderProfileRow( 'user-profile-interests-drinks', 'drinks', $drinks );
		$form .= '</div>
		         <div class="profile-info">
			<p class="profile-update-title">' . $this->msg( 'user-profile-interests-other' )->escaped() . '</p>';
		$form .= $this->renderProfileRow( 'user-profile-interests-universes', 'universes', $universes );
		$form .= $this->renderProfileRow( 'user-profile-interests-pets', 'pets', $pets );
		$form .= $this->renderProfileRow( 'user-profile-interests-hobbies', 'hobbies', $hobbies );
		$form .= $this->renderProfileRow( 'user-profile-interests-heroes', 'heroes', $heroes );
		$form .= '</div>
				<input type="submit" class="site-button" value="' . $this->msg( 'user-profile-update-button' )->escaped() . '" size="20" />
				</div>
				<input type="hidden" name="wpEditToken" value="' . htmlspecialchars( $this->getUser()->getEditToken(), ENT_QUOTES ) . '" />
				</form>';
		return $form;
	}
	private function renderProfileRow( $labelMsgKey, $fieldName, $fieldValue ) {
		return '<div class="profile-update-row">
			<p class="profile-update-unit-left">' . $this->msg( $labelMsgKey )->escaped() . '</p>
			<p class="profile-update-unit">
				<textarea name="' . $fieldName . '" id="' . $fieldName . '" rows="3" cols="75">'
				. htmlspecialchars( $fieldValue ?? '', ENT_QUOTES ) .
				'</textarea>
			</p>' . $this->renderEye( 'up_' . $fieldName ) . '</div>';
	}
	private function renderTextFieldRow( $labelMsgKey, $fieldName, $fieldValue, $type = 'text', $size = 25 ) {
		return '<div class="profile-update-row">
			<p class="profile-update-unit-left">' . $this->msg( $labelMsgKey )->escaped() . '</p>
			<p class="profile-update-unit">
				<input type="' . $type . '" name="' . $fieldName . '" id="' . $fieldName . '" size="' . $size . '" value="' . htmlspecialchars( $fieldValue ?? '', ENT_QUOTES ) . '" />
			</p>' . $this->renderEye( 'up_' . $fieldName ) . '</div>';
	}
	private function renderBigTextFieldRow( $labelMsgKey, $fieldName, $fieldValue, $rows = 3, $cols = 75 ) {
		return '<div class="profile-update-row">
			<p class="profile-update-unit-left">' . $this->msg( $labelMsgKey )->escaped() . '</p>
			<p class="profile-update-unit">
				<textarea name="' . $fieldName . '" id="' . $fieldName . '" rows="' . $rows . '" cols="' . $cols . '">' .
					htmlspecialchars( $fieldValue ?? '', ENT_QUOTES ) . '</textarea>
			</p>' .
			$this->renderEye( 'up_' . $fieldName ) .
		'</div>';
	}

	private function renderCountryDropdownRow(
		string $labelKey,
		string $fieldName,
		string $selectedCountry,
		array $countries,
		?string $stateValue = '',
		bool $hasStateSelector = false
	): string {
		$stateValue = (string)$stateValue; 
		$html = '<div class="profile-update-row">';
		$html .= '<p class="profile-update-unit-left" id="' . $fieldName . '_label">' .
			$this->msg( $labelKey )->escaped() . '</p>';
		$html .= '<p class="profile-update-unit">';

		if ( $hasStateSelector ) {
			$html .= '<span id="' . $fieldName . '_state_form"></span>';
			$html .= '<input type="hidden" id="' . $fieldName . '_state_current" value="' . htmlspecialchars( $stateValue, ENT_QUOTES ) . '" />';
		}

		$html .= '<select name="' . $fieldName . '" id="' . $fieldName . '">';
		$html .= '<option></option>';
		foreach ( $countries as $country ) {
			$html .= Xml::option( $country, $country, $country === $selectedCountry );
		}
		$html .= '</select>';
		$html .= '</p>';
		$html .= $this->renderEye( 'up_' . $fieldName );
		$html .= '</div>';

		return $html;
	}
	private function renderBirthdayFields( $birthday, $privateBirthYear, $showYOB ): string {
		$html = '<div class="profile-update">';
		$html .= '<p class="profile-update-title">' . $this->msg( 'user-profile-personal-birthday' )->escaped() . '</p>';
		$html .= '<div class="profile-update-row">';
		$html .= '<p class="profile-update-unit-left" id="birthday-format">' .
			$this->msg( $showYOB ? 'user-profile-personal-birthdate-with-year' : 'user-profile-personal-birthdate' )->escaped() . '</p>';
		$html .= '<p class="profile-update-unit"><input type="text"' .
			( $showYOB ? ' class="long-birthday"' : '' ) .
			' size="25" name="birthday" id="birthday" value="' . htmlspecialchars( $birthday ?? '', ENT_QUOTES ) . '" /></p>';
		$html .= $this->renderEye( 'up_birthday' ) . '</div>';

		if ( $privateBirthYear !== null ) {
			$html .= '<div class="profile-update">';
			$html .= '<p class="profile-update-title">' . $this->msg( 'nsfwblur-pref-birthyear-title' )->escaped() . '</p>';
			$html .= '<div class="profile-update-row">';
			$html .= '<p class="profile-update-unit-left">' . $this->msg( 'nsfwblur-pref-birthyear-label' )->escaped() . '</p>';
			$html .= '<p class="profile-update-unit">
				<input type="number" min="1900" max="' . date( 'Y' ) . '" name="private_birthyear" id="private_birthyear" value="' . htmlspecialchars( $privateBirthYear, ENT_QUOTES ) . '" />
				<span style="font-size:smaller;color:gray;">' . $this->msg( 'nsfwblur-pref-birthyear-help' )->escaped() . '</span>
			</p>';
			$html .= '</div> </div>';
		}

		return $html;
	}


	/**
	 * Displays the form for toggling notifications related to social tools
	 * (e-mail me when someone friends/foes me, send me a gift, etc.)
	 *
	 * @return string HTML
	 */
	function displayPreferencesForm() {
		$user = $this->getUser();

		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$s = $dbr->selectRow(
			'user_profile',
			[ 'up_birthday' ],
			[ 'up_actor' => $user->getActorId() ],
			__METHOD__
		);

		$showYOB = !$s || !$s->up_birthday;

		// @todo If the checkboxes are in front of the option, this would look more like Special:Preferences
		$this->getOutput()->setPageTitle( $this->msg( 'preferences' )->escaped() );

		$form = UserProfile::getEditProfileNav( $this->msg( 'preferences' )->escaped() );
		$form .= '<form action="" method="post" enctype="multipart/form-data" name="profile">';
		$form .= '<div class="profile-info clearfix">
			<div class="profile-update">
				<p class="profile-update-title">' . $this->msg( 'user-profile-preferences-emails' )->escaped() . '</p>';
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			$form .= '<p class="profile-update-row">' .
				$this->msg( 'user-profile-preferences-emails-manage' )->parse() .
				'</p>';
		} else {
			$form .= '<p class="profile-update-row">'
					. $this->msg( 'user-profile-preferences-emails-personalmessage' )->escaped() .
					' <input type="checkbox" size="25" name="notify_message" id="notify_message" value="1"' . ( ( $userOptionsLookup->getIntOption( $user, 'notifymessage', 1 ) == 1 ) ? 'checked' : '' ) . '/>
				</p>
				<p class="profile-update-row">'
					. $this->msg( 'user-profile-preferences-emails-friendfoe' )->escaped() .
					' <input type="checkbox" size="25" class="createbox" name="notify_friend" id="notify_friend" value="1" ' . ( ( $userOptionsLookup->getIntOption( $user, 'notifyfriendrequest', 1 ) == 1 ) ? 'checked' : '' ) . '/>
				</p>
				<p class="profile-update-row">'
					. $this->msg( 'user-profile-preferences-emails-family' )->escaped() .
					' <input type="checkbox" size="25" class="createbox" name="notify_family" id="notify_family" value="1" ' . ( ( $userOptionsLookup->getIntOption( $user, 'notifyfamilyrequest', 1 ) == 1 ) ? 'checked' : '' ) . '/>
				</p>
				<p class="profile-update-row">'
					. $this->msg( 'user-profile-preferences-emails-gift' )->escaped() .
					' <input type="checkbox" size="25" name="notify_gift" id="notify_gift" value="1" ' . ( ( $userOptionsLookup->getIntOption( $user, 'notifygift', 1 ) == 1 ) ? 'checked' : '' ) . '/>
				</p>

				<p class="profile-update-row">'
					. $this->msg( 'user-profile-preferences-emails-level' )->escaped() .
					' <input type="checkbox" size="25" name="notify_honorifics" id="notify_honorifics" value="1"' . ( ( $userOptionsLookup->getIntOption( $user, 'notifyhonorifics', 1 ) == 1 ) ? 'checked' : '' ) . '/>
				</p>';
		}

		$form .= '<p class="profile-update-title">' .
			$this->msg( 'user-profile-preferences-miscellaneous' )->escaped() .
			'</p>
			<p class="profile-update-row">' .
				$this->msg( 'user-profile-preferences-miscellaneous-show-year-of-birth' )->escaped() .
				' <input type="checkbox" size="25" name="show_year_of_birth" id="show_year_of_birth" value="1"' . ( ( $userOptionsLookup->getIntOption( $user, 'showyearofbirth', (int)$showYOB ) == 1 ) ? 'checked' : '' ) . '/>
			</p>';

		// Allow extensions (like UserMailingList) to add new checkboxes
		$this->getHookContainer()->run( 'SpecialUpdateProfile::displayPreferencesForm', [ $this, &$form ] );

		$form .= '</div>
			<div class="visualClear"></div>';
		$form .= '<input type="submit" class="site-button" value="' . $this->msg( 'user-profile-update-button' )->escaped() . '" size="20" />';
		$form .= Html::hidden( 'wpEditToken', $user->getEditToken() );
		$form .= '</form>';
		$form .= '</div>';

		return $form;
	}

	/**
	 * Displays the form for editing custom (site-specific) information.
	 *
	 * @param UserIdentity $user
	 * @return string HTML
	 */
	function displayCustomForm( $user ) {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$s = $dbr->selectRow(
			'user_profile',
			[
				'up_custom_1', 'up_custom_2', 'up_custom_3', 'up_custom_4',
				'up_custom_5'
			],
			[
				// @phan-suppress-next-line PhanUndeclaredMethod Removed in MW 1.41
				'up_actor' => $user->getActorId()
			],
			__METHOD__
		);

		if ( $s !== false ) {
			$custom1 = $s->up_custom_1;
			$custom2 = $s->up_custom_2;
			$custom3 = $s->up_custom_3;
			$custom4 = $s->up_custom_4;
		}

		$this->getOutput()->setPageTitle( $this->msg( 'user-profile-tidbits-title' )->escaped() );

		$form = UserProfile::getEditProfileNav( $this->msg( 'user-profile-section-custom' )->escaped() );
		$form .= '<form action="" method="post" enctype="multipart/form-data" name="profile">';
		// NoJS thing -- JS sets this to false, which means that in execute() we skip updating
		// profile field visibilities for users with JS enabled can do and have already done that
		// with the nice JS-enabled drop-down (instead of having to rely on a plain ol'
		// <select> + form submission, as no-JS users have to)
		$form .= Html::hidden( 'should_update_field_visibilities', true );
		$form .= '<div class="profile-info profile-info-custom-info clearfix">
				<div class="profile-update">
					<p class="profile-update-title">' . $this->msg( 'user-profile-tidbits-title' )->inContentLanguage()->parse() . '</p>
					<div id="profile-update-custom1">
					<p class="profile-update-unit-left">' . $this->msg( 'custom-info-field1' )->inContentLanguage()->parse() . '</p>
					<p class="profile-update-unit">
						<textarea name="custom1" id="fav_moment" rows="3" cols="75">' . ( isset( $custom1 ) && $custom1 ? htmlspecialchars( $custom1, ENT_QUOTES ) : '' ) . '</textarea>
					</p>
					</div>
					<div class="visualClear">' . $this->renderEye( 'up_custom_1' ) . '</div>
					<div id="profile-update-custom2">
					<p class="profile-update-unit-left">' . $this->msg( 'custom-info-field2' )->inContentLanguage()->parse() . '</p>
					<p class="profile-update-unit">
						<textarea name="custom2" id="least_moment" rows="3" cols="75">' . ( isset( $custom2 ) && $custom2 ? htmlspecialchars( $custom2, ENT_QUOTES ) : '' ) . '</textarea>
					</p>
					</div>
					<div class="visualClear">' . $this->renderEye( 'up_custom_2' ) . '</div>
					<div id="profile-update-custom3">
					<p class="profile-update-unit-left">' . $this->msg( 'custom-info-field3' )->inContentLanguage()->parse() . '</p>
					<p class="profile-update-unit">
						<textarea name="custom3" id="fav_athlete" rows="3" cols="75">' . ( isset( $custom3 ) && $custom3 ? htmlspecialchars( $custom3, ENT_QUOTES ) : '' ) . '</textarea>
					</p>
					</div>
					<div class="visualClear">' . $this->renderEye( 'up_custom_3' ) . '</div>
					<div id="profile-update-custom4">
					<p class="profile-update-unit-left">' . $this->msg( 'custom-info-field4' )->inContentLanguage()->parse() . '</p>
					<p class="profile-update-unit">
						<textarea name="custom4" id="least_fav_athlete" rows="3" cols="75">' . ( isset( $custom4 ) && $custom4 ? htmlspecialchars( $custom4, ENT_QUOTES ) : '' ) . '</textarea>
					</p>
					</div>
					<div class="visualClear">' . $this->renderEye( 'up_custom_4' ) . '</div>
					<div id="profile-update-custom5">
					<p class="profile-update-unit-left">' . $this->msg( 'custom-info-field5' )->inContentLanguage()->parse() . '</p>
					<p class="profile-update-unit">
						<textarea name="custom5" id="fav_food" rows="3" cols="75">' . ( isset( $custom5 ) && $custom5 ? htmlspecialchars( $custom5, ENT_QUOTES ) : '' ) . '</textarea>
					</p>
					</div>
				</div>
			<input type="submit" class="site-button" value="' . $this->msg( 'user-profile-update-button' )->escaped() . '" size="20" />
			</div>
			<input type="hidden" name="wpEditToken" value="' . htmlspecialchars( $this->getUser()->getEditToken(), ENT_QUOTES ) . '" />
		</form>';

		return $form;
	}

	/**
	 * Renders fields privacy button by field code
	 *
	 * @param string $fieldCode Internal field code, such as up_movies for the "Movies" field
	 *
	 * @return string
	 */
	private function renderEye( $fieldCode ) {
		return SPUserSecurity::renderEye( $fieldCode, $this->getUser() );
	}

	/** Normalize privacy value (accepts strings or ints). Default = public when empty/unknown. */
	private function normalizePrivacyValue($raw, string $default = 'public'): string {
		if ($raw === null || $raw === '') {
			return $default;
		}
		if (is_string($raw)) {
			$lc = strtolower(trim($raw));
			if (in_array($lc, ['public','friends','hidden'], true)) {
				return $lc;
			}
			if ($lc === 'friend' || $lc === 'friends-only') {
				return 'friends';
			}
			// Unknown string → default
			return $default;
		}
		if (is_numeric($raw)) { // common mapping
			$int = (int)$raw;
			if ($int === 0) return 'public';
			if ($int === 1) return 'friends';
			if ($int === 2) return 'hidden';
			return $default;
		}
		return $default;
	}

	/** Collect posted privacy values in a consistent [ 'up_field' => 'public|friends|hidden' ] map. */
	private function getPostedFieldVisibilities(): array {
		$req = $this->getRequest();
		$vis = [];

		// Pattern A: privacy[up_real_name] = public|friends|hidden
		$map = $req->getArray('privacy', []);
		if (is_array($map)) {
			foreach ($map as $k => $v) {
				if (is_string($k) && preg_match('/^up_/', $k)) {
					$vis[$k] = $this->normalizePrivacyValue($v, 'public');
				}
			}
		}

		// Pattern B: up_real_name_privacy = ...
		foreach ($req->getValues() as $k => $v) {
			if (is_string($k) && preg_match('/^(up_.+)_privacy$/', $k, $m)) {
				$vis[$m[1]] = $this->normalizePrivacyValue($v, 'public');
			}
		}

		// Pattern C: direct up_real_name = ...
		foreach ($req->getValues() as $k => $v) {
			if (is_string($k) && preg_match('/^up_/', $k)) {
				// If we already captured a value from A or B, don't overwrite
				if (!array_key_exists($k, $vis)) {
					$vis[$k] = $this->normalizePrivacyValue($v, 'public');
				}
			}
		}

		return $vis;
	}

	/** Persist privacy directly if SPUserSecurity setter isn't available (row-per-field schema). */
	private function savePrivacyDirect(int $userId, string $fieldKey, string $visibility): void {
		$lb  = MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbw = $lb->getConnection(DB_PRIMARY);

		if (!$dbw->tableExists('user_fields_privacy', __METHOD__)) {
			// Table missing → nothing to do
			return;
		}

		// Try to detect row-per-field columns
		$hasRowPerField =
			$dbw->fieldExists('user_fields_privacy', 'ufp_user_id', __METHOD__) &&
			$dbw->fieldExists('user_fields_privacy', 'ufp_field', __METHOD__) &&
			$dbw->fieldExists('user_fields_privacy', 'ufp_privacy', __METHOD__);

		if ($hasRowPerField) {
			$dbw->upsert(
				'user_fields_privacy',
				[
					'ufp_user_id' => $userId,
					'ufp_field'   => $fieldKey,
					'ufp_privacy' => $visibility,
				],
				[ ['ufp_user_id', 'ufp_field'] ],
				[ 'ufp_privacy' => $visibility ],
				__METHOD__
			);
			return;
		}

		// Fallback: column-per-field style, try to update up_privacy_<field>
		$userCol = null;
		foreach (['up_user_id','ufp_user_id','user_id'] as $candidate) {
			if ($dbw->fieldExists('user_fields_privacy', $candidate, __METHOD__)) { $userCol = $candidate; break; }
		}
		if ($userCol === null) { return; }

		$col = 'up_privacy_' . preg_replace('/^up_/', '', $fieldKey);
		if (!$dbw->fieldExists('user_fields_privacy', $col, __METHOD__)) {
			return;
		}

		$row = $dbw->selectRow('user_fields_privacy', [$userCol], [$userCol => $userId], __METHOD__);
		if ($row) {
			$dbw->update('user_fields_privacy', [ $col => $visibility ], [ $userCol => $userId ], __METHOD__);
		} else {
			$dbw->insert('user_fields_privacy', [ $userCol => $userId, $col => $visibility ], __METHOD__);
		}
	}

}
