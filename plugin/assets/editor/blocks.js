/**
 * Gutenberg side of the Histeon blocks — no build step, WordPress globals only.
 *
 * Gutenberg is the storage format; here the page must look as it does on the site, and its texts must be
 * editable in place with a click:
 * - elements are their real tags with their real attributes (event handlers and script URLs excepted);
 * - texts are RichText, edited directly; a text with expressions shows its value until it is selected;
 * - what only the server can work out (loops, components, raw HTML, expressions in attributes, which branch
 *   of a condition shows) comes from /xb/v1/editor-render, rendered as the live site renders it, and is
 *   cleaned before it is shown (no scripts, handlers or embedded pages run in the editor).
 * The site's CSS reaches the canvas from Plugin::on_enqueue_block_assets().
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType, serialize, createBlock } = wp.blocks;
	const { useBlockProps, useInnerBlocksProps, RichText, InnerBlocks } = wp.blockEditor;
	const { createElement: el, createContext, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } = wp.element;
	const { useSelect } = wp.data;
	const { __, sprintf } = wp.i18n;

	const TAG = /^[a-z][a-zA-Z0-9]*(?:-[a-zA-Z0-9]+)*$/;
	const VOID = new Set( [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' ] );
	// Shown as an empty box with the element's classes and size: they would run code or load other pages here.
	const INERT = new Set( [ 'script', 'style', 'iframe', 'object', 'embed', 'html', 'head', 'body', 'base', 'meta', 'link', 'frame', 'frameset', 'template', 'noscript', 'portal' ] );
	const URL_ATTRIBUTES = new Set( [ 'href', 'src', 'action', 'formaction', 'xlink:href', 'poster', 'background', 'cite', 'data', 'srcset', 'imagesrcset', 'ping' ] );
	const UNSAFE_URL = /^[\s\u0000-\u001f]*(?:javascript|vbscript|data(?!:image\/))[\s\u0000-\u001f]*:/i;
	// Set by the block editor itself on every block wrapper: never overwritten by the content's attributes.
	const EDITOR_OWNED = new Set( [ 'class', 'tabindex', 'role', 'aria-label', 'data-block', 'data-type', 'data-title', 'contenteditable', 'draggable' ] );

	function safeAttribute( name, value ) {
		const lower = name.toLowerCase();
		// autofocus would steal the editor's focus and scroll the canvas on load.
		if ( lower.startsWith( 'on' ) || lower === 'srcdoc' || lower === 'autofocus' || lower.startsWith( 'xb:' ) ) {
			return false;
		}
		// Browsers drop tabs and line breaks inside a URL: `java\tscript:` is `javascript:`.
		return ! ( URL_ATTRIBUTES.has( lower ) && typeof value === 'string' && UNSAFE_URL.test( value.replace( /[\t\n\r]/g, '' ) ) );
	}

	// ------------------------------------------------------------------ server rendering, batched

	const cache = new Map();
	const inflight = new Set();
	const listeners = new Set();
	let queue = { items: {}, tests: {} };
	let timer = null;
	// What the renderings depend on beyond their markup (published components, the release): when the server reports
	// another value, every kept rendering is drawn again (lista della spesa 149).
	let version = null;
	let checked = 0;
	let generation = 0;

	function adopt( next, fresh ) {
		if ( typeof next !== 'string' ) {
			return;
		}
		if ( version !== null && next !== version ) {
			[ ...cache.keys() ].forEach( ( k ) => ! fresh.includes( k ) && cache.delete( k ) );
			generation += 1; // The hooks ask again for what they show.
			listeners.forEach( ( l ) => l() );
		}
		version = next;
	}

	/** Back on the tab: one small request says whether a component or a release changed meanwhile. */
	function recheck() {
		if ( document.visibilityState !== 'visible' || version === null || Date.now() - checked < 5000 || ! postId() ) {
			return;
		}
		checked = Date.now();
		wp.apiFetch( { path: '/xb/v1/editor-render', method: 'POST', data: { post_id: postId(), items: {} } } )
			.then( ( res ) => adopt( res && res.version, [] ) )
			.catch( () => {} );
	}
	document.addEventListener( 'visibilitychange', recheck );
	window.addEventListener( 'focus', recheck );

	function hash( text ) {
		let h = 0x811c9dc5;
		for ( let i = 0; i < text.length; i++ ) {
			h ^= text.charCodeAt( i );
			h = Math.imul( h, 0x01000193 );
		}
		return ( h >>> 0 ).toString( 36 ) + text.length.toString( 36 );
	}

	function postId() {
		const editor = wp.data.select( 'core/editor' );
		return editor && editor.getCurrentPostId ? Number( editor.getCurrentPostId() ) || 0 : 0;
	}

	function flush() {
		timer = null;
		const batch = queue;
		queue = { items: {}, tests: {} };
		const keys = [ ...Object.keys( batch.items ).map( ( k ) => 'i:' + k ), ...Object.keys( batch.tests ).map( ( k ) => 't:' + k ) ];
		if ( keys.length === 0 ) {
			return;
		}
		wp.apiFetch( { path: '/xb/v1/editor-render', method: 'POST', data: { post_id: postId(), items: batch.items, tests: batch.tests } } )
			.then( ( res ) => {
				Object.entries( ( res && res.items ) || {} ).forEach( ( [ k, v ] ) => cache.set( 'i:' + k, typeof v === 'string' ? v : '' ) );
				Object.entries( ( res && res.tests ) || {} ).forEach( ( [ k, v ] ) => cache.set( 't:' + k, v === true ) );
				adopt( res && res.version, keys );
				checked = Date.now();
			} )
			.catch( () => {
				keys.forEach( ( k ) => cache.set( k, false ) ); // Shown as the plain blocks instead.
			} )
			.finally( () => {
				keys.forEach( ( k ) => inflight.delete( k ) );
				listeners.forEach( ( l ) => l() );
			} );
	}

	function request( kind, key, payload ) {
		const full = kind + ':' + key;
		if ( cache.has( full ) || inflight.has( full ) || ! postId() ) {
			return;
		}
		inflight.add( full );
		queue[ kind === 'i' ? 'items' : 'tests' ][ key ] = payload;
		if ( Object.keys( queue.items ).length + Object.keys( queue.tests ).length >= 300 ) {
			clearTimeout( timer );
			flush();
		} else if ( ! timer ) {
			timer = setTimeout( flush, 120 );
		}
	}

	/** The server's answer for a payload: undefined while pending, false if it failed. */
	function useServer( kind, payload ) {
		const [ , update ] = useState( 0 );
		const key = payload ? hash( payload ) : null;
		useEffect( () => {
			const listener = () => update( ( n ) => n + 1 );
			listeners.add( listener );
			return () => listeners.delete( listener );
		}, [] );
		useEffect( () => {
			if ( key ) {
				request( kind, key, payload );
			}
		}, [ key, generation ] );
		return key ? cache.get( kind + ':' + key ) : undefined;
	}

	/** Several answers at once (one hook, however many payloads). */
	function useServerMany( kind, payloads ) {
		const [ , update ] = useState( 0 );
		const keys = payloads.map( ( p ) => ( p ? hash( p ) : null ) );
		useEffect( () => {
			const listener = () => update( ( n ) => n + 1 );
			listeners.add( listener );
			return () => listeners.delete( listener );
		}, [] );
		useEffect( () => {
			keys.forEach( ( key, i ) => key && request( kind, key, payloads[ i ] ) );
		}, [ keys.join( '|' ), generation ] );
		return keys.map( ( key ) => ( key ? cache.get( kind + ':' + key ) : undefined ) );
	}

	/*
	 * Server HTML made inert, with a closed list of elements and attributes (doc 23, voce 168): the markup is parsed
	 * once, in a document that runs nothing, and the nodes that pass are built again in the editor's document, one by
	 * one. Nothing is written back as HTML and parsed a second time, so a tree that a second parse would read
	 * differently (SVG or MathML content around raw text, the classic mutation XSS) cannot turn into markup that runs.
	 * What the site shows stays: every HTML content element, static SVG (no animation, which could set an address),
	 * MathML, custom elements; embedded pages become an empty box with their classes and size.
	 */
	const NS_HTML = 'http://www.w3.org/1999/xhtml';
	const NS_SVG = 'http://www.w3.org/2000/svg';
	const NS_MATH = 'http://www.w3.org/1998/Math/MathML';
	const HTML_ELEMENTS = new Set( (
		'a abbr acronym address area article aside audio b bdi bdo big blockquote br button canvas caption center cite code col ' +
		'colgroup data datalist dd del details dfn dialog dir div dl dt em fieldset figcaption figure font footer form h1 h2 h3 ' +
		'h4 h5 h6 header hgroup hr i img input ins kbd label legend li listing main map mark marquee menu meter nav nobr ol ' +
		'optgroup option output p picture pre progress q rb rp rt rtc ruby s samp search section select slot small source span ' +
		'strike strong style sub summary sup table tbody td textarea tfoot th thead time tr track tt u ul var video wbr xmp'
	).split( ' ' ) );
	const SVG_ELEMENTS = new Set( (
		'svg a g path circle ellipse line polyline polygon rect text tspan textPath defs symbol use image linearGradient ' +
		'radialGradient stop clipPath mask pattern marker title desc metadata switch view foreignObject style filter feBlend ' +
		'feColorMatrix feComponentTransfer feComposite feConvolveMatrix feDiffuseLighting feDisplacementMap feDistantLight ' +
		'feDropShadow feFlood feFuncA feFuncB feFuncG feFuncR feGaussianBlur feImage feMerge feMergeNode feMorphology ' +
		'feOffset fePointLight feSpecularLighting feSpotLight feTile feTurbulence'
	).split( ' ' ) );
	const MATH_ELEMENTS = new Set( (
		'math mi mn mo ms mspace mtext mrow mfrac msqrt mroot mstyle merror mpadded mphantom menclose msub msup msubsup ' +
		'munder mover munderover mmultiscripts mprescripts none mtable mtr mtd mlabeledtr semantics annotation annotation-xml'
	).split( ' ' ) );
	// Left out with what they hold: they run code, load other documents or say nothing a reader sees.
	const DROPPED = new Set( [ 'script', 'base', 'meta', 'link', 'noscript', 'noembed', 'noframes', 'frame', 'frameset', 'portal', 'template', 'title', 'head', 'html', 'body', 'param', 'applet', 'bgsound', 'keygen' ] );
	const EMBEDS = new Set( [ 'iframe', 'object', 'embed' ] );
	const ATTRIBUTES = new Set( (
		// HTML.
		'abbr accept accept-charset accesskey action align alt autocapitalize autocomplete autoplay axis background bgcolor border ' +
		'cellpadding cellspacing char charoff charset checked cite class clear color cols colspan compact controls controlslist coords ' +
		'crossorigin datetime decoding default dir dirname disabled download enctype enterkeyhint face fetchpriority for form ' +
		'formaction formenctype formmethod formnovalidate formtarget frame headers height hidden high href hreflang hspace ' +
		'id inert inputmode ismap itemid itemprop itemref itemscope itemtype kind label lang list loading loop low max ' +
		'maxlength media method min minlength multiple muted name nohref noshade novalidate nowrap open optimum part pattern ' +
		'ping placeholder playsinline popover popovertarget popovertargetaction poster preload readonly referrerpolicy rel ' +
		'required rev reversed role rows rowspan rules scope selected shape size sizes slot span spellcheck src srclang ' +
		'srcset start step style summary tabindex target title translate type usemap valign value vspace width wrap ' +
		// SVG (names as the parser gives them, camelCase included).
		'alignment-baseline amplitude azimuth baseFrequency baseline-shift bias clip clip-path clip-rule clipPathUnits ' +
		'color-interpolation color-interpolation-filters cursor cx cy d diffuseConstant direction display divisor ' +
		'dominant-baseline dx dy edgeMode elevation exponent fill fill-opacity fill-rule filter filterUnits flood-color ' +
		'flood-opacity font-family font-size font-size-adjust font-stretch font-style font-variant font-weight fr fx fy ' +
		'glyph-orientation-horizontal glyph-orientation-vertical gradientTransform gradientUnits image-rendering in in2 ' +
		'intercept isolation k k1 k2 k3 k4 kernelMatrix kernelUnitLength lengthAdjust letter-spacing lighting-color ' +
		'limitingConeAngle marker-end marker-mid marker-start markerHeight markerUnits markerWidth mask mask-type ' +
		'maskContentUnits maskUnits mix-blend-mode mode numOctaves offset opacity operator order orient overflow ' +
		'paint-order path pathLength patternContentUnits patternTransform patternUnits pointer-events points pointsAtX ' +
		'pointsAtY pointsAtZ preserveAlpha preserveAspectRatio primitiveUnits r radius refX refY result rotate rx ry scale ' +
		'seed shape-rendering side slope spacing specularConstant specularExponent spreadMethod startOffset stdDeviation ' +
		'stitchTiles stop-color stop-opacity stroke stroke-dasharray stroke-dashoffset stroke-linecap stroke-linejoin ' +
		'stroke-miterlimit stroke-opacity stroke-width surfaceScale systemLanguage tableValues targetX targetY ' +
		'text-anchor text-decoration text-rendering textLength transform transform-origin unicode-bidi values ' +
		'vector-effect version viewBox visibility word-spacing writing-mode x x1 x2 xChannelSelector xlink:href ' +
		'xlink:title xml:lang xml:space y y1 y2 yChannelSelector z zoomAndPan focusable ' +
		// MathML.
		'accent accentunder close columnalign columnlines columnspacing columnspan depth displaystyle encoding fence frame ' +
		'framespacing linethickness lspace mathbackground mathcolor mathsize mathvariant maxsize minsize movablelimits ' +
		'notation rowalign rowlines rowspacing rspace scriptlevel separator separators stretchy symmetric voffset'
	).split( ' ' ) );
	// data-* and aria-*, but not the data- spellings of client frameworks that evaluate their value as code.
	const PATTERN_ATTRIBUTES = /^(?:data-(?!hx-|ng-|on-|bind)[a-z0-9_.:-]+|aria-[a-z]+)$/;

	function allowedElement( node ) {
		const name = node.localName;
		if ( node.namespaceURI === NS_SVG ) {
			return SVG_ELEMENTS.has( name );
		}
		if ( node.namespaceURI === NS_MATH ) {
			return MATH_ELEMENTS.has( name );
		}
		return node.namespaceURI === NS_HTML && ( HTML_ELEMENTS.has( name ) || ( name.includes( '-' ) && TAG.test( name ) ) );
	}

	function allowedAttribute( node, attribute ) {
		const name = node.namespaceURI === NS_HTML ? attribute.name.toLowerCase() : attribute.name;
		// A named image or form would shadow the canvas document's own properties (document.forms, getElementById…).
		if ( name === 'name' && node.namespaceURI === NS_HTML && ( node.localName === 'img' || node.localName === 'form' ) ) {
			return false;
		}
		return ( ATTRIBUTES.has( name ) || PATTERN_ATTRIBUTES.test( name.toLowerCase() ) ) && safeAttribute( attribute.name, attribute.value );
	}

	function copyAttributes( from, to, names ) {
		[ ...from.attributes ].forEach( ( a ) => {
			if ( ( names ? names.includes( a.name ) : allowedAttribute( from, a ) ) ) {
				try {
					if ( a.namespaceURI ) {
						to.setAttributeNS( a.namespaceURI, a.name, a.value );
					} else {
						to.setAttribute( a.name, a.value );
					}
				} catch ( e ) {
					// A name the DOM refuses: left out, as the browser would.
				}
			}
		} );
	}

	/** The checked copy of `from`'s children, built in `doc`, appended to `to`. */
	function rebuild( from, to, doc ) {
		from.childNodes.forEach( ( node ) => {
			if ( node.nodeType === 3 ) {
				to.appendChild( doc.createTextNode( node.data ) );
				return;
			}
			if ( node.nodeType !== 1 ) {
				return; // Comments and processing instructions.
			}
			const name = node.localName;
			if ( node.namespaceURI === NS_HTML && EMBEDS.has( name ) ) {
				const box = doc.createElementNS( NS_HTML, 'div' );
				copyAttributes( node, box, [ 'class', 'width', 'height', 'style' ] );
				box.classList.add( 'xb-editor-embed' );
				to.appendChild( box );
				return;
			}
			if ( ( node.namespaceURI === NS_HTML && DROPPED.has( name ) ) || name === 'script' ) {
				return;
			}
			if ( ! allowedElement( node ) ) {
				rebuild( node, to, doc ); // Unknown or animated: what it holds, without the element.
				return;
			}
			const copy = doc.createElementNS( node.namespaceURI, name );
			copyAttributes( node, copy );
			rebuild( node, copy, doc );
			to.appendChild( copy );
		} );
	}

	/** The server's HTML as checked nodes of `doc` (the editor's canvas), ready to insert. */
	function clean( html, doc ) {
		const parsed = new DOMParser().parseFromString( '<body>' + html, 'text/html' );
		const out = doc.createDocumentFragment();
		rebuild( parsed.body, out, doc );
		return out;
	}

	/** Puts the cleaned HTML in the node of `ref`, when there is HTML to show (null leaves the node to React). */
	function useCleanContent( ref, html ) {
		useLayoutEffect( () => {
			const node = ref.current;
			if ( node && typeof html === 'string' ) {
				node.replaceChildren( clean( html, node.ownerDocument ) );
			}
		}, [ html ] );
	}

	/*
	 * The block editor styles every block wrapper (`position: relative`, margins, overflow-wrap…) with selectors
	 * more specific than the site's: an absolutely positioned caption would sit in the flow. Its layout rules
	 * are narrowed to leave Histeon blocks alone, so the site's CSS decides, as on the site. Outlines, focus and
	 * selection rules (pseudo-elements, states) are untouched.
	 */
	const LAYOUT = /(^|[\s;{])(position|margin(-[a-z]+)?|padding(-[a-z]+)?|overflow(-[a-z]+)?|overflow-wrap|word-break|display|width|max-width|min-width|box-sizing|z-index)\s*:/;
	function narrowEditorRules( doc ) {
		const walk = ( rules ) => {
			for ( const rule of rules ) {
				if ( rule.cssRules && ! rule.selectorText ) {
					walk( rule.cssRules );
					continue;
				}
				const selector = rule.selectorText || '';
				if ( ! selector.includes( '.block-editor-block-list__block' ) || selector.includes( '::' ) || selector.includes( ':not([data-type^="xb/"])' ) || ! LAYOUT.test( rule.style.cssText ) ) {
					continue;
				}
				try {
					rule.selectorText = selector.replace( /\.block-editor-block-list__block(?![\w-])/g, '.block-editor-block-list__block:not([data-type^="xb/"])' );
				} catch ( e ) {
					// A selector this browser cannot rewrite: left as it is.
				}
			}
		};
		for ( const sheet of doc.styleSheets ) {
			const href = sheet.href || '';
			if ( href && ! href.includes( '/wp-includes/css/dist/' ) ) {
				continue; // Only WordPress's own editor styles; the site's stylesheets are never touched.
			}
			try {
				walk( sheet.cssRules );
			} catch ( e ) {
				// Cross-origin sheet: not readable, not ours.
			}
		}
	}

	// Links and forms of the page must not navigate the editor away.
	const guarded = new WeakSet();
	function useGuards( ref ) {
		useEffect( () => {
			const doc = ref.current && ref.current.ownerDocument;
			if ( ! doc || guarded.has( doc ) ) {
				return;
			}
			guarded.add( doc );
			narrowEditorRules( doc );
			// Stylesheets still loading when the first block mounts.
			setTimeout( () => narrowEditorRules( doc ), 1500 );
			// Links and image-map areas of the content never navigate the editor, not even with the middle button;
			// SVG links too, whose address may be xlink:href (any namespace: *|href).
			const follow = ( event ) => {
				const link = event.target && event.target.closest && event.target.closest( 'a[*|href], area[href]' );
				if ( link && link.closest( '[data-type^="xb/"]' ) ) {
					event.preventDefault();
				}
			};
			doc.addEventListener( 'click', follow, true );
			doc.addEventListener( 'auxclick', follow, true );
			doc.addEventListener( 'submit', ( event ) => {
				if ( event.target && event.target.closest && event.target.closest( '[data-type^="xb/"]' ) ) {
					event.preventDefault();
				}
			}, true );
		}, [] );
	}

	// ------------------------------------------------------------------ blocks

	/** The block's attributes on its DOM node (all but `class`, which goes through the block props). */
	function useDomAttributes( ref, attributes ) {
		const applied = useRef( [] );
		const json = JSON.stringify( attributes );
		useLayoutEffect( () => {
			const node = ref.current;
			if ( ! node ) {
				return;
			}
			applied.current.forEach( ( name ) => {
				if ( ! Object.prototype.hasOwnProperty.call( attributes, name ) ) {
					node.removeAttribute( name );
				}
			} );
			const names = [];
			Object.entries( attributes ).forEach( ( [ name, value ] ) => {
				if ( EDITOR_OWNED.has( name.toLowerCase() ) || ! safeAttribute( name, value ) ) {
					return;
				}
				try {
					node.setAttribute( name, value === true ? '' : String( value ) );
					names.push( name );
				} catch ( e ) {
					// An attribute name the DOM refuses: skipped, as the browser would.
				}
			} );
			applied.current = names;
		}, [ json ] );
	}

	/** Attributes with expressions, as the server renders them on this page. */
	function useResolved( attributes ) {
		const attrs = attributes.attributes || {};
		const dynamic = Object.values( attrs ).some( ( v ) => typeof v === 'string' && v.includes( '{' ) );
		const markup = useMemo( () => ( dynamic ? serialize( [ createBlock( 'xb/element', { tag: attributes.tag, attributes: attrs }, [] ) ] ) : null ), [ dynamic, attributes.tag, JSON.stringify( attrs ) ] );
		const html = useServer( 'i', markup );
		if ( ! dynamic ) {
			return attrs;
		}
		const out = {};
		Object.entries( attrs ).forEach( ( [ k, v ] ) => {
			if ( ! ( typeof v === 'string' && v.includes( '{' ) ) ) {
				out[ k ] = v;
			}
		} );
		if ( typeof html === 'string' ) {
			const node = new DOMParser().parseFromString( '<body>' + html, 'text/html' ).body.firstElementChild;
			if ( node ) {
				[ ...node.attributes ].forEach( ( a ) => {
					out[ a.name ] = a.value;
				} );
			}
		}
		return out;
	}

	function ElementEdit( { attributes } ) {
		const ref = useRef();
		const tag = TAG.test( attributes.tag || '' ) ? attributes.tag : 'div';
		const inert = INERT.has( tag ) || tag !== attributes.tag;
		const attrs = useResolved( attributes );
		const className = [ typeof attrs.class === 'string' ? attrs.class : '', inert ? 'xb-editor-embed' : '' ].join( ' ' ).trim();
		const blockProps = useBlockProps( { ref, className } );
		const innerProps = useInnerBlocksProps( blockProps, { renderAppender: false } );
		useDomAttributes( ref, inert ? pick( attrs, [ 'id', 'width', 'height', 'style' ] ) : attrs );
		useGuards( ref );
		if ( inert ) {
			return el( 'div', blockProps );
		}
		return VOID.has( tag ) ? el( tag, blockProps ) : el( tag, innerProps );
	}

	function pick( object, names ) {
		const out = {};
		names.forEach( ( n ) => {
			if ( Object.prototype.hasOwnProperty.call( object, n ) ) {
				out[ n ] = object[ n ];
			}
		} );
		return out;
	}

	const DYNAMIC_TEXT = /(^|[^\\])\{/;

	function TextEdit( { attributes, setAttributes, isSelected } ) {
		const ref = useRef();
		const content = attributes.content || '';
		const dynamic = ! attributes.verbatim && DYNAMIC_TEXT.test( content );
		const markup = useMemo( () => ( dynamic ? serialize( [ createBlock( 'xb/text', { content } ) ] ) : null ), [ dynamic, content ] );
		const rendered = useServer( 'i', markup );
		const blockProps = useBlockProps( { ref, className: 'xb-editor-text' } );
		const showing = ! attributes.verbatim && dynamic && ! isSelected && typeof rendered === 'string';
		useCleanContent( ref, showing ? rendered : null );
		if ( attributes.verbatim ) {
			return el( 'span', { ...blockProps, hidden: true } );
		}
		if ( showing ) {
			return el( 'span', { ...blockProps, key: 'rendered', title: __( 'Testo dinamico: selezionalo per modificarlo', 'xb' ) } );
		}
		return el( RichText, {
			...blockProps,
			tagName: 'span',
			value: content,
			onChange: ( value ) => setAttributes( { content: value } ),
			allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/code', 'core/strikethrough', 'core/subscript', 'core/superscript' ],
			placeholder: __( 'Testo', 'xb' ),
		} );
	}

	/**
	 * A block shown as the site renders it (loops, components, raw HTML, the post content). Its inner blocks
	 * stay in the document (list view, the builder), hidden. Edited in Histeon.
	 */
	function Rendered( describe, options ) {
		const withSlots = Boolean( options && options.slots );
		return function Edit( { attributes, clientId } ) {
			const ref = useRef();
			const markup = useSelect( ( select ) => {
				const block = select( 'core/block-editor' ).getBlock( clientId );
				return block ? serialize( [ block ] ) : '';
			}, [ clientId ] );
			const html = useServer( 'i', markup || null );
			const label = describe( attributes );
			const blockProps = useBlockProps( { ref, className: 'xb-editor-rendered xb-editor-locked', title: sprintf( __( '%s — si modifica in Histeon', 'xb' ), label ) } );
			// A component with slots shows its areas under it, editable; the component itself stays as the site shows it.
			const innerProps = useInnerBlocksProps( withSlots ? { className: 'xb-editor-slots', 'aria-label': __( 'Aree modificabili del componente', 'xb' ) } : { className: 'xb-editor-hidden' }, withSlots ? { renderAppender: false, allowedBlocks: [ 'xb/slot-fill' ], templateLock: isClient() ? 'all' : false } : { renderAppender: false } );
			const renderedRef = useRef();
			useGuards( ref );
			useCleanContent( renderedRef, typeof html === 'string' ? html : null );
			let shown;
			if ( typeof html === 'string' ) {
				shown = el( 'div', { key: 'rendered', ref: renderedRef, className: 'xb-editor-rendered' } );
			} else if ( html === false ) {
				shown = el( 'div', { key: 'pending', className: 'xb-editor-pending' }, label );
			} else {
				shown = el( 'div', { key: 'pending', className: 'xb-editor-pending' } );
			}
			return el( 'div', blockProps, shown, el( 'div', innerProps ) );
		};
	}

	/** The props of a component, as its definition asks for them. */
	const schemas = {};
	function useSchema( ref ) {
		const [ , loaded ] = useState( 0 );
		useEffect( () => {
			if ( ! ref || schemas[ ref ] ) {
				return;
			}
			schemas[ ref ] = { props: {} };
			wp.apiFetch( { path: '/xb/v1/component-schema?ref=' + encodeURIComponent( ref ) } )
				.then( ( answer ) => { schemas[ ref ] = answer; loaded( ( n ) => n + 1 ); } )
				.catch( () => { delete schemas[ ref ]; } );
		}, [ ref ] );
		return schemas[ ref ] || { props: {} };
	}

	function ImageField( { label, value, onChange } ) {
		return el( 'div', { className: 'xb-prop-image' },
			el( 'p', null, label ),
			value ? el( 'img', { src: value, alt: '', style: { maxWidth: '100%' } } ) : null,
			isClient() ? null : el( wp.blockEditor.MediaUpload, {
				allowedTypes: [ 'image' ],
				onSelect: ( media ) => onChange( media.url ),
				render: ( { open } ) => el( wp.components.Button, { variant: 'secondary', onClick: open }, value ? __( 'Cambia immagine', 'xb' ) : __( 'Scegli immagine', 'xb' ) ),
			} ),
			value && ! isClient() ? el( wp.components.Button, { variant: 'link', isDestructive: true, onClick: () => onChange( '' ) }, __( 'Togli', 'xb' ) ) : null
		);
	}

	/** A block with rows: each row, an instance in its slot, is selected and changed on its own. */
	function RowsEdit() {
		const blockProps = useBlockProps( { className: 'xb-editor-rows' } );
		return el( 'div', useInnerBlocksProps( blockProps, { renderAppender: false } ) );
	}

	function AddRow( { clientId, rows } ) {
		const fill = useSelect( ( select ) => select( 'core/block-editor' ).getBlocks( clientId )[ 0 ], [ clientId ] );
		const { insertBlock } = wp.data.useDispatch( 'core/block-editor' );
		const add = () => {
			const row = createBlock( 'xb/component', { ref: rows } );
			if ( fill ) {
				insertBlock( row, undefined, fill.clientId );
			} else {
				insertBlock( createBlock( 'xb/slot-fill', { name: 'default' }, [ row ] ), 0, clientId );
			}
		};
		return el( wp.components.Button, { variant: 'secondary', onClick: add }, __( 'Aggiungi', 'xb' ) );
	}

	/**
	 * Client mode (docs/spec/client-mode.md): the client sees the panels, read-only. Read when drawn: this file
	 * loads before the inspector's settings.
	 */
	const isClient = () => Boolean( window.xbInspector && window.xbInspector.client );

	/** A short choice as buttons, a long one as a menu (list 133). */
	function ChoiceField( { label, help, value, options, onChange } ) {
		const { BaseControl, Button, SelectControl } = wp.components;
		if ( options.length > 5 ) {
			return el( SelectControl, { label, help, value: value || '', disabled: isClient(), options: options.map( ( option ) => ( { value: option, label: option } ) ), onChange, __nextHasNoMarginBottom: true } );
		}
		return el( BaseControl, { label, help, __nextHasNoMarginBottom: true },
			el( 'div', { className: 'xb-prop-choice', role: 'group', 'aria-label': label },
				options.map( ( option ) => el( Button, { key: option, variant: option === value ? 'primary' : 'secondary', size: 'compact', isPressed: option === value, disabled: isClient(), onClick: () => onChange( option ) }, option ) )
			)
		);
	}

	/** A link with the site's own pages as suggestions (list 133). */
	function LinkField( { label, help, value, onChange } ) {
		const { BaseControl, TextControl } = wp.components;
		const URLInput = wp.blockEditor.URLInput;
		if ( ! URLInput || isClient() ) {
			return el( TextControl, { label, help, type: 'url', value: value || '', disabled: isClient(), onChange, __nextHasNoMarginBottom: true } );
		}
		return el( BaseControl, { label, help, __nextHasNoMarginBottom: true }, el( URLInput, { value: value || '', onChange: ( url ) => onChange( url ), __nextHasNoMarginBottom: true } ) );
	}

	/** Back to the component's own value, when the page changed it. */
	function Reset( { definition, value, onReset } ) {
		if ( isClient() || value === undefined || definition.default === undefined || JSON.stringify( value ) === JSON.stringify( definition.default ) ) {
			return null;
		}
		return el( wp.components.Button, { variant: 'link', className: 'xb-prop-reset', onClick: onReset }, __( 'Valore predefinito', 'xb' ) );
	}

	/** The slots without content yet: a button adds an area for each (list 134). */
	function AddSlots( { clientId, slots } ) {
		const filled = useSelect( ( select ) => select( 'core/block-editor' ).getBlocks( clientId ).filter( ( b ) => b.name === 'xb/slot-fill' ).map( ( b ) => b.attributes.name || 'default' ), [ clientId ] );
		const { insertBlock } = wp.data.useDispatch( 'core/block-editor' );
		const missing = slots.filter( ( name ) => ! filled.includes( name ) );
		if ( isClient() || ! missing.length ) {
			return null;
		}
		return el( 'div', { className: 'xb-prop-slots' },
			el( 'p', null, __( 'Aree da riempire', 'xb' ) ),
			missing.map( ( name ) => el( wp.components.Button, { key: name, variant: 'secondary', onClick: () => insertBlock( createBlock( 'xb/slot-fill', { name }, [ createBlock( 'core/paragraph' ) ] ), undefined, clientId ) }, sprintf( __( 'Aggiungi contenuto a «%s»', 'xb' ), name ) ) )
		);
	}

	/** Shown as the site shows it; what it says changes in the side panel, whatever the component. */
	function ComponentEdit( props ) {
		const { attributes, setAttributes } = props;
		const { InspectorControls } = wp.blockEditor;
		const { PanelBody, TextControl, ToggleControl } = wp.components;
		const values = attributes.props || {};
		const schema = useSchema( attributes.ref );
		const set = ( name, value ) => setAttributes( { props: { ...values, [ name ]: value } } );
		const fields = Object.entries( schema.props ).map( ( [ name, definition ] ) => {
			const literal = typeof values[ name ] !== 'object' || null === values[ name ];
			const value = values[ name ] === undefined ? definition.default : values[ name ];
			const label = definition.label || name;
			const help = definition.help || definition.description || undefined;
			const common = { label, help, disabled: isClient(), __nextHasNoMarginBottom: true };
			if ( ! literal ) {
				return null; // A value worked out by an expression changes in Histeon.
			}
			let field;
			switch ( definition.type ) {
				case 'enum': field = el( ChoiceField, { label, help, value, options: definition.options || [], onChange: ( next ) => set( name, next ) } ); break;
				case 'boolean': field = el( ToggleControl, { ...common, checked: Boolean( value ), onChange: ( next ) => set( name, next ) } ); break;
				case 'number': field = el( TextControl, { ...common, type: 'number', value: value === undefined || value === null ? '' : value, onChange: ( next ) => set( name, Number( next ) ) } ); break;
				case 'image': field = el( ImageField, { label, value: value || '', onChange: ( next ) => set( name, next ) } ); break;
				case 'url': field = el( LinkField, { label, help, value, onChange: ( next ) => set( name, next ) } ); break;
				case 'array': case 'object': return null;
				case 'rich-text': field = el( wp.components.BaseControl, { label, help, __nextHasNoMarginBottom: true },
					el( RichText, { tagName: 'div', className: 'xb-prop-rich', value: value || '', readOnly: isClient(), allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/code', 'core/subscript', 'core/superscript' ], onChange: ( next ) => set( name, next ) } ) ); break;
				default: field = el( TextControl, { ...common, value: value || '', onChange: ( next ) => set( name, next ) } );
			}
			return el( 'div', { key: name, className: 'xb-prop' }, field, el( Reset, { definition, value: values[ name ], onReset: () => { const next = { ...values }; delete next[ name ]; setAttributes( { props: next } ); } } ) );
		} );
		const slots = ! schema.rows && Array.isArray( schema.slots ) ? schema.slots : [];
		const panel = fields.some( Boolean ) || schema.rows || slots.length;
		return el( wp.element.Fragment, null,
			el( schema.rows ? RowsEdit : slots.length ? RenderedWithSlots : RenderedComponent, props ),
			panel ? el( InspectorControls, null, el( PanelBody, { title: attributes.label || __( 'Contenuto', 'xb' ) },
				isClient() ? el( 'p', { className: 'xb-prop-readonly' }, __( 'In sola lettura: le modifiche le fa chi gestisce il sito.', 'xb' ) ) : null,
				fields,
				schema.rows && ! isClient() ? el( AddRow, { clientId: props.clientId, rows: schema.rows } ) : null,
				slots.length ? el( AddSlots, { clientId: props.clientId, slots } ) : null
			) ) : null
		);
	}
	const RenderedComponent = Rendered( ( a ) => a.label || sprintf( __( 'Componente %s', 'xb' ), a.ref ) );
	const RenderedWithSlots = Rendered( ( a ) => a.label || sprintf( __( 'Componente %s', 'xb' ), a.ref ), { slots: true } );

	// Which branch of each condition the site shows; the others stay in the document, hidden.
	const ActiveBranch = createContext( undefined );

	function IfEdit( { clientId } ) {
		const branches = useSelect( ( select ) => select( 'core/block-editor' ).getBlocks( clientId ), [ clientId ] );
		const tests = branches.map( ( b ) => ( b.attributes && b.attributes.test ) || null );
		const results = useServerMany( 't', tests );
		let active = null;
		for ( let i = 0; i < branches.length; i++ ) {
			if ( tests[ i ] === null || results[ i ] === true ) {
				active = branches[ i ].clientId;
				break;
			}
			if ( results[ i ] === undefined ) {
				break; // Still asking the server: show nothing yet rather than the wrong branch.
			}
		}
		const blockProps = useBlockProps( { className: 'xb-editor-transparent' } );
		return el( ActiveBranch.Provider, { value: active }, el( 'div', useInnerBlocksProps( blockProps, { renderAppender: false } ) ) );
	}

	function BranchEdit( { clientId } ) {
		const active = useContext( ActiveBranch );
		const blockProps = useBlockProps( { className: 'xb-editor-transparent', hidden: active !== clientId } );
		return el( 'div', useInnerBlocksProps( blockProps, { renderAppender: false } ) );
	}

	/** The content of one slot of a component (list 134): named, with its blocks; inside rows it stays transparent. */
	function SlotFillEdit( { attributes, clientId } ) {
		const parent = useSelect( ( select ) => {
			const editor = select( 'core/block-editor' );
			const id = editor.getBlockRootClientId( clientId );
			return id ? editor.getBlock( id ) : null;
		}, [ clientId ] );
		const ref = parent && parent.name === 'xb/component' ? parent.attributes.ref : null;
		const schema = ref ? schemas[ ref ] : null;
		const area = Boolean( schema && ! schema.rows && Array.isArray( schema.slots ) && schema.slots.length );
		const blockProps = useBlockProps( { className: area ? 'xb-editor-slot-fill' : 'xb-editor-transparent' } );
		const innerProps = useInnerBlocksProps( blockProps, { renderAppender: area && ! isClient() ? InnerBlocks.ButtonBlockAppender : false, templateLock: isClient() ? 'all' : false } );
		if ( ! area ) {
			return el( 'div', innerProps );
		}
		return el( 'div', innerProps, el( 'span', { className: 'xb-editor-slot-label', contentEditable: false }, sprintf( __( 'Area «%s»', 'xb' ), attributes.name || 'default' ) ), innerProps.children );
	}

	function Transparent() {
		const blockProps = useBlockProps( { className: 'xb-editor-transparent' } );
		return el( 'div', useInnerBlocksProps( blockProps, { renderAppender: false } ) );
	}

	function Placeholder( label ) {
		return function Edit( { attributes } ) {
			const text = typeof label === 'function' ? label( attributes ) : label;
			return el( 'div', useBlockProps( { className: 'xb-editor-pending' } ), text );
		};
	}

	const saveInner = () => el( InnerBlocks.Content );
	const saveNothing = () => null;

	registerBlockType( 'xb/element', { edit: ElementEdit, save: saveInner } );
	registerBlockType( 'xb/text', { edit: TextEdit, save: saveNothing } );
	registerBlockType( 'xb/if', { edit: IfEdit, save: saveInner } );
	registerBlockType( 'xb/branch', { edit: BranchEdit, save: saveInner } );
	registerBlockType( 'xb/loop', {
		edit: Rendered( ( a ) => sprintf( __( 'Ripeti %s', 'xb' ), ( a.source && ( a.source.id || a.source.expr ) ) || '' ) ),
		save: saveInner,
	} );
	registerBlockType( 'xb/component', { edit: ComponentEdit, save: saveInner } );
	registerBlockType( 'xb/raw', { edit: Rendered( () => __( 'HTML', 'xb' ) ), save: saveNothing } );
	registerBlockType( 'xb/post-content', { edit: Rendered( () => __( 'Contenuto del post', 'xb' ) ), save: saveNothing } );
	registerBlockType( 'xb/slot-fill', { edit: SlotFillEdit, save: saveInner } );
	registerBlockType( 'xb/slot', { edit: Placeholder( ( a ) => sprintf( __( 'Slot %s', 'xb' ), a.name ) ), save: saveNothing } );
	registerBlockType( 'xb/passthrough', { edit: Transparent, save: saveInner } );
}( window.wp ) );
