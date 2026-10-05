'use strict';

const { test, afterEach } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const CHECKOUT_MODULE_PATH = path.join( __dirname, '..', '..', 'assets', 'js', 'checkout.js' );

const BLOCK_MARKUP = `
	<div class="wp-block-woocommerce-checkout-pickup-options-block">
		<fieldset>
			<div class="wc-block-components-radio-control__option">
				<input type="radio" name="radio-control" id="radio-1" value="lps_local_pickup:1:20" checked>
				<label for="radio-1">Rodina</label>
			</div>
			<div class="wc-block-components-radio-control__option">
				<input type="radio" name="radio-control" id="radio-2" value="lps_local_pickup:1:21">
				<label for="radio-2">Charodeyka</label>
			</div>
			<div class="wc-block-components-radio-control__option">
				<input type="radio" name="radio-control" id="radio-3" value="other_pickup_method:5:99">
				<label for="radio-3">Some other pickup method</label>
			</div>
		</fieldset>
	</div>
`;

const CLASSIC_MARKUP = `
	<ul id="shipping_method" class="woocommerce-shipping-methods">
		<li>
			<input type="radio" name="shipping_method[0]" id="shipping_method_0_lps_local_pickup:1:20" value="lps_local_pickup:1:20" class="shipping_method" checked>
			<label for="shipping_method_0_lps_local_pickup:1:20">Rodina: Free</label>
		</li>
		<li>
			<input type="radio" name="shipping_method[0]" id="shipping_method_0_lps_local_pickup:1:21" value="lps_local_pickup:1:21" class="shipping_method">
			<label for="shipping_method_0_lps_local_pickup:1:21">Charodeyka: Free</label>
		</li>
		<li>
			<input type="radio" name="shipping_method[0]" id="shipping_method_0_econt_office:2" value="econt_office:2" class="shipping_method">
			<label for="shipping_method_0_econt_office:2">Econt office</label>
		</li>
	</ul>
`;

const LPS_CHECKOUT_DATA = {
	pickupLocation: 'Pickup location',
	selectLocation: 'Select a pickup location',
	methodTitles: { 1: 'Store pickup' },
	showPrice: { 1: true },
	locations: {
		20: { name: 'Rodina', price: 'Free' },
		21: { name: 'Charodeyka', price: 'Free' },
	},
};

let activeDom;
afterEach( () => { if ( activeDom ) { activeDom.window.close(); } } );

function loadCheckout( markup, lpsCheckout ) {
	const dom = new JSDOM( `<!doctype html><html><body>${ markup }</body></html>` );

	activeDom = dom;
	global.window = dom.window;
	global.document = dom.window.document;
	global.CSS = dom.window.CSS;
	global.MutationObserver = dom.window.MutationObserver;
	global.window.lpsCheckout = lpsCheckout;

	delete require.cache[ require.resolve( CHECKOUT_MODULE_PATH ) ];
	const checkout = require( CHECKOUT_MODULE_PATH );

	return { dom, document: dom.window.document, checkout };
}

test( 'findPickupRadios only matches lps_local_pickup rates, in block markup', () => {
	const { checkout } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );
	const radios = checkout.findPickupRadios();

	assert.deepEqual(
		radios.map( ( radio ) => radio.value ),
		[ 'lps_local_pickup:1:20', 'lps_local_pickup:1:21' ]
	);
} );

test( 'findPickupRadios only matches lps_local_pickup rates, in classic #shipping_method markup', () => {
	const { checkout } = loadCheckout( CLASSIC_MARKUP, LPS_CHECKOUT_DATA );
	const radios = checkout.findPickupRadios();

	assert.deepEqual(
		radios.map( ( radio ) => radio.value ),
		[ 'lps_local_pickup:1:20', 'lps_local_pickup:1:21' ]
	);
} );

test( 'pickupRow finds the block radio-control option wrapper', () => {
	const { checkout, document } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );
	const radio = document.getElementById( 'radio-1' );
	const row = checkout.pickupRow( radio );

	assert.equal( row.className, 'wc-block-components-radio-control__option' );
} );

test( 'pickupRow falls back to the <li> in classic checkout markup', () => {
	const { checkout, document } = loadCheckout( CLASSIC_MARKUP, LPS_CHECKOUT_DATA );
	const radio = document.getElementById( 'shipping_method_0_lps_local_pickup:1:20' );
	const row = checkout.pickupRow( radio );

	assert.equal( row.tagName, 'LI' );
} );

test( 'instanceId and locationId parse the rate id', () => {
	const { checkout, document } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );
	const radio = document.getElementById( 'radio-2' );

	assert.equal( checkout.instanceId( radio ), '1' );
	assert.equal( checkout.locationId( radio ), '21' );
} );

test( 'optionText prefers localized location data and falls back to the visible label', () => {
	const { checkout, document } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );
	const known = document.getElementById( 'radio-1' );
	const unknown = document.getElementById( 'radio-3' );

	assert.equal( checkout.optionText( known ), 'Rodina — Free' );
	assert.equal( checkout.optionText( unknown ), 'Some other pickup method' );
} );

test( 'groupLabel uses the method title, falling back to the generic pickup label', () => {
	const { checkout, document } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );
	const radios = [ document.getElementById( 'radio-1' ) ];

	assert.equal( checkout.groupLabel( radios ), 'Store pickup' );
	assert.equal(
		checkout.groupLabel( [ document.getElementById( 'radio-3' ) ] ),
		'Pickup location'
	);
} );

test( 'renderSelector collapses classic checkout rows into one dropdown', () => {
	const { checkout, document } = loadCheckout( CLASSIC_MARKUP, LPS_CHECKOUT_DATA );

	checkout.renderSelector();

	const wrapper = document.querySelector( '.lps-pickup-selector' );
	assert.ok( wrapper, 'wrapper was inserted' );
	assert.equal( wrapper.tagName, 'LI', 'wrapper is a <li> inside the <ul>' );
	assert.equal( wrapper.parentNode, document.getElementById( 'shipping_method' ) );

	const select = wrapper.querySelector( 'select' );
	const optionValues = Array.from( select.options ).map( ( option ) => option.value );
	assert.deepEqual( optionValues, [ '', 'lps_local_pickup:1:20', 'lps_local_pickup:1:21' ] );
	assert.equal( select.value, 'lps_local_pickup:1:20', 'preselects the checked rate' );

	const rodinaRow = document.getElementById( 'shipping_method_0_lps_local_pickup:1:20' ).closest( 'li' );
	const charodeykaRow = document.getElementById( 'shipping_method_0_lps_local_pickup:1:21' ).closest( 'li' );
	const econtRow = document.getElementById( 'shipping_method_0_econt_office:2' ).closest( 'li' );

	assert.ok( rodinaRow.classList.contains( 'lps-native-rate' ) );
	assert.ok( charodeykaRow.classList.contains( 'lps-native-rate' ) );
	assert.ok( ! econtRow.classList.contains( 'lps-native-rate' ), 'unrelated rates are left alone' );
} );

test( 'renderSelector force-hides native rows with inline !important, so higher-specificity theme CSS cannot override it', () => {
	const { checkout, document } = loadCheckout( CLASSIC_MARKUP, LPS_CHECKOUT_DATA );

	const style = document.createElement( 'style' );
	style.textContent = '#shipping_method li { display: flex !important; }';
	document.head.appendChild( style );

	checkout.renderSelector();

	const rodinaRow = document.getElementById( 'shipping_method_0_lps_local_pickup:1:20' ).closest( 'li' );
	const econtRow = document.getElementById( 'shipping_method_0_econt_office:2' ).closest( 'li' );

	assert.equal( rodinaRow.style.getPropertyValue( 'display' ), 'none' );
	assert.equal( rodinaRow.style.getPropertyPriority( 'display' ), 'important' );
	assert.equal( econtRow.style.getPropertyValue( 'display' ), '', 'unrelated rates get no inline override' );
} );

test( 'renderSelector collapses block checkout rows into one dropdown', () => {
	const { checkout, document } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );

	checkout.renderSelector();

	const wrapper = document.querySelector( '.lps-pickup-selector' );
	assert.ok( wrapper, 'wrapper was inserted' );
	assert.equal( wrapper.tagName, 'DIV', 'wrapper is a <div> outside a list' );

	const firstOption = document.querySelector( '.wc-block-components-radio-control__option' );
	assert.equal( wrapper.nextElementSibling, firstOption, 'wrapper sits right before the native options' );

	const select = wrapper.querySelector( 'select' );
	assert.equal( select.value, 'lps_local_pickup:1:20' );

	const otherMethodRow = document.getElementById( 'radio-3' ).closest( '.wc-block-components-radio-control__option' );
	assert.ok( ! otherMethodRow.classList.contains( 'lps-native-rate' ), 'rates from other methods stay visible' );
} );

test( 'choosing a dropdown option clicks the matching native radio and keeps them in sync', () => {
	const { checkout, document } = loadCheckout( CLASSIC_MARKUP, LPS_CHECKOUT_DATA );

	checkout.renderSelector();

	const rodina = document.getElementById( 'shipping_method_0_lps_local_pickup:1:20' );
	const charodeyka = document.getElementById( 'shipping_method_0_lps_local_pickup:1:21' );

	let charodeykaChanged = false;
	charodeyka.addEventListener( 'change', () => {
		charodeykaChanged = true;
	} );

	const select = document.querySelector( '.lps-pickup-selector select' );
	select.value = 'lps_local_pickup:1:21';
	select.dispatchEvent( new document.defaultView.Event( 'change' ) );

	assert.ok( charodeykaChanged, 'the native radio received a real change event' );
	assert.equal( charodeyka.checked, true );
	assert.equal( rodina.checked, false );
} );

for ( const [ name, markup, otherId ] of [
	[ 'block', BLOCK_MARKUP, 'radio-3' ],
	[ 'classic', CLASSIC_MARKUP, 'shipping_method_0_econt_office:2' ],
] ) {
	test( `${ name } checkout shows a tickless heading and an always-visible store picker`, () => {
		const { checkout, document } = loadCheckout( markup, LPS_CHECKOUT_DATA );
		const other = document.getElementById( otherId );
		other.checked = true;
		checkout.renderSelector();

		const heading = document.querySelector( '.lps-pickup-method-label' );
		const picker = document.querySelector( '.lps-location-picker' );
		const select = picker.querySelector( 'select' );
		assert.equal( heading.textContent, 'Store pickup' );
		assert.equal( document.querySelector( '.lps-pickup-selector input[type="radio"]' ), null, 'no tick before the heading' );
		assert.equal( picker.hidden, false );
		assert.equal( select.disabled, false );
		assert.equal( select.required, false );
		assert.equal( select.value, '' );

		select.value = 'lps_local_pickup:1:21';
		select.dispatchEvent( new document.defaultView.Event( 'change', { bubbles: true } ) );
		assert.equal( checkout.findPickupRadios()[ 1 ].checked, true );
		assert.equal( other.checked, false );
		assert.equal( select.required, true );

		other.click();
		assert.equal( select.value, '', 'picker resets when another method is chosen' );
		assert.equal( select.required, false );
		assert.ok( checkout.findPickupRadios().every( ( radio ) => ! radio.checked ) );
	} );
}

test( 'picker selects the live native rate after WooCommerce replaces rate inputs', () => {
	const { checkout, document } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );
	checkout.renderSelector();
	const select = document.querySelector( '.lps-pickup-selector select' );
	const original = document.getElementById( 'radio-2' );
	const replacement = original.cloneNode( true );
	original.replaceWith( replacement );
	checkout.renderSelector();

	let changed = false;
	replacement.addEventListener( 'change', () => { changed = true; } );
	select.value = replacement.value;
	select.dispatchEvent( new document.defaultView.Event( 'change', { bubbles: true } ) );
	assert.equal( changed, true );
	assert.equal( replacement.checked, true );
	assert.equal( original.checked, false );
	assert.equal( document.querySelectorAll( '.lps-pickup-selector' ).length, 1 );
} );

// Allow the MutationObserver and DOMContentLoaded handlers to settle.
function settle( dom ) {
	return new Promise( ( resolve ) => dom.window.setTimeout( resolve, 0 ) );
}

test( 'checkout without pickup rates renders no picker', () => {
	const { checkout, document } = loadCheckout( '<input type="radio" value="flat_rate:1" checked>', LPS_CHECKOUT_DATA );
	checkout.renderSelector();
	assert.equal( document.querySelector( '.lps-pickup-selector' ), null );
} );

test( 'price visibility changes labels without changing the selected rate', () => {
	const data = { ...LPS_CHECKOUT_DATA, showPrice: { 1: false } };
	const { checkout, document } = loadCheckout( BLOCK_MARKUP, data );
	checkout.renderSelector();
	let select = document.querySelector( '.lps-pickup-selector select' );
	assert.deepEqual( Array.from( select.options ).slice( 1 ).map( ( option ) => option.textContent ), [ 'Rodina', 'Charodeyka' ] );
	data.showPrice[ 1 ] = true;
	checkout.renderSelector();
	select = document.querySelector( '.lps-pickup-selector select' );
	assert.equal( select.options[ 1 ].textContent, 'Rodina — Free' );
	assert.equal( select.value, 'lps_local_pickup:1:20' );
	assert.equal( document.querySelectorAll( '.lps-pickup-selector' ).length, 1 );
} );

test( 'missing localization falls back to native labels and accessible English labels', () => {
	const { checkout, document } = loadCheckout( BLOCK_MARKUP, undefined );
	checkout.renderSelector();
	const select = document.querySelector( '.lps-pickup-selector select' );
	assert.equal( select.options[ 1 ].textContent, 'Rodina' );
	assert.equal( select.options[ 0 ].textContent, 'Select a pickup location' );
	assert.equal( select.getAttribute( 'aria-label' ), 'Pickup location' );
	assert.equal( document.querySelector( '.lps-pickup-method-label' ).textContent, 'Pickup location' );
} );

test( 'store names are inserted as text instead of HTML', () => {
	const data = { ...LPS_CHECKOUT_DATA, locations: { 20: { name: '<img src=x onerror=alert(1)>', price: 'Free' } } };
	const { checkout, document } = loadCheckout( BLOCK_MARKUP, data );
	checkout.renderSelector();
	assert.equal( document.querySelector( '.lps-pickup-selector img' ), null );
	assert.equal( document.querySelector( 'select' ).options[ 1 ].textContent, '<img src=x onerror=alert(1)> — Free' );
} );

test( 'observer removes the picker when the available pickup rates disappear', async () => {
	const { checkout, document, dom } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );
	await settle( dom );
	assert.ok( document.querySelector( '.lps-pickup-selector' ) );
	checkout.findPickupRadios().forEach( ( radio ) => radio.closest( '.wc-block-components-radio-control__option' ).remove() );
	await settle( dom );
	assert.equal( document.querySelector( '.lps-pickup-selector' ), null );
	assert.ok( document.getElementById( 'radio-3' ) );
} );

test( 'observer restores a single working picker after a complete checkout rerender', async () => {
	const { document, dom } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );
	await settle( dom );
	document.body.innerHTML = BLOCK_MARKUP.replace( ' checked', '' ).replace( 'value="lps_local_pickup:1:21"', 'value="lps_local_pickup:1:21" checked' );
	await settle( dom );
	assert.equal( document.querySelectorAll( '.lps-pickup-selector' ).length, 1 );
	const select = document.querySelector( '.lps-pickup-selector select' );
	assert.equal( select.value, 'lps_local_pickup:1:21' );
	assert.equal( select.parentNode.hidden, false );
	select.value = 'lps_local_pickup:1:20';
	select.dispatchEvent( new dom.window.Event( 'change', { bubbles: true } ) );
	assert.equal( document.getElementById( 'radio-1' ).checked, true );
} );

test( 'observer updates stores and selection when the selected store is removed', async () => {
	const { document, dom } = loadCheckout( BLOCK_MARKUP, LPS_CHECKOUT_DATA );
	await settle( dom );
	document.getElementById( 'radio-1' ).closest( '.wc-block-components-radio-control__option' ).remove();
	document.getElementById( 'radio-2' ).checked = true;
	await settle( dom );
	const select = document.querySelector( '.lps-pickup-selector select' );
	assert.deepEqual( Array.from( select.options ).map( ( option ) => option.value ), [ '', 'lps_local_pickup:1:21' ] );
	assert.equal( select.value, 'lps_local_pickup:1:21' );
} );

test( 'theme-rendered rate buttons (input + label in plain divs) are hidden', () => {
	const markup = '<div id="shipping_method"><div class="btn"><input type="radio" id="r1" value="lps_local_pickup:1:20" checked><label for="r1"><span>Rodina</span></label></div></div>';
	const { checkout, document } = loadCheckout( markup, LPS_CHECKOUT_DATA );
	checkout.renderSelector();
	assert.equal( document.querySelector( 'label[for="r1"]' ).style.display, 'none' );
	assert.equal( document.querySelector( '.btn' ).style.display, 'none' );
	assert.equal( document.getElementById( 'r1' ).style.display, 'none' );
} );
