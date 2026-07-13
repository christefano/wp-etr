/* Event Tickets Registrations — accessible tab switching. No dependencies.
   Deep-link hash is the canonical "#tab-{id}" form (legacy "#etr-tab-{id}"
   and bare "#registrations" fragments still work on load - see initTabs()).
   Also mirrors the active tab onto document.body[data-etr-active-tab] so
   etr-registrations.css can hide the TEC/ET page widgets on non-Details
   tabs. */
( function () {
	'use strict';

	function initTabs( root ) {
		var tabs   = Array.prototype.slice.call( root.querySelectorAll( '[role="tab"]' ) );
		var panels = Array.prototype.slice.call( root.querySelectorAll( '[role="tabpanel"]' ) );

		// Tab buttons keep their DOM ids "etr-tab-details" / "etr-tab-registrations"
		// / "etr-tab-{id}" for filter-added tabs (see class-etr-plugin.php's
		// apply_event_tabs_filter()) - those never change. The address-bar
		// hash they publish, however, is the shorter canonical "#tab-{id}"
		// form (activate()'s updateHash below writes it via replaceState),
		// so a direct link to #tab-round-entry (or any other tab) opens that
		// tab on load, and links elsewhere in the site that jump to a
		// specific tab keep working after the visitor switches tabs by hand.
		//
		// On the real tabbed path (a "Details" tab is present; see
		// render_tab_markup()) activate() also mirrors the active tab's id
		// onto document.body.dataset.etrActiveTab, which etr-registrations.css
		// uses to hide the TEC "Add to calendar" widget and the Event
		// Tickets "Get Tickets" form on every tab except Details - both
		// render outside the tab panels themselves, so they can't be hidden
		// by the panels' own [hidden] attribute. The fallback path (no
		// Details tab; render_tabs_fallback()) deliberately never touches
		// that body attribute - see drivesBodyAttr below.
		var drivesBodyAttr = ! root.closest( '.etr-registrations-fallback' );

		function activate( tab, updateHash ) {
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
			var bareId = tab.id ? tab.id.replace( /^etr-tab-/, '' ) : '';
			if ( drivesBodyAttr && bareId ) {
				document.body.setAttribute( 'data-etr-active-tab', bareId );
			}
			if ( updateHash && bareId ) {
				var newHash = '#tab-' + bareId;
				if ( window.location.hash !== newHash && window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', newHash );
				}
			}
		}

		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () {
				activate( tab, true );
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
					activate( next, true );
				}
			} );
		} );

		// Deep link on load: "#tab-{id}" (the canonical permalink form
		// activate() above writes via replaceState()) opens that tab
		// directly. Also accepts the older "#etr-tab-{id}" form (written by
		// earlier ETR versions, and still matching the buttons' DOM ids
		// as-is) and the original hardcoded "#registrations" fragment
		// predating tab permalinks entirely, so old bookmarks/links keep
		// working.
		var hash = window.location.hash ? window.location.hash.slice( 1 ) : '';
		var initialTab = null;
		if ( hash.indexOf( 'tab-' ) === 0 && hash.indexOf( 'etr-tab-' ) !== 0 ) {
			initialTab = root.querySelector( '#etr-tab-' + hash.slice( 4 ) );
		} else if ( hash.indexOf( 'etr-tab-' ) === 0 ) {
			initialTab = root.querySelector( '#' + hash );
		} else if ( hash === 'registrations' ) {
			initialTab = root.querySelector( '#etr-tab-registrations' );
		}
		// No matching hash: fall back to whichever tab the server already
		// marked active (always the first tab - see render_tab_markup()), so
		// activate() still runs once to set the body attribute above for the
		// default Details tab (drivesBodyAttr paths only; harmless no-op
		// otherwise since it just mirrors the already-server-rendered state).
		if ( ! initialTab ) {
			initialTab = tabs.filter( function ( t ) { return t.classList.contains( 'is-active' ); } )[ 0 ] || tabs[ 0 ];
		}
		if ( initialTab ) {
			activate( initialTab, false );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.slice
			.call( document.querySelectorAll( '[data-etr-tabs]' ) )
			.forEach( initTabs );
	} );

	// Print button: open a clean window with just the registrations tables.
	// This print window is a standalone document (opened with document.write),
	// so it never sees etr-registrations.css - every rule the printed sheet
	// needs, including avatar sizing, has to live here. No rule hides
	// .etr-col-photo, so the avatar column (when the section table has one)
	// prints along with everything else.
	var PRINT_CSS =
		'body{font-family:system-ui,Arial,sans-serif;margin:24px;color:#111}' +
		'h3{margin:0 0 .3em;font-size:15pt}' +
		'.etr-section-count{color:#666;font-weight:400;font-size:.85em}' +
		'.etr-section{margin:0 0 22px;page-break-inside:avoid}' +
		'table{width:100%;border-collapse:collapse}' +
		'th,td{text-align:left;padding:4px 8px;border-bottom:1px solid #ccc}' +
		'.etr-col-num,.etr-col-rating{text-align:right}' +
		'.etr-col-photo{width:36px}' +
		'.etr-avatar{display:block;width:32px;height:32px;border-radius:50%;object-fit:cover}' +
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
