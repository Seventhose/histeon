/**
 * Gutenberg → Histeon: a button in the editor header opens the builder full screen over the editor, in an iframe
 * of this same site. The builder asks to close with a postMessage; only that iframe, from this origin, is
 * listened to. No build step, WordPress globals only (plugin/src/Admin/EditorLaunch.php).
 */
( function ( wp, config ) {
	'use strict';

	if ( ! config || typeof config.url !== 'string' ) {
		return;
	}
	const { __ } = wp.i18n;
	const editor = () => wp.data.select( 'core/editor' );
	const notices = () => wp.data.dispatch( 'core/notices' );
	const PENDING = 'xb-pending';
	let frame = null;
	let modifiedBefore = null;

	function showPending() {
		notices().createNotice(
			'info',
			__( 'Questa pagina ha modifiche fatte in Histeon e salvate in sviluppo: compariranno qui e sul sito dopo la pubblicazione.', 'xb' ),
			{ id: PENDING, isDismissible: true, actions: [ { label: __( 'Apri Histeon', 'xb' ), onClick: open } ] }
		);
	}

	async function modified() {
		const post = await wp.apiFetch( { path: config.route + '?context=edit&_fields=modified_gmt' } );
		return post && typeof post.modified_gmt === 'string' ? post.modified_gmt : null;
	}

	async function open() {
		if ( frame ) {
			return;
		}
		// Histeon works on what is stored: unsaved Gutenberg edits are saved first, or the two would diverge.
		if ( editor().isEditedPostDirty() || editor().isEditedPostNew() ) {
			if ( ! window.confirm( __( 'Prima di aprire Histeon salvo le modifiche fatte in Gutenberg. Continuare?', 'xb' ) ) ) {
				return;
			}
			await wp.data.dispatch( 'core/editor' ).savePost();
			if ( editor().isEditedPostDirty() || editor().didPostSaveRequestFail() ) {
				return;
			}
		}
		try {
			modifiedBefore = await modified();
		} catch ( e ) {
			modifiedBefore = null;
		}
		notices().removeNotice( PENDING );
		frame = document.createElement( 'iframe' );
		frame.className = 'xb-launch-frame';
		frame.title = __( 'Builder Histeon', 'xb' );
		frame.src = config.url;
		document.documentElement.classList.add( 'xb-launch-open' );
		document.body.appendChild( frame );
		frame.focus();
	}

	async function close() {
		if ( ! frame ) {
			return;
		}
		frame.remove();
		frame = null;
		document.documentElement.classList.remove( 'xb-launch-open' );
		try {
			// A release published from Histeon rewrote the stored page: show it (Gutenberg had nothing unsaved).
			const after = await modified();
			if ( after && modifiedBefore && after !== modifiedBefore && ! editor().isEditedPostDirty() ) {
				window.location.reload();
				return;
			}
			const changes = await wp.apiFetch( { path: '/xb/v1/changes' } );
			if ( Array.isArray( changes ) && changes.some( ( c ) => c && c.doc === config.doc ) ) {
				showPending();
			}
		} catch ( e ) {
			// The editor stays as it was; nothing to report beyond what the builder already showed.
		}
	}

	window.addEventListener( 'message', ( event ) => {
		if ( ! frame || event.origin !== window.location.origin || event.source !== frame.contentWindow ) {
			return;
		}
		if ( event.data && event.data.type === 'xb:close' ) {
			close();
		}
	} );

	function button() {
		const b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'components-button xb-launch-button';
		b.textContent = __( 'Modifica con Histeon', 'xb' );
		b.addEventListener( 'click', open );
		return b;
	}

	// The header is re-rendered by React: put the button back whenever it disappears.
	function place() {
		if ( document.querySelector( '.xb-launch-button' ) ) {
			return;
		}
		const target = document.querySelector( '.editor-header__settings, .edit-post-header__settings' );
		if ( target ) {
			target.insertBefore( button(), target.firstChild );
		}
	}

	wp.domReady( () => {
		place();
		new MutationObserver( place ).observe( document.body, { childList: true, subtree: true } );
		if ( config.pending ) {
			showPending();
		}
	} );
} )( window.wp, window.xbLaunch );
