( function () {
	'use strict';

	const METHOD_PREFIX = 'lps_local_pickup:';
	const WRAPPER_CLASS = 'lps-pickup-selector';
	let isSyncing = false;

	const RADIO_SELECTORS = [
		'.wp-block-woocommerce-checkout-pickup-options-block input[type="radio"]',
		'#shipping_method input[type="radio"]',
	];

	function findPickupRadios() {
		const seen = new Set();
		const radios = [];

		RADIO_SELECTORS.forEach( function ( selector ) {
			document.querySelectorAll( selector ).forEach( function ( input ) {
				if ( input.value.indexOf( METHOD_PREFIX ) === 0 && ! seen.has( input ) ) {
					seen.add( input );
					radios.push( input );
				}
			} );
		} );

		return radios;
	}

	function pickupRow( input ) {
		return input.closest( '.wc-block-components-radio-control__option' ) || input.closest( 'li' );
	}

	function hideNow( el ) {
		el.classList.add( 'lps-native-rate' );
		// Inline !important beats even ID-selector theme/plugin CSS
		// (e.g. #shipping_method li), which the class alone can lose to.
		el.style.setProperty( 'display', 'none', 'important' );
	}

	// Themes render rates in their own markup (e.g. buttons made of a
	// <label for>), so hide the row, the label bound to the input, and the
	// input's wrapper when it holds nothing but this rate.
	// Re-run on every pass: renderers replace these nodes on refresh.
	function hideRate( input ) {
		const row = pickupRow( input );
		const label = input.id ? document.querySelector( 'label[for="' + CSS.escape( input.id ) + '"]' ) : null;
		const wrapper = input.parentElement;
		if ( row ) {
			hideNow( row );
		} else if ( wrapper && wrapper.querySelectorAll( 'input' ).length === 1 ) {
			hideNow( wrapper );
		}
		if ( label ) {
			hideNow( label );
		}
		hideNow( input );
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

	function syncSelector( wrapper, radios ) {
		const checked = radios.find( function ( radio ) { return radio.checked; } );
		const select = wrapper.querySelector( 'select' );
		select.required = Boolean( checked );
		select.value = checked ? checked.value : '';
	}

	function selectRate( value ) {
		// WooCommerce can replace the native inputs after a checkout refresh.
		const radios = findPickupRadios();
		const selected = radios.find( function ( radio ) { return radio.value === value; } ) || radios[ 0 ];
		if ( selected ) {
			isSyncing = true;
			selected.click();
			isSyncing = false;
			renderSelector();
		}
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

		// Re-mark rows on every pass: some checkout renderers replace these
		// nodes on refresh, which would silently drop the hiding class.
		radios.forEach( hideRate );

		const signature = radios.map( function ( radio ) {
			return radio.value + ':' + optionText( radio );
		} ).join( '|' );

		const firstRow = pickupRow( radios[ 0 ] );
		const parent   = firstRow ? firstRow.parentNode : null;
		if ( current && current.dataset.signature === signature && current.parentNode === parent ) {
			syncSelector( current, radios );
			return;
		}

		if ( current ) {
			current.remove();
		}

		const isList   = parent && ( 'UL' === parent.tagName || 'OL' === parent.tagName );

		const wrapper = document.createElement( isList ? 'li' : 'div' );
		wrapper.className = WRAPPER_CLASS;
		wrapper.dataset.signature = signature;

		// Heading only, no radio: picking a store in the dropdown selects the
		// native rate, which remains the submitted shipping method.
		const methodLabel = document.createElement( 'div' );
		methodLabel.className = 'lps-pickup-method-label';
		methodLabel.textContent = groupLabel( radios );

		const picker = document.createElement( 'div' );
				picker.className = 'lps-location-picker';
		const select = document.createElement( 'select' );
		select.id = 'lps-pickup-location';
		select.setAttribute( 'aria-label', window.lpsCheckout ? window.lpsCheckout.pickupLocation : 'Pickup location' );
		select.className = 'wc-block-components-select__select';

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
		} );

		if ( ! radios.some( function ( radio ) { return radio.checked; } ) ) {
			select.value = '';
		}

		select.addEventListener( 'change', function () {
			selectRate( select.value );
		} );

		picker.appendChild( select );
		wrapper.appendChild( methodLabel );
		wrapper.appendChild( picker );
		syncSelector( wrapper, radios );

		if ( firstRow && parent ) {
			parent.insertBefore( wrapper, firstRow );
		}
	}

	document.addEventListener( 'DOMContentLoaded', renderSelector );
	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( 'input[type="radio"]' ) ) {
			renderSelector();
		}
	} );
	new MutationObserver( renderSelector ).observe( document.documentElement, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'checked' ],
	} );

	// Exposed for the Node test suite only; unreachable in the browser since
	// `module` is never defined there.
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = {
			findPickupRadios: findPickupRadios,
			pickupRow: pickupRow,
			optionLabel: optionLabel,
			instanceId: instanceId,
			locationId: locationId,
			groupLabel: groupLabel,
			optionText: optionText,
			renderSelector: renderSelector,
		};
	}
}() );
