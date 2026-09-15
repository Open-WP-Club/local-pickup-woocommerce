( function () {
	'use strict';

	const METHOD_PREFIX = 'lps_local_pickup:';
	const WRAPPER_CLASS = 'lps-pickup-selector';
	let isSyncing = false;

	function findPickupRadios() {
		return Array.from(
			document.querySelectorAll( '.wp-block-woocommerce-checkout-pickup-options-block input[type="radio"]' )
		).filter( function ( input ) {
			return input.value.indexOf( METHOD_PREFIX ) === 0;
		} );
	}

	function optionLabel( input ) {
		const label = input.id ? document.querySelector( 'label[for="' + CSS.escape( input.id ) + '"]' ) : null;
		return label ? label.textContent.replace( /\s+/g, ' ' ).trim() : input.value;
	}

	function instanceId( input ) {
		return input.value.slice( METHOD_PREFIX.length ).split( ':' )[ 0 ];
	}

	function locationId( input ) {
		return input.value.slice( METHOD_PREFIX.length ).split( ':' )[ 1 ];
	}

	function groupLabel( radios ) {
		const titles = window.lpsCheckout ? window.lpsCheckout.methodTitles : null;
		const title = titles ? titles[ instanceId( radios[ 0 ] ) ] : null;
		return title || ( window.lpsCheckout ? window.lpsCheckout.pickupLocation : 'Pickup location' );
	}

	function optionText( input ) {
		const locations = window.lpsCheckout ? window.lpsCheckout.locations : null;
		const location = locations ? locations[ locationId( input ) ] : null;
		if ( ! location ) {
			return optionLabel( input );
		}

		const showPrice = window.lpsCheckout && window.lpsCheckout.showPrice;
		const shouldShowPrice = showPrice ? showPrice[ instanceId( input ) ] !== false : true;

		return shouldShowPrice && location.price ? location.name + ' — ' + location.price : location.name;
	}

	function renderSelector() {
		if ( isSyncing ) {
			return;
		}

		const radios = findPickupRadios();
		const current = document.querySelector( '.' + WRAPPER_CLASS );
		if ( ! radios.length ) {
			if ( current ) {
				current.remove();
			}
			return;
		}

		const signature = radios.map( function ( radio ) {
			return radio.value + ':' + optionText( radio );
		} ).join( '|' );

		if ( current && current.dataset.signature === signature ) {
			const select = current.querySelector( 'select' );
			const checked = radios.find( function ( radio ) { return radio.checked; } );
			if ( checked && select.value !== checked.value ) {
				select.value = checked.value;
			}
			return;
		}

		if ( current ) {
			current.remove();
		}

		const wrapper = document.createElement( 'div' );
		wrapper.className = WRAPPER_CLASS;
		wrapper.dataset.signature = signature;

		const label = document.createElement( 'label' );
		label.htmlFor = 'lps-pickup-location';
		label.textContent = groupLabel( radios );

		const select = document.createElement( 'select' );
		select.id = 'lps-pickup-location';
		select.className = 'wc-block-components-select__select';
		select.setAttribute( 'required', 'required' );

		const placeholder = document.createElement( 'option' );
		placeholder.value = '';
		placeholder.textContent = window.lpsCheckout ? window.lpsCheckout.selectLocation : 'Select a pickup location';
		placeholder.disabled = true;
		select.appendChild( placeholder );

		radios.forEach( function ( radio ) {
			const option = document.createElement( 'option' );
			option.value = radio.value;
			option.textContent = optionText( radio );
			option.selected = radio.checked;
			select.appendChild( option );

			const row = radio.closest( '.wc-block-components-radio-control__option' );
			if ( row ) {
				row.classList.add( 'lps-native-rate' );
			}
		} );

		if ( ! radios.some( function ( radio ) { return radio.checked; } ) ) {
			select.value = '';
		}

		select.addEventListener( 'change', function () {
			const selected = radios.find( function ( radio ) { return radio.value === select.value; } );
			if ( selected ) {
				isSyncing = true;
				selected.click();
				isSyncing = false;
				window.setTimeout( renderSelector, 0 );
			}
		} );

		wrapper.appendChild( label );
		wrapper.appendChild( select );

		const firstRow = radios[ 0 ].closest( '.wc-block-components-radio-control__option' );
		if ( firstRow && firstRow.parentNode ) {
			firstRow.parentNode.insertBefore( wrapper, firstRow );
		}
	}

	document.addEventListener( 'DOMContentLoaded', renderSelector );
	new MutationObserver( renderSelector ).observe( document.documentElement, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'checked' ],
	} );
}() );
