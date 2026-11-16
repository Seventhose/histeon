/**
 * Yoast SEO and Rank Math analyse XB pages as visitors see them (plugin/src/Seo/Seo.php).
 * XB keeps texts in block attributes, so the analysers would find no words, headings, links or images: while
 * the page is edited, its rendered HTML is fetched from /xb/v1/editor-render (with the editor's own permissions)
 * and handed to them instead of the raw block markup. No build step, WordPress globals only.
 */
( function ( wp ) {
	'use strict';

	let rendered = null;
	let last = null;
	let timer = null;
	let busy = false;

	const editor = () => wp.data.select( 'core/editor' );
	const hasXb = ( content ) => typeof content === 'string' && content.indexOf( '<!-- wp:xb/' ) !== -1;
	/** What the analysers read: the rendered page when there is one, else what they had. */
	const contentFor = ( original ) => ( rendered !== null ? rendered : original );

	function refresh() {
		if ( window.YoastSEO && window.YoastSEO.app && window.YoastSEO.app.pluginReloaded ) {
			window.YoastSEO.app.pluginReloaded( 'XB' );
		}
		if ( window.rankMathEditor && window.rankMathEditor.refresh ) {
			window.rankMathEditor.refresh( 'content' );
		}
	}

	async function update() {
		timer = null;
		const content = editor() && editor().getEditedPostContent ? editor().getEditedPostContent() : null;
		if ( content === null || content === last || busy ) {
			return;
		}
		last = content;
		if ( ! hasXb( content ) ) {
			if ( rendered !== null ) {
				rendered = null;
				refresh();
			}
			return;
		}
		busy = true;
		try {
			const res = await wp.apiFetch( { path: '/xb/v1/editor-render', method: 'POST', data: { post_id: editor().getCurrentPostId(), items: { page: content } } } );
			rendered = res && res.items && typeof res.items.page === 'string' ? res.items.page : null;
		} catch ( e ) {
			rendered = null; // The analysers keep what they had.
		} finally {
			busy = false;
		}
		refresh();
		if ( editor().getEditedPostContent() !== last ) {
			schedule();
		}
	}

	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( update, 1200 );
	}

	// Rank Math.
	wp.hooks.addFilter( 'rank_math_content', 'xb/seo', contentFor );

	// Yoast SEO: a content modification, registered once its app is ready.
	function registerYoast() {
		if ( ! window.YoastSEO || ! window.YoastSEO.app || registerYoast.done ) {
			return;
		}
		registerYoast.done = true;
		window.YoastSEO.app.registerPlugin( 'XB', { status: 'ready' } );
		window.YoastSEO.app.registerModification( 'content', contentFor, 'XB', 5 );
	}
	registerYoast();
	if ( window.jQuery ) {
		window.jQuery( window ).on( 'YoastSEO:ready', registerYoast );
	}

	wp.data.subscribe( () => {
		const content = editor() && editor().getEditedPostContent ? editor().getEditedPostContent() : null;
		if ( content !== null && content !== last && ! timer ) {
			schedule();
		}
	} );
}( window.wp ) );
