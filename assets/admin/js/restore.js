/**
 * OpenVault Backup: Restore page.
 * Choose a backup, preview, start, follow the progress, confirm, show the result and undo, all over the REST API.
 * Plain ES5, no dependencies. Only text is ever written into the page (textContent), never HTML.
 *
 * A database restore replaces the users table, so the administrator may be logged out in the middle of it. The restore keeps running
 * on the server; when the authenticated polling stops working after the apply started, this script switches to the unauthenticated
 * status route (GET restores/{id}/status?t=token). The token is kept in memory and in sessionStorage only.
 */
( function () {
	'use strict';

	var cfg = window.ovbRestore;
	var root = document.getElementById( 'ovb-restore' );
	if ( ! cfg || ! root ) {
		return;
	}

	var t = cfg.i18n || {};
	var PHRASE = 'RESTORE';
	var POLL_MS = 2000;
	var MAX_DELAY = 8000;

	var state = {
		restoreId: 0,
		kind: 'restore',
		token: '',
		useToken: false,
		applying: false,
		shownPercent: 0,
		startedAt: 0,
		delay: POLL_MS,
		pollTimer: null,
		tickTimer: null,
		busy: false,
		finished: false,
		preview: null,
		lastLive: ''
	};

	function $( id ) {
		return document.getElementById( id );
	}

	function show( el, on ) {
		if ( el ) {
			if ( on ) {
				el.removeAttribute( 'hidden' );
			} else {
				el.setAttribute( 'hidden', 'hidden' );
			}
		}
	}

	function say( id, text ) {
		var el = $( id );
		if ( el ) {
			el.textContent = text || '';
		}
	}

	function clear( el ) {
		while ( el && el.firstChild ) {
			el.removeChild( el.firstChild );
		}
	}

	function addItem( list, text ) {
		var li = document.createElement( 'li' );
		li.textContent = text;
		list.appendChild( li );
	}

	function fill( id, items ) {
		var list = $( id );
		var i;
		clear( list );
		for ( i = 0; i < items.length; i++ ) {
			addItem( list, items[ i ] );
		}
	}

	function fmt( template, args ) {
		var out = template || '';
		var i;
		for ( i = 0; i < args.length; i++ ) {
			out = out.replace( '%' + ( i + 1 ) + '$s', args[ i ] ).replace( '%s', args[ i ] );
		}
		return out;
	}

	function human( bytes ) {
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		var v = bytes || 0;
		while ( v >= 1024 && i < units.length - 1 ) {
			v = v / 1024;
			i++;
		}
		return ( i === 0 ? v : v.toFixed( v >= 100 ? 0 : 1 ) ) + ' ' + units[ i ];
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

	function when( iso ) {
		var d = iso ? new Date( iso ) : null;
		return d && ! isNaN( d.getTime() ) ? d.toLocaleString() : '';
	}

	function showError( message ) {
		var box = $( 'ovb-rs-error' );
		box.getElementsByTagName( 'p' )[ 0 ].textContent = message || '';
		show( box, !! message );
	}

	function announce( text ) {
		if ( text && text !== state.lastLive ) {
			state.lastLive = text;
			say( 'ovb-rs-live', text );
		}
	}

	function focusHeading( id ) {
		var el = $( id );
		if ( el && el.focus ) {
			el.focus();
		}
	}

	function view( name ) {
		show( $( 'ovb-rs-choose' ), name === 'choose' );
		show( $( 'ovb-rs-preview' ), name === 'preview' );
		show( $( 'ovb-rs-run' ), name === 'run' );
		show( $( 'ovb-recent' ), name === 'choose' );
	}

	function store( key, value ) {
		try {
			if ( value ) {
				window.sessionStorage.setItem( key, value );
			} else {
				window.sessionStorage.removeItem( key );
			}
		} catch ( e ) {
			// Storage can be blocked: the token then lives in memory only.
		}
	}

	function stored( key ) {
		try {
			return window.sessionStorage.getItem( key ) || '';
		} catch ( e ) {
			return '';
		}
	}

	// Authenticated request (cookie + REST nonce).
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

	// Unauthenticated status request: no cookie, no nonce, only the token.
	function tokenRequest() {
		return window.fetch( cfg.restUrl + 'restores/' + state.restoreId + '/status?t=' + encodeURIComponent( state.token ), {
			method: 'GET',
			credentials: 'omit',
			cache: 'no-store',
			headers: { 'Accept': 'application/json' }
		} ).then( function ( response ) {
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

	// ---- Step 1: choose -------------------------------------------------------------------------------------------------

	function selectedBackup() {
		return root.querySelector( 'input[name="ovb_backup"]:checked' );
	}

	function selectedMode() {
		return root.querySelector( 'input[name="ovb_mode"]:checked' );
	}

	function refreshModes() {
		var backup = selectedBackup();
		var modes = {};
		var radios = root.querySelectorAll( 'input[name="ovb_mode"]' );
		var i;
		var mode;
		if ( backup ) {
			try {
				modes = JSON.parse( backup.getAttribute( 'data-ovb-modes' ) || '{}' );
			} catch ( e ) {
				modes = {};
			}
		}
		for ( i = 0; i < radios.length; i++ ) {
			mode = modes[ radios[ i ].value ];
			radios[ i ].disabled = ! backup || ! mode || ! mode.enabled;
			if ( radios[ i ].disabled && radios[ i ].checked ) {
				radios[ i ].checked = false;
			}
			say( 'ovb-mr-' + radios[ i ].value, backup && mode && ! mode.enabled ? mode.reason : '' );
		}
		refreshPreviewButton();
	}

	function refreshPreviewButton() {
		var button = $( 'ovb-rs-preview-btn' );
		if ( button ) {
			button.disabled = ! selectedBackup() || ! selectedMode() || state.busy;
		}
	}

	function runPreview() {
		var backup = selectedBackup();
		var mode = selectedMode();
		if ( ! backup || ! mode ) {
			return;
		}
		showError( '' );
		state.busy = true;
		refreshPreviewButton();
		announce( t.previewing );
		api( 'POST', 'restores/preview', { backup_id: parseInt( backup.value, 10 ), mode: mode.value } ).then(
			function ( result ) {
				state.busy = false;
				refreshPreviewButton();
				if ( ! result.ok ) {
					showError( result.data.message || t.requestError );
					return;
				}
				state.preview = result.data;
				state.preview.backupId = parseInt( backup.value, 10 );
				renderPreview( result.data );
				view( 'preview' );
				focusHeading( 'ovb-rs-preview-title' );
			},
			function () {
				state.busy = false;
				refreshPreviewButton();
				showError( t.requestError );
			}
		);
	}

	// ---- Step 2: preview ------------------------------------------------------------------------------------------------

	function messages( items ) {
		var out = [];
		var i;
		for ( i = 0; i < items.length; i++ ) {
			out.push( items[ i ].message );
		}
		return out;
	}

	function renderPreview( data ) {
		var facts = [];
		var b = data.backup;
		var ackItem = null;
		var i;

		facts.push( fmt( t.factBackup, [ when( b.created_at ) + ' - ' + b.trigger_label + ' - ' + b.contents_summary + ( b.size > 0 ? ' - ' + human( b.size ) : '' ) ] ) );
		if ( data.source ) {
			facts.push( fmt( t.factSource, [ data.source.label ] ) );
		}
		facts.push( data.touches.db ? t.factDb : t.factNoDb );
		facts.push( data.touches.files ? t.factFiles : t.factNoFiles );
		if ( data.sizes.download > 0 ) {
			facts.push( fmt( t.factDownload, [ human( data.sizes.download ) ] ) );
		}
		if ( data.touches.db && data.sizes.snapshot > 0 ) {
			facts.push( fmt( t.factSnapshot, [ human( data.sizes.snapshot ) ] ) );
		}
		if ( data.touches.files && data.sizes.journal > 0 ) {
			facts.push( fmt( t.factJournal, [ human( data.sizes.journal ) ] ) );
		}
		if ( data.disk.checked ) {
			facts.push( fmt( t.factDisk, [ data.disk.free === null ? t.diskUnknown : human( data.disk.free ), human( data.disk.needed ) ] ) );
		}
		fill( 'ovb-pv-facts', facts );

		fillBox( 'ovb-pv-errors', messages( data.errors ) );
		fillBox( 'ovb-pv-warnings', messages( data.warnings ) );
		fill( 'ovb-pv-protected', data.protected );

		for ( i = 0; i < data.requires_ack.length; i++ ) {
			if ( data.requires_ack[ i ].code === 'url_mismatch' ) {
				ackItem = data.requires_ack[ i ];
			}
		}
		show( $( 'ovb-pv-ack-box' ), !! ackItem );
		say( 'ovb-pv-ack-text', ackItem ? ackItem.message : '' );
		$( 'ovb-pv-ack' ).checked = false;
		$( 'ovb-pv-keep' ).checked = false;
		$( 'ovb-pv-keep' ).disabled = true;
		show( $( 'ovb-pv-server-box' ), !! data.options.server_config_choice );
		$( 'ovb-pv-server' ).checked = false;
		$( 'ovb-pv-phrase' ).value = '';
		refreshStart();
	}

	function fillBox( id, items ) {
		var box = $( id );
		var list = box.getElementsByTagName( 'ul' )[ 0 ];
		var i;
		clear( list );
		for ( i = 0; i < items.length; i++ ) {
			addItem( list, items[ i ] );
		}
		show( box, items.length > 0 );
	}

	function refreshStart() {
		var data = state.preview;
		var ok = !! data && data.errors.length === 0 && $( 'ovb-pv-phrase' ).value === PHRASE && ! state.busy;
		if ( ok && ! $( 'ovb-pv-ack-box' ).hasAttribute( 'hidden' ) && ! $( 'ovb-pv-ack' ).checked ) {
			ok = false;
		}
		$( 'ovb-pv-start' ).disabled = ! ok;
		$( 'ovb-pv-keep' ).disabled = ! $( 'ovb-pv-ack' ).checked;
		if ( ! $( 'ovb-pv-ack' ).checked ) {
			$( 'ovb-pv-keep' ).checked = false;
		}
	}

	function startRestore() {
		var data = state.preview;
		if ( ! data || $( 'ovb-pv-phrase' ).value !== PHRASE ) {
			return;
		}
		showError( '' );
		state.busy = true;
		refreshStart();
		announce( t.starting );
		api( 'POST', 'restores', {
			backup_id: data.backupId,
			mode: data.mode,
			acknowledge_url_mismatch: $( 'ovb-pv-ack' ).checked,
			keep_site_urls: $( 'ovb-pv-keep' ).checked,
			restore_server_config: $( 'ovb-pv-server' ).checked,
			confirm_phrase: $( 'ovb-pv-phrase' ).value
		} ).then(
			function ( result ) {
				state.busy = false;
				if ( ! result.ok ) {
					refreshStart();
					showError( result.data.message || t.requestError );
					return;
				}
				attach( result.data );
			},
			function () {
				state.busy = false;
				refreshStart();
				showError( t.requestError );
			}
		);
	}

	// ---- Steps 3 and 4: follow a job ------------------------------------------------------------------------------------

	function attach( data ) {
		window.clearTimeout( state.pollTimer );
		window.clearInterval( state.tickTimer );
		state.restoreId = data.id;
		state.kind = data.kind;
		state.token = data.status_token || stored( 'ovbRestoreToken' + data.id );
		if ( data.status_token ) {
			store( 'ovbRestoreToken' + data.id, data.status_token );
		}
		state.useToken = false;
		state.applying = data.kind === 'undo';
		state.shownPercent = 0;
		state.finished = false;
		state.delay = POLL_MS;
		state.startedAt = Date.now() - ( data.elapsed_seconds || 0 ) * 1000;
		state.lastLive = '';
		say( 'ovb-run-title', t.headProgress );
		show( $( 'ovb-run-progress' ), true );
		show( $( 'ovb-run-ready' ), false );
		show( $( 'ovb-run-result' ), false );
		show( $( 'ovb-run-undo' ), false );
		show( $( 'ovb-run-again' ), false );
		show( $( 'ovb-run-note' ), false );
		view( 'run' );
		state.tickTimer = window.setInterval( tick, 1000 );
		render( data );
		focusHeading( 'ovb-run-title' );
		if ( data.phase !== 'ready' ) {
			schedule();
		}
	}

	function tick() {
		say( 'ovb-run-elapsed', clock( ( Date.now() - state.startedAt ) / 1000 ) );
	}

	function setPercent( percent ) {
		state.shownPercent = Math.max( state.shownPercent, percent || 0 );
		$( 'ovb-run-bar' ).value = state.shownPercent;
		say( 'ovb-run-percent', state.shownPercent + '%' );
	}

	function note( text ) {
		say( 'ovb-run-note', text );
		show( $( 'ovb-run-note' ), !! text );
	}

	function render( data ) {
		var phase = data.phase;
		setPercent( data.finished && data.status === 'complete' ? 100 : data.percent );
		say( 'ovb-run-step', data.step_label || data.message || '' );
		announce( data.step_label || '' );
		if ( phase === 'applying' ) {
			state.applying = true;
			note( data.maintenance ? t.maintenanceOn : '' );
		} else if ( phase === 'preparing' ) {
			note( '' );
		}
		cancelControls( data );

		if ( phase === 'ready' ) {
			showReady( data );
		} else if ( data.finished ) {
			finish( data );
		}
	}

	function cancelControls( data ) {
		var button = $( 'ovb-run-cancel' );
		show( button, ! data.finished && data.can_cancel );
		say( 'ovb-run-cancel-note', ! data.finished && ! data.can_cancel ? data.cancel_refusal || '' : '' );
	}

	function schedule() {
		window.clearTimeout( state.pollTimer );
		if ( state.finished ) {
			return;
		}
		state.pollTimer = window.setTimeout( poll, Math.max( 1000, state.delay ) );
	}

	function backoff() {
		state.delay = Math.min( MAX_DELAY, Math.round( state.delay * 1.5 ) );
	}

	function poll() {
		if ( state.finished ) {
			return;
		}
		if ( state.useToken ) {
			tokenRequest().then( onTokenResult, onTokenFailure );
		} else {
			api( 'GET', 'restores/' + state.restoreId ).then( onAuthResult, onAuthFailure );
		}
	}

	function switchToToken() {
		if ( state.token && ! state.useToken ) {
			state.useToken = true;
			note( t.loggedOutNote );
			return true;
		}
		return state.useToken;
	}

	function onAuthResult( result ) {
		if ( result.ok ) {
			state.delay = POLL_MS;
			showError( '' );
			render( result.data );
			if ( result.data.phase !== 'ready' ) {
				schedule();
			}
			return;
		}
		if ( result.status === 404 ) {
			showError( result.data.message || t.requestError );
			return;
		}
		onAuthFailure();
	}

	// 401, 403, 503 and network errors: after the apply started this is expected (the users table is replaced), so no alarm.
	function onAuthFailure() {
		backoff();
		if ( state.applying && switchToToken() ) {
			state.delay = POLL_MS;
		} else {
			note( state.applying ? t.waiting : '' );
			if ( ! state.applying ) {
				showError( t.requestError );
			}
		}
		schedule();
	}

	function onTokenResult( result ) {
		var data;
		if ( result.status === 404 ) {
			endWithoutDetails( false, false );
			return;
		}
		if ( ! result.ok ) {
			onTokenFailure();
			return;
		}
		state.delay = POLL_MS;
		data = result.data;
		setPercent( data.done && ! data.failed ? 100 : data.percent );
		say( 'ovb-run-step', data.step_label || '' );
		announce( data.step_label || '' );
		show( $( 'ovb-run-cancel' ), false );
		if ( data.done ) {
			endWithoutDetails( true, data.failed, data.message );
			return;
		}
		schedule();
	}

	function onTokenFailure() {
		backoff();
		note( t.waiting );
		schedule();
	}

	function loginLink() {
		var a = document.createElement( 'a' );
		a.className = 'button button-primary';
		a.href = cfg.loginUrl + ( cfg.loginUrl.indexOf( 'redirect_to=' ) === -1 ? '' : '%26restore%3D' + state.restoreId );
		a.textContent = t.logIn;
		return a;
	}

	// The job ended but only coarse facts are known (the session is gone): say so and offer the login that returns to this page.
	function endWithoutDetails( known, failed, message ) {
		var title = $( 'ovb-result-title' );
		var body = $( 'ovb-result-body' );
		var links = $( 'ovb-result-links' );
		state.finished = true;
		window.clearTimeout( state.pollTimer );
		window.clearInterval( state.tickTimer );
		show( $( 'ovb-run-progress' ), false );
		clear( body );
		clear( links );
		title.textContent = known && failed ? t.failedTitle : t.doneTitle;
		var p = document.createElement( 'p' );
		p.textContent = known ? ( failed ? ( message || '' ) : t.doneLogin ) : t.doneLogin;
		body.appendChild( p );
		links.appendChild( loginLink() );
		show( $( 'ovb-run-result' ), true );
		announce( p.textContent );
		focusHeading( 'ovb-result-title' );
	}

	function showReady( data ) {
		var items = [];
		var scope = data.mode;
		if ( scope === 'db' || scope === 'full' ) {
			items.push( t.ready1 );
		}
		if ( scope === 'files' || scope === 'full' ) {
			items.push( t.ready2 );
		}
		if ( items.length === 0 ) {
			items.push( t.readyNone );
		}
		fill( 'ovb-ready-list', items );
		show( $( 'ovb-run-ready' ), true );
		window.clearTimeout( state.pollTimer );
		$( 'ovb-ready-phrase' ).value = '';
		$( 'ovb-ready-apply' ).disabled = true;
		announce( data.message );
		focusHeading( 'ovb-ready-title' );
	}

	function applyNow() {
		if ( $( 'ovb-ready-phrase' ).value !== PHRASE ) {
			return;
		}
		showError( '' );
		$( 'ovb-ready-apply' ).disabled = true;
		announce( t.applying );
		api( 'POST', 'restores/' + state.restoreId + '/confirm', { confirm_phrase: PHRASE } ).then(
			function ( result ) {
				if ( ! result.ok ) {
					showError( result.data.message || t.requestError );
					$( 'ovb-ready-apply' ).disabled = $( 'ovb-ready-phrase' ).value !== PHRASE;
					return;
				}
				show( $( 'ovb-run-ready' ), false );
				state.applying = true;
				state.delay = POLL_MS;
				render( result.data );
				schedule();
			},
			function () {
				showError( t.requestError );
				$( 'ovb-ready-apply' ).disabled = false;
			}
		);
	}

	function cancelRestore() {
		if ( ! window.confirm( t.confirmCancel ) ) {
			return;
		}
		say( 'ovb-run-cancel-note', t.cancelling );
		api( 'POST', 'restores/' + state.restoreId + '/cancel' ).then(
			function ( result ) {
				if ( result.ok ) {
					window.clearTimeout( state.pollTimer );
					render( result.data );
				} else {
					say( 'ovb-run-cancel-note', result.data.message || t.requestError );
				}
			},
			function () {
				say( 'ovb-run-cancel-note', t.requestError );
			}
		);
	}

	// ---- Step 4: result and undo ------------------------------------------------------------------------------------------

	function resultLines( data ) {
		var r = data.result;
		var lines = [];
		var reason;
		if ( ! r ) {
			return lines;
		}
		if ( r.tables > 0 ) {
			lines.push( fmt( t.resDb, [ r.tables, r.rows, r.statements ] ) );
		}
		if ( r.files && ( r.files.restored > 0 || r.files.skipped_n > 0 ) ) {
			lines.push( fmt( t.resFiles, [ r.files.restored, r.files.created, r.files.overwritten, human( r.files.bytes ) ] ) );
			for ( reason in r.files.skipped ) {
				if ( Object.prototype.hasOwnProperty.call( r.files.skipped, reason ) ) {
					lines.push( fmt( t.resSkipped, [ r.files.skipped[ reason ], reason ] ) );
				}
			}
		}
		if ( r.undo_files && ( r.undo_files.restored > 0 || r.undo_files.removed > 0 ) ) {
			lines.push( fmt( t.resUndoFiles, [ r.undo_files.restored, r.undo_files.removed ] ) );
		}
		if ( r.kept_urls ) {
			lines.push( t.resKept );
		}
		if ( r.snapshot_id > 0 && data.kind !== 'undo' && r.tables > 0 ) {
			lines.push( t.resSnapshot );
		}
		lines.push( fmt( t.resTime, [ clock( r.seconds ) ] ) );
		return lines;
	}

	function finish( data ) {
		var title = $( 'ovb-result-title' );
		var body = $( 'ovb-result-body' );
		var links = $( 'ovb-result-links' );
		var i;
		var p;
		var lines;
		var a;

		state.finished = true;
		say( 'ovb-run-title', t.headResult );
		window.clearTimeout( state.pollTimer );
		window.clearInterval( state.tickTimer );
		show( $( 'ovb-run-progress' ), false );
		show( $( 'ovb-run-ready' ), false );
		clear( body );
		clear( links );

		if ( data.status === 'complete' ) {
			title.textContent = data.kind === 'undo' ? t.undoDoneTitle : t.doneTitle;
			lines = resultLines( data );
			var ul = document.createElement( 'ul' );
			ul.className = 'ovb-needs';
			for ( i = 0; i < lines.length; i++ ) {
				addItem( ul, lines[ i ] );
			}
			for ( i = 0; i < data.warnings.length; i++ ) {
				addItem( ul, data.warnings[ i ] );
			}
			body.appendChild( ul );
		} else {
			title.textContent = data.status === 'cancelled' ? t.cancelledTitle : t.failedTitle;
			p = document.createElement( 'p' );
			p.className = data.status === 'failed' ? 'ovb-dest-error' : '';
			p.textContent = data.error || data.message || '';
			body.appendChild( p );
			p = document.createElement( 'p' );
			p.textContent = data.status === 'cancelled' ? t.cancelledHelp : t.failedHelp;
			body.appendChild( p );
		}

		a = document.createElement( 'a' );
		a.className = 'button';
		a.href = cfg.historyUrl;
		a.textContent = t.historyLink;
		links.appendChild( a );
		show( $( 'ovb-run-result' ), true );
		show( $( 'ovb-run-again' ), true );
		undoSection( data );
		announce( title.textContent );
		focusHeading( 'ovb-result-title' );
	}

	function undoSection( data ) {
		var u = data.undo;
		show( $( 'ovb-run-undo' ), !! u );
		if ( ! u ) {
			return;
		}
		say( 'ovb-undo-text', u.can_undo ? t.undoOffer : ( u.reason || t.undoNone ) );
		show( $( 'ovb-undo-action' ), u.can_undo );
		say( 'ovb-undo-warning', u.undo_db ? u.warning : '' );
		show( $( 'ovb-undo-warning' ), u.can_undo && u.undo_db );
		$( 'ovb-undo-phrase' ).value = '';
		$( 'ovb-undo-btn' ).disabled = true;
		show( $( 'ovb-dismiss-box' ), u.journal && ! u.dismissed );
	}

	function startUndo() {
		if ( $( 'ovb-undo-phrase' ).value !== PHRASE ) {
			return;
		}
		showError( '' );
		$( 'ovb-undo-btn' ).disabled = true;
		announce( t.undoStarting );
		api( 'POST', 'restores/' + state.restoreId + '/undo', { confirm_phrase: PHRASE } ).then(
			function ( result ) {
				if ( ! result.ok ) {
					showError( result.data.message || t.requestError );
					return;
				}
				attach( result.data );
			},
			function () {
				showError( t.requestError );
				$( 'ovb-undo-btn' ).disabled = false;
			}
		);
	}

	function dismissUndo() {
		if ( ! window.confirm( t.confirmDismiss ) ) {
			return;
		}
		showError( '' );
		api( 'POST', 'restores/' + state.restoreId + '/undo/dismiss' ).then(
			function ( result ) {
				if ( result.ok ) {
					finish( result.data );
					announce( t.dismissed );
				} else {
					showError( result.data.message || t.requestError );
				}
			},
			function () {
				showError( t.requestError );
			}
		);
	}

	// ---- Wiring ---------------------------------------------------------------------------------------------------------------

	function typed( inputId, buttonId ) {
		var input = $( inputId );
		input.addEventListener( 'input', function () {
			$( buttonId ).disabled = input.value !== PHRASE;
		} );
	}

	function init() {
		var list = $( 'ovb-rs-list' );
		var pre = parseInt( root.getAttribute( 'data-ovb-backup-id' ), 10 ) || 0;
		var id = parseInt( root.getAttribute( 'data-ovb-restore-id' ), 10 ) || 0;
		var radio;

		list.addEventListener( 'change', refreshModes );
		$( 'ovb-rs-modes' ).addEventListener( 'change', refreshPreviewButton );
		$( 'ovb-rs-preview-btn' ).addEventListener( 'click', runPreview );
		$( 'ovb-pv-back' ).addEventListener( 'click', function () {
			view( 'choose' );
			focusHeading( 'ovb-rs-choose-title' );
		} );
		$( 'ovb-pv-phrase' ).addEventListener( 'input', refreshStart );
		$( 'ovb-pv-ack' ).addEventListener( 'change', refreshStart );
		$( 'ovb-pv-start' ).addEventListener( 'click', startRestore );
		typed( 'ovb-ready-phrase', 'ovb-ready-apply' );
		$( 'ovb-ready-apply' ).addEventListener( 'click', applyNow );
		$( 'ovb-run-cancel' ).addEventListener( 'click', cancelRestore );
		typed( 'ovb-undo-phrase', 'ovb-undo-btn' );
		$( 'ovb-undo-btn' ).addEventListener( 'click', startUndo );
		$( 'ovb-undo-dismiss' ).addEventListener( 'click', dismissUndo );

		refreshModes();
		if ( pre > 0 ) {
			radio = $( 'ovb-b-' + pre );
			if ( radio && ! radio.disabled ) {
				radio.checked = true;
				refreshModes();
			}
		}

		if ( id > 0 ) {
			state.restoreId = id;
			state.token = stored( 'ovbRestoreToken' + id );
			view( 'run' );
			api( 'GET', 'restores/' + id ).then(
				function ( result ) {
					if ( result.ok ) {
						attach( result.data );
					} else {
						onFirstLoadFailure( result );
					}
				},
				function () {
					onFirstLoadFailure( { status: 0 } );
				}
			);
		}
	}

	// The page was opened for a restore but the session does not work (logged out by the restore): use the token if this tab has one.
	function onFirstLoadFailure( result ) {
		if ( result.status === 404 ) {
			view( 'choose' );
			showError( ( result.data && result.data.message ) || t.requestError );
			return;
		}
		state.applying = true;
		state.useToken = !! state.token;
		state.startedAt = Date.now();
		state.delay = POLL_MS;
		show( $( 'ovb-run-progress' ), true );
		state.tickTimer = window.setInterval( tick, 1000 );
		note( state.useToken ? t.loggedOutNote : t.waiting );
		schedule();
	}

	if ( ! window.fetch || ! window.JSON ) {
		showError( t.noFetch );
		return;
	}
	init();
}() );
