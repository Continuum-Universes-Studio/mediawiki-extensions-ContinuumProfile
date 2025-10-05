<?php
namespace SocialProfile\UserProfile;

use MediaWiki\MediaWikiServices;
use Html;
use MessageLocalizer;
use User;

class UserGroupBadges {
	private MessageLocalizer $msg;

	public function __construct( MessageLocalizer $msg ) {
		$this->msg = $msg;
	}

	public function render( User $user ): string {
		$services = MediaWikiServices::getInstance();
		$ugm = $services->getUserGroupManager();
		$config = $services->getMainConfig();

		if ( !$config->get( 'SocialProfileShowGroupBadges' ) ) {
			return '';
		}

		$hide = $config->get( 'SocialProfileHideGroups' );
		$groups = array_diff( $ugm->getUserGroups( $user ), $hide ?: [] );
		if ( !$groups ) {
			return '';
		}

		$badges = '';
		foreach ( $groups as $group ) {
			$label = $this->msg->msg( "group-$group" )->text();
			$badges .= Html::element(
				'span',
				[ 'class' => "usergroup-badge group-$group", 'title' => $label ],
				$label
			) . ' ';
		}

		return Html::rawElement( 'span', [ 'class' => 'usergroup-badges' ], rtrim( $badges ) );
	}
}
