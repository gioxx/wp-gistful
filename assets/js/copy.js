/**
 * Gistful for GitHub - copy buttons.
 *
 * Adds a "Copy" button to every file of the gists flagged with data-copy="1"
 * and a "Copy all" button to gists with more than one file. The text comes
 * from the code cells the visitor sees (only the lines shown, no line
 * numbers), so it matches the gist source. Without JavaScript nothing is
 * added. Labels come from window.gistfulCopy (set by the plugin).
 */
( function () {
	'use strict';

	var labels = Object.assign(
		{
			copy: 'Copy',
			copyAll: 'Copy all',
			copied: 'Copied',
			failed: 'Copy failed',
			copyFile: 'Copy %s',
			copyAllLabel: 'Copy all files',
		},
		( typeof window !== 'undefined' && window.gistfulCopy ) || {}
	);

	/**
	 * Code of one file: the text of its code cells, one line each.
	 *
	 * @param {Element} file A .gist-file element.
	 * @return {string} Code, or '' when the file has no code cells.
	 */
	function fileText( file ) {
		var cells = file.querySelectorAll( 'td.blob-code' );
		var lines = [];
		for ( var i = 0; i < cells.length; i++ ) {
			lines.push( cells[ i ].textContent.replace( /\r?\n$/, '' ) );
		}
		return lines.length ? lines.join( '\n' ) + '\n' : '';
	}

	/**
	 * Name of a file, from the aria-label the renderer puts on .gist-data.
	 *
	 * @param {Element} file A .gist-file element.
	 * @return {string} File name or ''.
	 */
	function fileName( file ) {
		var data = file.querySelector( '.gist-data' );
		return ( data && data.getAttribute( 'aria-label' ) ) || '';
	}

	/**
	 * Copy text, with a fallback where the Clipboard API is unavailable
	 * (sites without HTTPS, older browsers).
	 *
	 * @param {string} text Text.
	 * @return {Promise} Resolves when copied.
	 */
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.top = '-1000px';
			document.body.appendChild( area );
			area.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( area );
			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'copy' ) );
			}
		} );
	}

	/**
	 * A copy button that shows "Copied" (or "Copy failed") for a moment and
	 * announces it through the gist's live region.
	 *
	 * @param {string}   text   Visible label.
	 * @param {string}   label  Accessible label.
	 * @param {Function} getText Returns the text to copy.
	 * @param {Element}  status Live region.
	 * @return {HTMLButtonElement} Button.
	 */
	function button( text, label, getText, status ) {
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'gistful-copy';
		btn.textContent = text;
		btn.setAttribute( 'aria-label', label );
		btn.addEventListener( 'click', function () {
			var done = function ( message, state ) {
				btn.textContent = message;
				btn.setAttribute( 'data-state', state );
				status.textContent = message;
				window.setTimeout( function () {
					btn.textContent = text;
					btn.removeAttribute( 'data-state' );
					status.textContent = '';
				}, 2000 );
			};
			copyText( getText() ).then(
				function () {
					done( labels.copied, 'copied' );
				},
				function () {
					done( labels.failed, 'failed' );
				}
			);
		} );
		return btn;
	}

	/**
	 * Add the buttons to one gist (once).
	 *
	 * @param {Element} gist A .gistful[data-copy="1"] element.
	 * @return {void}
	 */
	function enhance( gist ) {
		if ( gist.getAttribute( 'data-copy-ready' ) ) {
			return;
		}
		gist.setAttribute( 'data-copy-ready', '1' );

		var status = document.createElement( 'span' );
		status.className = 'gistful-copy-status';
		status.setAttribute( 'role', 'status' );
		gist.appendChild( status );

		var files = Array.prototype.filter.call( gist.querySelectorAll( '.gist-file' ), function ( file ) {
			return '' !== fileText( file );
		} );

		files.forEach( function ( file ) {
			var name = fileName( file );
			var bar = document.createElement( 'div' );
			bar.className = 'gistful-copy-bar';
			bar.appendChild(
				button( labels.copy, name ? labels.copyFile.replace( '%s', name ) : labels.copy, function () {
					return fileText( file );
				}, status )
			);
			file.insertBefore( bar, file.firstChild );
		} );

		if ( files.length > 1 ) {
			var allBar = document.createElement( 'div' );
			allBar.className = 'gistful-copy-bar gistful-copy-bar--all';
			allBar.appendChild(
				button( labels.copyAll, labels.copyAllLabel, function () {
					return files.map( fileText ).join( '\n' );
				}, status )
			);
			gist.insertBefore( allBar, gist.firstChild );
		}
	}

	/**
	 * Enhance every flagged gist in a container.
	 *
	 * @param {ParentNode} root Container (document by default).
	 * @return {void}
	 */
	function init( root ) {
		var gists = ( root || document ).querySelectorAll( '.gistful[data-copy="1"]' );
		for ( var i = 0; i < gists.length; i++ ) {
			enhance( gists[ i ] );
		}
	}

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { init: init, fileText: fileText };
	} else if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init( document );
		} );
	} else {
		init( document );
	}
}() );
