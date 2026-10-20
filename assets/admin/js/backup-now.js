/**
 * OpenVault Backup: "Backup Now" page.
 * Starts a backup over the REST API, polls its progress and shows the result.
 * Plain ES5, no dependencies. The backup keeps running on the server if this page is closed.
 */
( function () {
	'use strict';

	var cfg = window.ovbBackupNow;
	var root = document.getElementById( 'ovb-backup-now' );
	if ( ! cfg || ! root ) {
		return;
	}

	var t = cfg.i18n || {};
	var form = document.getElementById( 'ovb-backup-form' );
	var panel = document.getElementById( 'ovb-progress' );
	var errorBox = document.getElementById( 'ovb-error' );
	var bar = document.getElementById( 'ovb-bar' );
	var percentEl = document.getElementById( 'ovb-percent' );
	var stepEl = document.getElementById( 'ovb-step' );
	var detailEl = document.getElementById( 'ovb-detail' );
	var elapsedEl = document.getElementById( 'ovb-elapsed' );
	var etaEl = document.getElementById( 'ovb-eta' );
	var warnEl = document.getElementById( 'ovb-warnings' );
	var liveEl = document.getElementById( 'ovb-live' );
	var cancelBtn = document.getElementById( 'ovb-cancel' );
	var summary = document.getElementById( 'ovb-summary' );

	var POLL_MS = 2000;
	var backupId = 0;
	var shownPercent = 0;
	var lastLive = '';
	var elapsedBase = 0;
	var elapsedAt = 0;
	var tickTimer = null;
	var pollTimer = null;
	var finished = false;

	function show( el, on ) {
		if ( el ) {
			if ( on ) {
				el.removeAttribute( 'hidden' );
			} else {
				el.setAttribute( 'hidden', 'hidden' );
			}
		}
	}

	function showError( message ) {
		if ( ! errorBox ) {
			return;
		}
		errorBox.getElementsByTagName( 'p' )[ 0 ].textContent = message;
		show( errorBox, !! message );
	}

	function api( method, path, body ) {
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
					return { ok: response.ok, status: response.status, data: data };
				},
				function () {
					return { ok: false, status: response.status, data: {} };
				}
			);
		} );
	}

	function clock( seconds ) {
		var s = Math.max( 0, Math.floor( seconds ) );
		var m = Math.floor( s / 60 );
		var h = Math.floor( m / 60 );
		var pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		return h > 0 ? h + ':' + pad( m % 60 ) + ':' + pad( s % 60 ) : m + ':' + pad( s % 60 );
	}

	function human( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		var v = bytes;
		while ( v >= 1024 && i < units.length - 1 ) {
			v = v / 1024;
			i++;
		}
		return ( i === 0 ? v : v.toFixed( v >= 100 ? 0 : 1 ) ) + ' ' + units[ i ];
	}

	function etaText( seconds ) {
		var text;
		if ( seconds < 60 ) {
			text = Math.max( 5, Math.round( seconds / 5 ) * 5 ) + ' ' + t.sec;
		} else {
			text = Math.round( seconds / 60 ) + ' ' + t.min;
		}
		return '(' + ( t.eta || '%s' ).replace( '%s', t.about + ' ' + text ) + ')';
	}

	function tick() {
		elapsedEl.textContent = clock( elapsedBase + ( Date.now() - elapsedAt ) / 1000 );
	}

	function announce( text ) {
		if ( text !== lastLive ) {
			lastLive = text;
			liveEl.textContent = text;
		}
	}

	function render( data ) {
		var percent = Math.max( shownPercent, data.percent || 0 );
		if ( data.finished && data.status !== 'failed' ) {
			percent = 100;
		}
		shownPercent = percent;
		bar.value = percent;
		percentEl.textContent = percent + '%';
		stepEl.textContent = data.step_label || '';
		detailEl.textContent = data.detail ? '(' + data.detail + ')' : '';
		elapsedBase = data.elapsed_seconds || 0;
		elapsedAt = Date.now();
		tick();
		etaEl.textContent = data.eta_seconds ? etaText( data.eta_seconds ) : '';

		if ( data.warnings > 0 ) {
			warnEl.textContent = ( t.warnings || '%d' ).replace( '%d', data.warnings );
			show( warnEl, true );
		}
		// Keep screen reader chatter low: announce step changes, not every percent.
		announce( data.step_label || '' );
	}

	// One line per destination: its name and the result as text.
	function showSent( destinations ) {
		var box = document.getElementById( 'ovb-sent' );
		var ul = document.getElementById( 'ovb-sent-list' );
		var names = { done: t.sent_done, failed: t.sent_failed, uploading: t.sent_active, pending: t.sent_pending };
		var i;
		var li;
		var line;
		ul.innerHTML = '';
		for ( i = 0; i < destinations.length; i++ ) {
			line = destinations[ i ].label + ': ' + ( names[ destinations[ i ].status ] || destinations[ i ].status );
			if ( destinations[ i ].files_total > 0 ) {
				line += ' (' + destinations[ i ].files_done + '/' + destinations[ i ].files_total + ')';
			}
			if ( destinations[ i ].status === 'failed' && destinations[ i ].error ) {
				line += ' - ' + destinations[ i ].error;
			}
			li = document.createElement( 'li' );
			li.textContent = line;
			ul.appendChild( li );
		}
		show( box, destinations.length > 0 );
	}

	function selectedDestinations() {
		var boxes = form.querySelectorAll( '[data-ovb-destination]' );
		var ids = [];
		var i;
		for ( i = 0; i < boxes.length; i++ ) {
			if ( boxes[ i ].checked && ! boxes[ i ].disabled ) {
				ids.push( parseInt( boxes[ i ].getAttribute( 'data-ovb-destination' ), 10 ) );
			}
		}
		return ids;
	}

	function finish( data ) {
		finished = true;
		window.clearInterval( tickTimer );
		window.clearTimeout( pollTimer );
		show( cancelBtn, false );
		etaEl.textContent = '';

		var title = document.getElementById( 'ovb-summary-title' );
		var text = document.getElementById( 'ovb-summary-text' );
		var list = document.getElementById( 'ovb-downloads' );
		var ok = data.status === 'complete' || data.status === 'partial';

		title.textContent = data.status === 'complete' ? t.done : ( data.status === 'partial' ? t.doneWarn : t.failed );
		if ( ok ) {
			var count = 0;
			var i;
			for ( i = 0; i < data.parts.length; i++ ) {
				if ( data.parts[ i ].kind !== 'manifest' ) {
					count++;
				}
			}
			text.textContent = ( t.summary || '' ).replace( '%1$s', human( data.total_size ) ).replace( '%2$s', count );
			list.innerHTML = '';
			for ( i = 0; i < data.parts.length; i++ ) {
				var li = document.createElement( 'li' );
				var a = document.createElement( 'a' );
				a.href = data.parts[ i ].download_url;
				a.textContent = data.parts[ i ].name + ' (' + human( data.parts[ i ].size ) + ')';
				li.appendChild( a );
				list.appendChild( li );
			}
		} else {
			text.textContent = data.error || data.message || '';
			list.innerHTML = '';
		}
		showSent( data.destinations || [] );
		show( summary, true );
		announce( title.textContent );
		if ( title.focus ) {
			title.setAttribute( 'tabindex', '-1' );
			title.focus();
		}
	}

	function poll() {
		api( 'GET', 'backups/' + backupId + '/progress' ).then(
			function ( result ) {
				if ( ! result.ok ) {
					if ( result.status === 404 ) {
						showError( result.data.message || t.requestError );
						return;
					}
					showError( t.lostContact );
					pollTimer = window.setTimeout( poll, POLL_MS * 2 );
					return;
				}
				showError( '' );
				render( result.data );
				if ( result.data.finished ) {
					finish( result.data );
				} else {
					pollTimer = window.setTimeout( poll, POLL_MS );
				}
			},
			function () {
				showError( t.lostContact );
				pollTimer = window.setTimeout( poll, POLL_MS * 2 );
			}
		);
	}

	function attach( id ) {
		backupId = id;
		finished = false;
		show( form, false );
		show( panel, true );
		show( summary, false );
		show( cancelBtn, true );
		window.clearInterval( tickTimer );
		tickTimer = window.setInterval( tick, 1000 );
		poll();
	}

	function selectedContents() {
		var boxes = form.querySelectorAll( '[data-ovb-content]' );
		var contents = {};
		var any = false;
		var i;
		for ( i = 0; i < boxes.length; i++ ) {
			contents[ boxes[ i ].getAttribute( 'data-ovb-content' ) ] = boxes[ i ].checked;
			any = any || boxes[ i ].checked;
		}
		return any ? contents : null;
	}

	function start( event ) {
		event.preventDefault();
		showError( '' );
		var contents = selectedContents();
		if ( ! contents ) {
			showError( t.nothing );
			return;
		}
		var chosen = form.querySelector( 'input[name="format"]:checked' );
		var body = { contents: contents, note: document.getElementById( 'ovb-note' ).value };
		if ( chosen ) {
			body.format = chosen.value;
		}
		body.destinations = selectedDestinations();
		var button = document.getElementById( 'ovb-start' );
		button.disabled = true;
		announce( t.starting );

		api( 'POST', 'backups', body ).then(
			function ( result ) {
				if ( result.ok ) {
					attach( result.data.id );
					return;
				}
				button.disabled = false;
				if ( result.status === 409 && result.data.data && result.data.data.backup_id ) {
					attach( result.data.data.backup_id );
					return;
				}
				showError( result.data.message || t.requestError );
			},
			function () {
				button.disabled = false;
				showError( t.requestError );
			}
		);
	}

	function cancel() {
		if ( ! backupId || ! window.confirm( t.confirmStop ) ) {
			return;
		}
		cancelBtn.disabled = true;
		announce( t.cancelling );
		api( 'POST', 'backups/' + backupId + '/cancel' ).then( function ( result ) {
			cancelBtn.disabled = false;
			if ( result.ok ) {
				window.clearTimeout( pollTimer );
				render( result.data );
				finish( result.data );
			} else {
				showError( result.data.message || t.requestError );
			}
		}, function () {
			cancelBtn.disabled = false;
			showError( t.requestError );
		} );
	}

	if ( ! window.fetch || ! window.JSON ) {
		showError( t.noFetch );
		if ( form ) {
			form.setAttribute( 'hidden', 'hidden' );
		}
		return;
	}

	if ( form ) {
		form.addEventListener( 'submit', start );
	}
	if ( cancelBtn ) {
		cancelBtn.addEventListener( 'click', cancel );
	}
	if ( cfg.runningId > 0 ) {
		attach( cfg.runningId );
	}
}() );
