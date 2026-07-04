/* Event Tickets Registrations — accessible tab switching. No dependencies. */
( function () {
	'use strict';

	function initTabs( root ) {
		var tabs   = Array.prototype.slice.call( root.querySelectorAll( '[role="tab"]' ) );
		var panels = Array.prototype.slice.call( root.querySelectorAll( '[role="tabpanel"]' ) );

		function activate( tab ) {
			tabs.forEach( function ( t ) {
				var selected = t === tab;
				t.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				t.classList.toggle( 'is-active', selected );
				t.tabIndex = selected ? 0 : -1;
			} );
			panels.forEach( function ( p ) {
				var show = p.id === tab.getAttribute( 'aria-controls' );
				p.classList.toggle( 'is-active', show );
				if ( show ) {
					p.removeAttribute( 'hidden' );
				} else {
					p.setAttribute( 'hidden', '' );
				}
			} );
		}

		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () {
				activate( tab );
			} );
			tab.addEventListener( 'keydown', function ( e ) {
				var next = null;
				if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
					next = tabs[ ( i + 1 ) % tabs.length ];
				} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
					next = tabs[ ( i - 1 + tabs.length ) % tabs.length ];
				}
				if ( next ) {
					e.preventDefault();
					next.focus();
					activate( next );
				}
			} );
		} );

		// Deep link: /events/slug/#registrations opens the Registrations tab.
		if ( window.location.hash === '#registrations' ) {
			var regTab = root.querySelector( '#etr-tab-registrations' );
			if ( regTab ) {
				activate( regTab );
			}
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice
			.call( document.querySelectorAll( '[data-etr-tabs]' ) )
			.forEach( initTabs );
	} );

	// Print button: open a clean window with just the registrations tables.
	var PRINT_CSS =
		'body{font-family:system-ui,Arial,sans-serif;margin:24px;color:#111}' +
		'h3{margin:0 0 .3em;font-size:15pt}' +
		'.etr-section-count{color:#666;font-weight:400;font-size:.85em}' +
		'.etr-section{margin:0 0 22px;page-break-inside:avoid}' +
		'table{width:100%;border-collapse:collapse}' +
		'th,td{text-align:left;padding:4px 8px;border-bottom:1px solid #ccc}' +
		'.etr-col-num,.etr-col-rating{text-align:right}' +
		'.etr-total{margin-top:14px;font-weight:700}' +
		'a{color:inherit;text-decoration:none}' +
		'@media print{.etr-section{page-break-after:always}.etr-section:last-child{page-break-after:auto}}';

	function printRegistrations( source ) {
		var clone = source.cloneNode( true );
		Array.prototype.slice
			.call( clone.querySelectorAll( '.etr-toolbar, .no-print' ) )
			.forEach( function ( el ) { el.remove(); } );

		var win = window.open( '', '_blank', 'width=920,height=740' );
		if ( ! win ) { return; }
		var title = document.title || 'Registrations';
		win.document.write(
			'<!DOCTYPE html><html><head><meta charset="utf-8"><title>' +
			title.replace( /[<>]/g, '' ) +
			'</title><style>' + PRINT_CSS + '</style></head><body>' +
			clone.outerHTML + '</body></html>'
		);
		win.document.close();
		win.focus();
		win.onload = function () { win.print(); };
		setTimeout( function () { try { win.print(); } catch ( e ) {} }, 400 );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-etr-print]' ) : null;
		if ( ! btn ) { return; }
		var container = btn.closest( '.etr-registrations' );
		if ( container ) { printRegistrations( container ); }
	} );

	// Player cards use the native Popover API (popovertarget) — no JS needed
	// for open/close/Esc/outside-click/focus.

	// No-show toggle (editor-only). Posts to admin-ajax; updates the button and
	// the matching table row optimistically. Seeds/totals settle on next load.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-etr-toggle]' ) : null;
		if ( ! btn ) { return; }
		if ( typeof window.etrData === 'undefined' ) { return; }

		var id   = btn.getAttribute( 'data-etr-id' );
		var next = btn.getAttribute( 'data-etr-status' ) === 'noshow' ? '' : 'noshow';

		btn.disabled = true;
		fetch( window.etrData.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( {
				action: 'etr_toggle_noshow',
				attendee: id,
				status: next,
				_wpnonce: window.etrData.nonce
			} )
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				btn.disabled = false;
				if ( ! res || ! res.success ) { return; }
				var st = res.data.status;
				btn.setAttribute( 'data-etr-status', st );
				btn.textContent = st === 'noshow'
					? btn.getAttribute( 'data-label-clear' )
					: btn.getAttribute( 'data-label-mark' );
				var row = document.querySelector( '[data-etr-row="' + id + '"]' );
				if ( row ) { row.classList.toggle( 'etr-row--noshow', st === 'noshow' ); }
			} )
			.catch( function () { btn.disabled = false; } );
	} );
} )();
