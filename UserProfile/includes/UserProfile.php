<?php
declare( strict_types=1 );
namespace ContinuumUniverses\ContinuumProfile\UserProfile;



use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\User;

use SocialProfileFileBackend;

/**
 * Class to access profile data for a user
 */
class UserProfile {
	/** @var int Cache key version; bump this to force recaching of UserProfile data */
	public const CACHE_VERSION = 2;

	private const FIELD_TEXT  = 'text';
	private const FIELD_LIST  = 'list';
	private const FIELD_QUOTE = 'quote';

	/**
	 * Render types for fields.
	 * You can tweak this over time without touching DB schema.
	 */
	private const FIELD_TYPES = [
		// “Listy” fields (newline-separated in DB -> <ul><li>…</li></ul>)
		// newline-separated => <ul><li>…</li></ul>
		'places_lived' => self::FIELD_LIST,
		'schools'      => self::FIELD_LIST,
		'pets'         => self::FIELD_LIST,
		'hobbies'      => self::FIELD_LIST,
		'heroes'       => self::FIELD_LIST,

		// Interests that people naturally enter one-per-line
		'movies'       => self::FIELD_LIST,
		'tv'           => self::FIELD_LIST,
		'music'        => self::FIELD_LIST,
		'books'        => self::FIELD_LIST,
		'video_games'  => self::FIELD_LIST,
		'magazines'    => self::FIELD_LIST,
		'snacks'       => self::FIELD_LIST,
		'drinks'       => self::FIELD_LIST,
		'universes'    => self::FIELD_LIST,
		'websites' 	   => self::FIELD_LINKLIST,
		'rig' 		   => self::FIELD_LIST,
		// blockquote
		'quote'        => self::FIELD_QUOTE,
		'obsessed' => self::FIELD_LIST,
		'tools' => self::FIELD_LIST,
	];
	private const FIELD_LINKLIST = 'linklist';
	/**
	 * @var User User object whose profile is being viewed
	 */
	public $user;

	/**
	 * @var int The current user's user ID.
	 * @deprecated Prefer using $this->user to get an actor ID instead
	 */
	public $user_id;

	/**
	 * @var string The current user's user name.
	 * @deprecated Prefer using $this->user instead
	 */
	public $user_name;

	/**
	 * @var int used in getProfileComplete()
	 */
	public $profile_fields_count;

	/**
	 * @var array Array of valid profile fields; used in getProfileComplete()
	 * These _mostly_ correspond to the fields in the user_profile DB table.
	 * If a field is not defined here, it won't be shown in profile pages!
	 * @see https://phabricator.wikimedia.org/T212290
	 */
	public $profile_fields = [
		'groups',
		'real_name',
		'tagline',
		'location_city',
		'hometown_city',
		'hometown_country',
		'birthday',
		'about',
		'places_lived',
		'websites',
		'rig',
		'occupation',
		'schools',
		'movies',
		'tv',
		'music',
		'books',
		'magazines',
		'video_games',
		'snacks',
		'drinks',
		'universes',
		'pets',
		'hobbies',
		'heroes',
		'quote',
		'obsessed',
		'tools',
		'custom_1',
		'custom_2',
		'custom_3',
		'custom_4',
		'custom_5', // <-- you already load this, so include it here
		'email'
	];

	/**
	 * @var array Unused, remove me?
	 */
	public $profile_missing = [];

	/**
	 * @param User|string $username User object (preferred) or user name (legacy b/c)
	 * @todo FIXME: will explode horribly if $username is an IP address
	 */
	public function __construct( $username ) {
		if ( $username instanceof User ) {
			$this->user = $username;
		} else {
			$this->user = User::newFromName( $username );
		}
		$this->user_name = $this->user->getName();
		$this->user_id = $this->user->getId();
	}

	/**
	 * Gets the memcached key for the given user.
	 *
	 * @param UserIdentity $user User object for the desired user
	 * @return string
	 */
	public static function getCacheKey( $user ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		// @phan-suppress-next-line PhanUndeclaredMethod Removed in MW 1.41
		return $cache->makeKey( 'user', 'profile', 'info', 'actor_id', $user->getActorId(), self::CACHE_VERSION );
	}

	/**
	 * Deletes the memcached key for the given user.
	 *
	 * @param UserIdentity $user User object for the desired user
	 */
	public static function clearCache( $user ) {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$cache->delete( self::getCacheKey( $user ) );
	}

	/**
	 * Loads social profile info for the current user.
	 * First tries fetching the info from memcached and if that fails,
	 * queries the database.
	 * Fetched info is cached in memcached.
	 *
	 * @return array
	 */
	public function getProfile() {
		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

		$this->user->load();

		// Try cache first
		$key = self::getCacheKey( $this->user );
		$data = $cache->get( $key );
		if ( $data ) {
			wfDebug( "Got user profile info for {$this->user->getName()} from cache\n" );
			$profile = $data;
		} else {
			wfDebug( "Got user profile info for {$this->user->getName()} from DB\n" );
			$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
			$row = $dbr->selectRow(
				'user_profile',
				'*',
				[ 'up_actor' => $this->user->getActorId() ],
				__METHOD__,
				[ 'LIMIT' => 5 ]
			);

			$profile = [];
			if ( $row ) {
				$profile['actor'] = $this->user->getActorId();
			} else {
				$profile['user_page_type'] = 1;
				$profile['actor'] = 0;
			}

			$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
			$showYOB = $userOptionsLookup->getIntOption(
				$this->user,
				'showyearofbirth',
				(int)!isset( $row->up_birthday )
			) == 1;

			$issetUpBirthday = $row->up_birthday ?? '';

			$profile['location_city'] = $row->up_location_city ?? '';
			$profile['location_state'] = $row->up_location_state ?? '';
			$profile['location_country'] = $row->up_location_country ?? '';
			$profile['hometown_city'] = $row->up_hometown_city ?? '';
			$profile['hometown_state'] = $row->up_hometown_state ?? '';
			$profile['hometown_country'] = $row->up_hometown_country ?? '';
			$profile['birthday'] = $this->formatBirthday( $issetUpBirthday, $showYOB );

			$profile['about'] = $row->up_about ?? '';
			$profile['tagline'] = $row->up_tagline ?? '';
			$profile['places_lived'] = $row->up_places_lived ?? '';
			$profile['websites'] = $row->up_websites ?? '';
			$profile['rig'] = $row->up_rig ?? '';
			$profile['occupation'] = $row->up_occupation ?? '';
			$profile['schools'] = $row->up_schools ?? '';
			$profile['movies'] = $row->up_movies ?? '';
			$profile['music'] = $row->up_music ?? '';
			$profile['tv'] = $row->up_tv ?? '';
			$profile['books'] = $row->up_books ?? '';
			$profile['magazines'] = $row->up_magazines ?? '';
			$profile['video_games'] = $row->up_video_games ?? '';
			$profile['snacks'] = $row->up_snacks ?? '';
			$profile['drinks'] = $row->up_drinks ?? '';
			$profile['universes'] = $row->up_universes ?? '';
			$profile['pets'] = $row->up_pets ?? '';
			$profile['hobbies'] = $row->up_hobbies ?? '';
			$profile['heroes'] = $row->up_heroes ?? '';
			$profile['quote'] = $row->up_quote ?? '';
			$profile['obsessed'] = $row->up_obsessed ?? '';
			$profile['tools'] = $row->up_tools ?? '';
			// Custom fields
			$profile['custom_1'] = $row->up_custom_1 ?? '';
			$profile['custom_2'] = $row->up_custom_2 ?? '';
			$profile['custom_3'] = $row->up_custom_3 ?? '';
			$profile['custom_4'] = $row->up_custom_4 ?? '';
			$profile['custom_5'] = $row->up_custom_5 ?? '';

			$profile['user_page_type'] = $row->up_type ?? '';

			$cache->set( $key, $profile );
		}

		// These come from core user fields, not the user_profile table
		$profile['real_name'] = $this->user->getRealName();
		$profile['email'] = $this->user->getEmail();

		// Optional: expose groups as a raw newline list so your renderer can listify it.
		// If you already handle this elsewhere, delete this line.
		$ugm = MediaWikiServices::getInstance()->getUserGroupManager();
		$groups = $ugm->getUserGroups( $this->user ); // explicit groups
		$profile['groups'] = implode( "\n", $groups );


		return $profile;
	}

	/**
	 * Render a single field value as safe HTML based on its field type.
	 * This keeps your DB raw, and makes your output nice.
	 *
	 * @param string $fieldKey e.g. 'hobbies'
	 * @param string $raw Raw DB value
	 * @return string Safe HTML
	 */
	public function renderFieldHtml( string $fieldKey, string $raw ): string {
		$type = $this->getFieldType( $fieldKey );

		if ( $type === self::FIELD_LIST ) {
			return $this->renderMaybeList( $raw, true );
		}

		if ( $type === self::FIELD_QUOTE ) {
			return $this->renderQuote( $raw );
		}

		return $this->renderMaybeList( $raw, false );
	}

	private function getFieldType( string $fieldKey ): string {
		return self::FIELD_TYPES[$fieldKey] ?? self::FIELD_TEXT;
	}

	private function renderMaybeList( string $raw, bool $asList ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}

		if ( !$asList ) {
			// Keep newlines, escape safely
			return nl2br( htmlspecialchars( $raw, ENT_QUOTES, 'UTF-8' ) );
		}

		$items = preg_split( "/\r\n|\r|\n/", $raw );
		$items = array_values( array_filter(
			array_map( 'trim', $items ),
			static fn( $x ) => $x !== ''
		) );

		if ( !$items ) {
			return '';
		}

		$out = "<ul class='profile-list'>";
		foreach ( $items as $item ) {
			$out .= "<li>" . htmlspecialchars( $item, ENT_QUOTES, 'UTF-8' ) . "</li>";
		}
		$out .= "</ul>";
		return $out;
	}

	private function renderQuote( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}

		// Preserve line breaks inside the quote.
		$body = nl2br( htmlspecialchars( $raw, ENT_QUOTES, 'UTF-8' ) );

		return "<blockquote class='profile-quote'>{$body}</blockquote>";
	}

	/**
	 * Format the user's birthday.
	 *
	 * @param string $birthday Birthday in YYYY-MM-DD format
	 * @param bool $showYear
	 * @return string
	 */
	public function formatBirthday( $birthday, $showYear = true ) {
		$dob = explode( '-', $birthday );
		if ( count( $dob ) == 3 ) {
			$month = $dob[1];
			$day = $dob[2];
			if ( !$showYear ) {
				if ( $dob[1] == '00' && $dob[2] == '00' ) {
					return '';
				} else {
					return $month . $day;
				}
			}
			$year = $dob[0];
			if ( $dob[0] == '00' && $dob[1] == '00' && $dob[2] == '00' ) {
				return '';
			} else {
				return $year . $month . $day;
			}
		}
		return $birthday;
	}

	/**
	 * How many % of this user's profile is complete?
	 *
	 * @return float
	 */
	public function getProfileComplete() {
		$complete_count = 0;
		$this->profile_fields_count = 0;

		$profile = $this->getProfile();
		foreach ( $this->profile_fields as $field ) {
			$value = $profile[$field] ?? '';
			if ( $value !== '' ) {
				$complete_count++;
			}
			$this->profile_fields_count++;
		}

		// Check if the user has a non-default avatar
		$this->profile_fields_count++;
		$avatar = new wAvatar( $this->user->getId(), 'xl' );
		if ( !$avatar->isDefault() ) {
			$complete_count++;
		}

		return round( $complete_count / $this->profile_fields_count * 100 );
	}

	public static function getEditProfileNav( $current_nav ) {
		$lines = explode( "\n", wfMessage( 'update_profile_nav' )->inContentLanguage()->text() );
		$output = '<div class="profile-tab-bar">';
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		foreach ( $lines as $line ) {
			if ( strpos( $line, '*' ) !== 0 ) {
				continue;
			}

			$line = explode( '|', trim( $line, '* ' ), 2 );
			$page = Title::newFromText( $line[0] );
			$link_text = $line[1];

			// Maybe it's the name of a system message? (bug #30030)
			$msgObj = wfMessage( $line[1] );
			if ( !$msgObj->isDisabled() ) {
				$link_text = $msgObj->text();
			}

			$output .= '<div class="profile-tab' . ( ( $current_nav == $link_text ) ? '-on' : '' ) . '">';
			$output .= $linkRenderer->makeLink( $page, $link_text );
			$output .= '</div>';
		}

		$output .= '<div class="visualClear"></div></div>';
		return $output;
	}
}
