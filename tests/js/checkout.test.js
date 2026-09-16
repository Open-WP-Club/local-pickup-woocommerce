'use strict';

const { test } = require( 'node:test' );
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

function loadCheckout( markup, lpsCheckout ) {
	const dom = new JSDOM( `<!doctype html><html><body>${ markup }</body></html>` );

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
