/**
 * OpenVault Backup: History page actions (download list, lock, unlock, cancel, delete).
 * Plain ES5, no dependencies. Every action goes through the REST API with the REST nonce.
 */
( function () {
	'use strict';

	var cfg = window.ovbHistory;
	var table = document.getElementById( 'ovb-history' );
	if ( ! cfg || ! table ) {
		return;
	}

	var t = cfg.i18n || {};
	var notice = document.getElementById( 'ovb-history-notice' );
	var wrap = table.closest ? table.closest( '.ovb-wrap' ) : null;

	if ( wrap ) {
		// Without JavaScript the download lists stay open; with it they open on demand.
		wrap.className += ' ovb-js';
	}

	function say( message, isError ) {
		if ( ! notice ) {
			return;
		}
		notice.getElementsByTagName( 'p' )[ 0 ].textContent = message;
		notice.className = 'notice inline ' + ( isError ? 'notice-error' : 'notice-info' );
		notice.removeAttribute( 'hidden' );
	}

	function request( method, path, body ) {
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' }
		};
		if ( body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}
		return window.fetch( cfg.restUrl + path, options ).then( function ( response ) {
			return response.json().then(
				function ( data ) {
					return { ok: response.ok, data: data };
				},
				function () {
					return { ok: false, data: {} };
				}
			);
		} );
	}

	function run( link, method, path, body ) {
		say( t.working, false );
		link.setAttribute( 'aria-disabled', 'true' );
		request( method, path, body ).then(
			function ( result ) {
				if ( result.ok ) {
					window.location.reload();
					return;
				}
				link.removeAttribute( 'aria-disabled' );
				say( result.data.message || t.error, true );
			},
			function () {
				link.removeAttribute( 'aria-disabled' );
				say( t.error, true );
			}
		);
	}

	if ( ! window.fetch || ! window.JSON ) {
		say( t.noFetch, true );
		return;
	}

	table.addEventListener( 'click', function ( event ) {
		var link = event.target;
		while ( link && link !== table && ! ( link.getAttribute && link.getAttribute( 'data-ovb-action' ) ) ) {
			link = link.parentNode;
		}
		if ( ! link || link === table || link.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}

		var action = link.getAttribute( 'data-ovb-action' );
		var id = link.getAttribute( 'data-ovb-id' );
		event.preventDefault();

		if ( action === 'downloads' ) {
			var box = document.getElementById( 'ovb-dl-' + id );
			if ( box ) {
				var open = box.className.indexOf( 'is-open' ) === -1;
				box.className = 'ovb-downloads' + ( open ? ' is-open' : '' );
				link.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}
		} else if ( action === 'lock' ) {
			run( link, 'POST', 'backups/' + id + '/lock', { locked: link.getAttribute( 'data-ovb-locked' ) === '1' } );
		} else if ( action === 'cancel' ) {
			if ( window.confirm( t.confirmCancel ) ) {
				run( link, 'POST', 'backups/' + id + '/cancel' );
			}
		} else if ( action === 'delete' ) {
			if ( window.confirm( t.confirmDelete ) ) {
				run( link, 'DELETE', 'backups/' + id );
			}
		}
	} );
}() );
