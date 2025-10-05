<?php

/**
 * Formatter for user relationship (friend/foe) notifications ('social-rel-*')
 */
class EchoUserRelationshipPresentationModel extends EchoEventPresentationModel {

	public function getIconType() {
		$eventType = $this->event->getType();
		$relType = $this->event->getExtraParam( 'rel_type' );

		if ( $relType == 1 ) { // friend added/pending friend request
			return 'gratitude';
		} elseif ( $eventType == 'social-rel-accept' && $relType != 1 ) { // foe added
			return 'social-added';
		} else { // pending friend or foe request
			// @todo FIXME: better icon
			return 'placeholder';
		}
	}

	public function getHeaderMessage() {
		$eventType = $this->event->getType();
		$message = $this->event->getExtraParam( 'message' );
		$relType = $this->event->getExtraParam( 'rel_type' );

		if ( $eventType == 'social-rel-add' ) { // pending request
			if ( $relType == 1 && $message ) {
				$msg = 'notification-social-rel-add-friend-message';
			} elseif ( $relType == 1 ) {
				$msg = 'notification-social-rel-add-friend-no-message';
			} elseif ( $relType == 2 && $message ) {
				$msg = 'notification-social-rel-add-foe-message';
			} elseif ( $relType == 2 ) {
				$msg = 'notification-social-rel-add-foe-no-message';
			} elseif ( $relType == 3 && $message ) {
				$msg = 'notification-social-rel-add-family-message';
			} elseif ( $relType == 3 ) {
				$msg = 'notification-social-rel-add-family-no-message';
			} else {
				// Fallback for unhandled add cases
				return $this->msg(
					'notification-social-rel-generic',
					$this->event->getAgent()->getName()
				);
			}
			return $this->msg(
				$msg,
				$this->event->getAgent()->getName(),
				$message
			);
		} elseif ( $eventType == 'social-rel-accept' ) {
			$msg = ( $relType == 1 ? 'notification-social-rel-accept-friend' : 'notification-social-rel-accept-foe' );
			return $this->msg(
				$msg,
				$this->event->getAgent()->getName()
			);
		} elseif ( $eventType == 'social-rel-remove' ) {
			// Example for removal, add your own message keys!
			$msg = ( $relType == 1 ? 'notification-social-rel-remove-friend' :
					( $relType == 2 ? 'notification-social-rel-remove-foe' :
					( $relType == 3 ? 'notification-social-rel-remove-family' : 'notification-social-rel-generic' ) ) );
			return $this->msg(
				$msg,
				$this->event->getAgent()->getName()
			);
		}

		// FINAL fallback for *any* other unknown event type
		return $this->msg(
			'notification-social-rel-generic',
			$this->event->getAgent()->getName()
		);
	}

	public function getBodyMessage() {
		return false;
	}

	public function getPrimaryLink() {
		$eventType = $this->event->getType();
		$relType = $this->event->getExtraParam( 'rel_type' );
		$url = ''; // for phan
		if ( $eventType == 'social-rel-add' ) { // pending request
			$url = SpecialPage::getTitleFor( 'ViewRelationshipRequests' )->getLocalURL();
		} elseif ( $eventType == 'social-rel-accept' ) { // accepted request
			$url = SpecialPage::getTitleFor( 'ViewRelationships' )->getLocalURL();
		}
		return [
			'url' => $url,
			'label' => $this->msg( 'echo-learn-more' )->text()
		];
	}

	public function getSecondaryLinks() {
		// Apparently these two can't be the other way around 'cause it'll look
		// stupid. Who knew?
		return [ $this->getAgentLink(), $this->getSpecialPageLink() ];
	}

	private function getSpecialPageLink() {
		$eventType = $this->event->getType();
		$relType = $this->event->getExtraParam( 'rel_type' );
		$label = $url = ''; // for phan
		if ( $eventType == 'social-rel-add' ) { // pending request
			$label = $this->msg( 'viewrelationshiprequests' )->text();
			$url = SpecialPage::getTitleFor( 'ViewRelationshipRequests' )->getLocalURL();
		} elseif ( $eventType == 'social-rel-accept' ) { // accepted request
			$relTypeMap = [
				1 => 'ur-title-friend',
				2 => 'ur-title-foe',
				3 => 'ur-title-family'
			];

			$labelKey = $relTypeMap[$relType] ?? 'ur-title-unknown';

			$label = $this->msg( $labelKey )
				->params( $this->getViewingUserForGender() )
				->text();

			$url = SpecialPage::getTitleFor( 'ViewRelationships' )->getLocalURL();
		}
		return [
			'label' => $label,
			'url' => $url,
			'description' => '',
			'icon' => false,
			'prioritized' => true,
		];
	}

	/**
	 * Get a Message object and add the performer's name as a parameter.
	 * The output of the message should be plaintext.
	 *
	 * This message is used as the subject line in single-notification emails.
	 *
	 * @return Message
	 */
	public function getSubjectMessage() {
		$eventType = $this->event->getType();
		$relType = $this->event->getExtraParam( 'rel_type' );
		$msgKey = ''; // for phan with love
		if ( $eventType == 'social-rel-add' ) {
			$msgKeys = [
				1 => 'friend_request_subject',
				2 => 'foe_request_subject',
				3 => 'family_request_subject'
			];
			$msgKey = $msgKeys[$relType] ?? 'friend_request_subject';
		} elseif ( $eventType == 'social-rel-accept' ) {
			$msgKey = 'notification-social-rel-accept-email-subject';
		}

		// Note that getMessageWithAgent() adds the username as $2 for GENDER, even
		// if it's currently unused/ignored in the messages mentioned above
		$msg = $this->getMessageWithAgent( $msgKey );
		return $msg;
	}

}
