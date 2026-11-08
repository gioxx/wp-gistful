/* Gistful migration page. Vanilla JS on top of wp.apiFetch; no build step. */
( function () {
	'use strict';

	var cfg = window.gistfulMigration;
	if ( ! cfg || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	var t = cfg.i18n;
	var scanData = null;
	var stopFlags = { import: false, cleanup: false };

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return str.replace( /%(\d+\$)?[ds]/g, function ( m, pos ) {
			var idx = pos ? parseInt( pos, 10 ) - 1 : i++;
			return String( args[ idx ] );
		} );
	}

	function el( tag, text, attrs ) {
		var n = document.createElement( tag );
		if ( text ) {
			n.textContent = text;
		}
		Object.keys( attrs || {} ).forEach( function ( k ) {
			n.setAttribute( k, attrs[ k ] );
		} );
		return n;
	}

	function btn( label, onClick, primary ) {
		var b = el( 'button', label, { type: 'button', class: 'button' + ( primary ? ' button-primary' : '' ) } );
		b.addEventListener( 'click', onClick );
		return b;
	}

	function api( path, method ) {
		return window.wp.apiFetch( { path: '/' + cfg.base + '/' + path, method: method || 'GET' } );
	}

	function errText( e ) {
		return fmt( t.error, ( e && e.message ) || String( e ) );
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function runScan() {
		var out = document.getElementById( 'gistful-scan-result' );
		out.textContent = t.scanning;
		return api( 'scan' ).then( function ( s ) {
			scanData = s;
			out.textContent = fmt( t.scanResult, s.posts_with_gists, s.legacy_meta, s.legacy_transients, s.block_posts_convertible, s.legacy_option ? t.yes : t.no );
			renderSteps();
		}, function ( e ) {
			out.textContent = errText( e );
		} );
	}

	/* Import / cleanup: show numbers, ask for a second click, then batch with stop/resume. */
	function importDone() {
		var st = ( scanData.state || {} ).import;
		return scanData.legacy_meta === 0 || ( !! st && null === st.next );
	}

	function renderBatchStep( key, askText, hasWork, forced ) {
		var box = document.getElementById( 'gistful-' + key );
		var force = !! forced;
		clear( box );
		var state = ( scanData.state || {} )[ key ];
		var resumeAt = state && null !== state.next && undefined !== state.next ? state.next : null;
		if ( ! hasWork && null === resumeAt ) {
			box.appendChild( el( 'p', t.nothing ) );
			return;
		}
		if ( 'cleanup' === key && ! force && ! importDone() ) {
			box.appendChild( el( 'p', t.importPending ) );
			box.appendChild( btn( t.cleanupAnyway, function () {
				renderBatchStep( key, askText, hasWork, true );
			} ) );
			return;
		}
		box.appendChild( el( 'p', askText ) );
		var status = el( 'p', '', { 'aria-live': 'polite' } );
		var actions = el( 'p' );
		var start = btn( t.confirm, function () {
			begin( 0 );
		}, true );
		var cancel = btn( t.cancel, function () {
			clear( box );
		} );
		actions.appendChild( start );
		actions.appendChild( cancel );
		if ( null !== resumeAt ) {
			actions.appendChild( btn( t.resume, function () {
				begin( resumeAt );
			} ) );
		}
		box.appendChild( actions );
		box.appendChild( status );

		function begin( offset ) {
			stopFlags[ key ] = false;
			clear( actions );
			actions.appendChild( btn( t.stop, function () {
				stopFlags[ key ] = true;
			} ) );
			step( offset, 0 );
		}

		function post( offset ) {
			return window.wp.apiFetch( {
				path: '/' + cfg.base + '/' + key + '?offset=' + offset + '&limit=50' + ( force ? '&force=1' : '' ),
				method: 'POST',
			} );
		}

		function step( offset, total ) {
			post( offset ).then( function ( r ) {
				total += r.processed;
				status.textContent = fmt( t.progress, total );
				if ( null === r.next || undefined === r.next ) {
					status.textContent = t.done;
					clear( actions );
					if ( 'import' === key ) {
						scanData.state = scanData.state || {};
						scanData.state.import = { next: null };
						renderCleanupStep();
					}
					return;
				}
				if ( stopFlags[ key ] ) {
					status.textContent = t.stopped;
					clear( actions );
					actions.appendChild( btn( t.resume, function () {
						begin( r.next );
					} ) );
					return;
				}
				step( r.next, total );
			}, function ( e ) {
				status.textContent = errText( e );
				clear( actions );
			} );
		}
	}

	function renderConvert() {
		var box = document.getElementById( 'gistful-convert' );
		clear( box );
		var ids = scanData.convertible || [];
		if ( ! ids.length ) {
			box.appendChild( el( 'p', t.nothing ) );
			return;
		}
		var list = el( 'ul' );
		ids.forEach( function ( id ) {
			var li = el( 'li' );
			var detail = el( 'div' );
			li.appendChild( el( 'strong', fmt( t.postLabel, id ) + ' ' ) );
			li.appendChild( btn( t.preview, function () {
				showPreview( id, detail );
			} ) );
			li.appendChild( detail );
			list.appendChild( li );
		} );
		box.appendChild( list );
	}

	function showPreview( id, detail ) {
		clear( detail );
		api( 'preview/' + id ).then( function ( p ) {
			var wrap = el( 'div', '', { style: 'display:flex;gap:1em;flex-wrap:wrap' } );
			[ [ t.before, p.before ], [ t.after, p.after ] ].forEach( function ( col ) {
				var c = el( 'div', '', { style: 'flex:1 1 300px;min-width:0' } );
				c.appendChild( el( 'h4', col[ 0 ] ) );
				c.appendChild( el( 'pre', col[ 1 ], { style: 'max-height:300px;overflow:auto;white-space:pre-wrap;background:#fff;padding:8px;border:1px solid #c3c4c7' } ) );
				wrap.appendChild( c );
			} );
			detail.appendChild( wrap );
			var status = el( 'p', '', { 'aria-live': 'polite' } );
			var actions = el( 'p' );
			actions.appendChild( btn( t.convertConfirm, function () {
				api( 'convert/' + id + '?hash=' + encodeURIComponent( p.hash ), 'POST' ).then( function ( r ) {
					clear( detail );
					detail.appendChild( el( 'p', r.converted ? t.converted : t.unchanged ) );
				}, function ( e ) {
					status.textContent = errText( e );
				} );
			}, true ) );
			actions.appendChild( btn( t.cancel, function () {
				clear( detail );
			} ) );
			detail.appendChild( actions );
			detail.appendChild( status );
		}, function ( e ) {
			detail.appendChild( el( 'p', errText( e ) ) );
		} );
	}

	function renderCleanupStep() {
		renderBatchStep(
			'cleanup',
			fmt( t.cleanupAsk, scanData.legacy_meta, scanData.legacy_transients ),
			scanData.legacy_meta > 0 || scanData.legacy_transients > 0 || scanData.legacy_option
		);
	}

	function renderSteps() {
		renderBatchStep( 'import', fmt( t.importAsk, scanData.legacy_meta ), scanData.legacy_meta > 0 );
		renderCleanupStep();
		renderConvert();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var scanBtn = document.getElementById( 'gistful-scan' );
		if ( scanBtn ) {
			scanBtn.addEventListener( 'click', runScan );
		}
	} );
}() );
