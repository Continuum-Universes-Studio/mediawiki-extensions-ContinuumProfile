var UserGifts = {
	selected_gift: 0,

	// Countdown as user types characters
	// "Borrowed" from FanBoxes & slightly modified here as per discussion w/ Isarra on 28 August 2020
	limitText: function ( limitField, limitCount, limitNum ) {
		if ( limitField.value.length > limitNum ) {
			limitField.value = limitField.value.slice( 0, Math.max( 0, limitNum ) );
		} else {
			// This old line of code won't work now that the displayed number is
			// no longer an <input>...
			// limitCount.value = limitNum - limitField.value.length;
			// First we store the current remaining character amount as the value attribute
			// of the <span> which also shows the amount of characters left to the user...
			limitCount.attr( 'value', limitNum - limitField.value.length );
			// ...and then we change the displayed number accordingly, like this:
			limitCount.html( limitCount.attr( 'value' ) );
		}
	},

	selectGift: function ( id ) {
		// Un-select previously selected gift
		if ( UserGifts.selected_gift ) {
			$( '#give_gift_' + UserGifts.selected_gift ).removeClass( 'g-give-all-selected' );
		}

		// Select new gift
		$( '#give_gift_' + id ).addClass( 'g-give-all-selected' );

		UserGifts.selected_gift = id;
	},

	highlightGift: function ( id ) {
		$( '#give_gift_' + id ).addClass( 'g-give-all-highlight' );
	},

	unHighlightGift: function ( id ) {
		$( '#give_gift_' + id ).removeClass( 'g-give-all-highlight' );
	},

	sendGift: function () {
		if ( !UserGifts.selected_gift ) {
			window.alert( mw.msg( 'g-select-gift' ) );
			return false;
		}
		document.gift.gift_id.value = UserGifts.selected_gift;
		document.gift.submit();
	},

	chooseRelationship: function ( userName, relType ) {
		const relMap = {
			1: 'friend',
			2: 'foe',
			3: 'family'
		};
		const relString = relMap[relType] ?? 'friend';

		const url = new mw.Uri( mw.util.getUrl( 'Special:GiveGift' ) );
		url.extend( {
			user: userName,
			relationship: relString
		} );
		window.location = url.toString();
	}

};

$( () => {
	// "Select a friend" dropdown menu
	$( 'div.g-gift-select select' ).on( 'change', function () {
		UserGifts.chooseRelationship( $( this ).val() );
	} );

	// Handlers for individual gift images
	$( 'div[id^=give_gift_]' ).on( {
		click: function () {
			UserGifts.selectGift(
				$( this ).attr( 'id' ).replace( 'give_gift_', '' )
			);
		},
		mouseout: function () {
			UserGifts.unHighlightGift(
				$( this ).attr( 'id' ).replace( 'give_gift_', '' )
			);
		},
		mouseover: function () {
			UserGifts.highlightGift(
				$( this ).attr( 'id' ).replace( 'give_gift_', '' )
			);
		}
	} );

	// "X characters left" counter
	$( '#message' ).on( {
		keydown: function () {
			UserGifts.limitText( this.form.message, $( 'span.countdown' ), 255 );
		},
		keyup: function () {
			UserGifts.limitText( this.form.message, $( 'span.countdown' ), 255 );
		},
		paste: function () {
			UserGifts.limitText( this.form.message, $( 'span.countdown' ), 255 );
		},
		keypress: function () {
			UserGifts.limitText( this.form.message, $( 'span.countdown' ), 255 );
		}
	} );

	// "Send gift" button
	$( 'input#send-gift-button' ).on( 'click', () => {
		UserGifts.sendGift();
	} );
} );
