/**
 * OpenVault Backup: Destinations page (add, edit, test, remove).
 * Plain ES5, no dependencies. Every action goes through the REST API (/destinations) with the REST nonce.
 * Secrets are write-only: the key fields are never pre-filled and nothing here stores them anywhere.
 */
( function () {
	'use strict';

	var cfg = window.ovbDestinations;
	var root = document.getElementById( 'ovb-destinations' );
	if ( ! cfg || ! root ) {
		return;
	}

	var t = cfg.i18n || {};
	var form = document.getElementById( 'ovb-dest-form' );
	var list = document.getElementById( 'ovb-dest-list' );
	var notice = document.getElementById( 'ovb-dest-notice' );
	var formError = document.getElementById( 'ovb-dest-form-error' );
	var editId = document.getElementById( 'ovb-dest-edit-id' );
	var title = document.getElementById( 'ovb-dest-form-title' );
	var labelInput = document.getElementById( 'ovb-dest-label' );
	var presetSelect = document.getElementById( 'ovb-s3-preset' );
	var keysSaved = document.getElementById( 'ovb-keys-saved' );
	var keysFields = document.getElementById( 'ovb-keys-fields' );
	var saveBtn = document.getElementById( 'ovb-dest-save' );
	var cancelBtn = document.getElementById( 'ovb-dest-cancel' );
	var FLASH = 'ovbDestinationsFlash';

	function show( el, on ) {
		if ( el ) {
			if ( on ) {
				el.removeAttribute( 'hidden' );
			} else {
				el.setAttribute( 'hidden', 'hidden' );
			}
		}
	}

	function say( message, isError ) {
		if ( ! notice ) {
			return;
		}
		notice.getElementsByTagName( 'p' )[ 0 ].textContent = message;
		notice.className = 'notice inline ' + ( isError ? 'notice-error' : 'notice-success' );
		show( notice, !! message );
	}

	function formSay( message ) {
		formError.getElementsByTagName( 'p' )[ 0 ].textContent = message;
		show( formError, !! message );
	}

	function flash( message ) {
		try {
			window.sessionStorage.setItem( FLASH, message );
		} catch ( e ) {
			return;
		}
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
					return { ok: response.ok, status: response.status, data: data };
				},
				function () {
					return { ok: false, status: response.status, data: {} };
				}
			);
		} );
	}

	function tokens( el ) {
		return ( el.getAttribute( 'data-ovb-presets' ) || '' ).split( ' ' );
	}

	function currentType() {
		return form.querySelector( 'input[name="ovb-dest-type"]:checked' ).value;
	}

	// Show only the fields that belong to the chosen type and provider.
	function applyScope() {
		var type = currentType();
		var preset = presetSelect.value;
		var groups = form.querySelectorAll( '[data-ovb-type]' );
		var scoped = form.querySelectorAll( '[data-ovb-presets]' );
		var i;
		for ( i = 0; i < groups.length; i++ ) {
			show( groups[ i ], groups[ i ].getAttribute( 'data-ovb-type' ) === type );
		}
		for ( i = 0; i < scoped.length; i++ ) {
			show( scoped[ i ], tokens( scoped[ i ] ).indexOf( preset ) !== -1 );
		}
	}

	function isHidden( el ) {
		var node = el;
		while ( node && node !== form ) {
			if ( node.hasAttribute && node.hasAttribute( 'hidden' ) ) {
				return true;
			}
			node = node.parentNode;
		}
		return false;
	}

	function collectSettings() {
		var fields = form.querySelectorAll( '[data-ovb-field]' );
		var out = {};
		var i;
		for ( i = 0; i < fields.length; i++ ) {
			if ( isHidden( fields[ i ] ) ) {
				continue;
			}
			out[ fields[ i ].getAttribute( 'data-ovb-field' ) ] = fields[ i ].type === 'checkbox' ? fields[ i ].checked : fields[ i ].value.replace( /^\s+|\s+$/g, '' );
		}
		return out;
	}

	function collectCredentials() {
		if ( isHidden( keysFields ) || currentType() !== 's3' ) {
			return null;
		}
		return {
			access_key: document.getElementById( 'ovb-s3-access' ).value,
			secret_key: document.getElementById( 'ovb-s3-secret' ).value
		};
	}

	function clearSecrets() {
		document.getElementById( 'ovb-s3-access' ).value = '';
		document.getElementById( 'ovb-s3-secret' ).value = '';
	}

	function resetForm() {
		var fields = form.querySelectorAll( '[data-ovb-field]' );
		var i;
		for ( i = 0; i < fields.length; i++ ) {
			if ( fields[ i ].type === 'checkbox' ) {
				fields[ i ].checked = false;
			} else if ( fields[ i ].tagName !== 'SELECT' ) {
				fields[ i ].value = '';
			}
		}
		presetSelect.selectedIndex = 0;
		document.getElementById( 'ovb-s3-addressing' ).selectedIndex = 0;
		document.getElementById( 'ovb-s3-class' ).selectedIndex = 0;
		labelInput.value = '';
		clearSecrets();
		editId.value = '';
		document.getElementById( 'ovb-dest-type-s3' ).checked = true;
		document.getElementById( 'ovb-dest-type-s3' ).disabled = false;
		document.getElementById( 'ovb-dest-type-local' ).disabled = false;
		show( keysSaved, false );
		show( keysFields, true );
		show( cancelBtn, false );
		title.textContent = title.getAttribute( 'data-add' );
		formSay( '' );
		applyScope();
	}

	function startEdit( item ) {
		resetForm();
		var settings = item.settings || {};
		var fields = form.querySelectorAll( '[data-ovb-field]' );
		var i;
		var name;
		document.getElementById( 'ovb-dest-type-' + item.type ).checked = true;
		document.getElementById( 'ovb-dest-type-s3' ).disabled = true;
		document.getElementById( 'ovb-dest-type-local' ).disabled = true;
		if ( item.type === 's3' && settings.preset ) {
			presetSelect.value = settings.preset;
		}
		for ( i = 0; i < fields.length; i++ ) {
			name = fields[ i ].getAttribute( 'data-ovb-field' );
			if ( name === 'preset' || typeof settings[ name ] === 'undefined' ) {
				continue;
			}
			if ( fields[ i ].type === 'checkbox' ) {
				fields[ i ].checked = settings[ name ] === true;
			} else {
				fields[ i ].value = settings[ name ];
			}
		}
		labelInput.value = item.label;
		editId.value = String( item.id );
		title.textContent = title.getAttribute( 'data-edit' );
		show( cancelBtn, true );
		// Saved keys are never shown; they can only be kept or replaced.
		var saved = item.type === 's3' && item.has_credentials && item.status !== 'needs_reconnect';
		show( keysSaved, saved );
		show( keysFields, ! saved );
		applyScope();
		document.getElementById( 'ovb-dest-form-panel' ).scrollIntoView();
		labelInput.focus();
	}

	function cardOf( node ) {
		while ( node && node !== list ) {
			if ( node.getAttribute && node.getAttribute( 'data-ovb-dest' ) ) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	function part( card, role ) {
		return card.querySelector( '[data-ovb-role="' + role + '"]' );
	}

	function showResult( card, message, ok ) {
		var el = part( card, 'result' );
		el.textContent = message;
		el.className = 'ovb-dest-result ' + ( ok ? 'is-ok' : 'is-bad' );
	}

	// Reflect the destination returned by a test in its card.
	function refresh( card, item ) {
		var badge = part( card, 'status-badge' );
		var errorEl = part( card, 'error' );
		badge.textContent = item.status_label;
		badge.className = 'ovb-status ovb-status--' + item.status;
		part( card, 'tested' ).textContent = item.last_tested_at ? ( t.tested || '%s' ).replace( '%s', new Date( item.last_tested_at ).toLocaleString() ) : ( t.never || '' );
		errorEl.textContent = item.last_error || '';
		show( errorEl, !! item.last_error && item.status !== 'needs_reconnect' );
		card.setAttribute( 'data-ovb-dest', JSON.stringify( item ) );
	}

	function testCard( card, button ) {
		var id = card.getAttribute( 'data-ovb-id' );
		button.disabled = true;
		showResult( card, t.testing, true );
		request( 'POST', 'destinations/' + id + '/test' ).then(
			function ( result ) {
				button.disabled = false;
				if ( ! result.ok ) {
					showResult( card, result.data.message || t.requestError, false );
					return;
				}
				showResult( card, ( result.data.ok ? t.testOk : t.testFailed ) + ' ' + result.data.message, result.data.ok );
				if ( result.data.destination && result.data.destination.id ) {
					refresh( card, result.data.destination );
					// The result line already says why it failed.
					show( part( card, 'error' ), false );
				}
			},
			function () {
				button.disabled = false;
				showResult( card, t.requestError, false );
			}
		);
	}

	function removeCard( card, button ) {
		var item = JSON.parse( card.getAttribute( 'data-ovb-dest' ) );
		if ( ! window.confirm( ( t.confirmRemove || '%s' ).replace( '%s', item.label ) ) ) {
			return;
		}
		button.disabled = true;
		request( 'DELETE', 'destinations/' + item.id ).then(
			function ( result ) {
				if ( result.ok ) {
					flash( t.removed );
					window.location.reload();
					return;
				}
				button.disabled = false;
				say( result.data.message || t.requestError, true );
			},
			function () {
				button.disabled = false;
				say( t.requestError, true );
			}
		);
	}

	function save( event ) {
		event.preventDefault();
		formSay( '' );
		say( '', false );
		var type = currentType();
		var label = labelInput.value.replace( /^\s+|\s+$/g, '' );
		if ( ! label ) {
			formSay( t.needName );
			labelInput.focus();
			return;
		}
		var body = { label: label, settings: collectSettings() };
		var credentials = collectCredentials();
		var id = editId.value;
		if ( credentials ) {
			body.credentials = credentials;
		}
		if ( ! id ) {
			body.type = type;
		}
		saveBtn.disabled = true;
		request( 'POST', id ? 'destinations/' + id : 'destinations', body ).then(
			function ( result ) {
				saveBtn.disabled = false;
				if ( result.ok ) {
					clearSecrets();
					flash( id ? t.updated : t.created );
					window.location.reload();
					return;
				}
				formSay( result.data.message || t.requestError );
			},
			function () {
				saveBtn.disabled = false;
				formSay( t.requestError );
			}
		);
	}

	if ( ! window.fetch || ! window.JSON || ! form ) {
		say( t.noFetch, true );
		return;
	}

	form.addEventListener( 'submit', save );
	form.addEventListener( 'change', function ( event ) {
		if ( event.target === presetSelect || event.target.name === 'ovb-dest-type' ) {
			applyScope();
		}
	} );
	document.getElementById( 'ovb-keys-replace' ).addEventListener( 'click', function () {
		show( keysSaved, false );
		show( keysFields, true );
		document.getElementById( 'ovb-s3-access' ).focus();
	} );
	cancelBtn.addEventListener( 'click', resetForm );

	list.addEventListener( 'click', function ( event ) {
		var node = event.target;
		var action = '';
		while ( node && node !== list && ! action ) {
			action = node.getAttribute ? node.getAttribute( 'data-ovb-action' ) || '' : '';
			if ( ! action ) {
				node = node.parentNode;
			}
		}
		var card = action ? cardOf( node ) : null;
		if ( ! card ) {
			return;
		}
		event.preventDefault();
		if ( action === 'test' ) {
			testCard( card, node );
		} else if ( action === 'edit' ) {
			startEdit( JSON.parse( card.getAttribute( 'data-ovb-dest' ) ) );
		} else if ( action === 'remove' ) {
			removeCard( card, node );
		}
	} );

	resetForm();
	try {
		var message = window.sessionStorage.getItem( FLASH );
		if ( message ) {
			window.sessionStorage.removeItem( FLASH );
			say( message, false );
		}
	} catch ( e ) {
		message = '';
	}
}() );
