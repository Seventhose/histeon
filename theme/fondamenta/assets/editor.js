/**
 * Fondamenta's blocks in the block editor (docs/spec/fondamenta.md §5). Plain wp.element, no build step.
 *
 * save() must give back the HTML the conversion wrote, or the editor marks the block as changed: the element is
 * written with its tag and its attributes in the order stored, nothing added (no wp-block-* class).
 */
( function ( wp ) {
	const { registerBlockType, registerBlockVariation } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InnerBlocks, RichText, InspectorControls, useBlockProps, useInnerBlocksProps } = wp.blockEditor;
	const { PanelBody, TextControl, ToggleControl, Notice } = wp.components;
	const { __, sprintf } = wp.i18n;

	const VUOTI = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' ];

	/** Stored attributes, `[[name, value], …]`, as props of the saved element: names and values as written. */
	function salvati( attributi ) {
		const out = {};
		( attributi || [] ).forEach( ( pair ) => {
			if ( Array.isArray( pair ) && typeof pair[ 0 ] === 'string' ) {
				out[ pair[ 0 ] ] = typeof pair[ 1 ] === 'string' ? pair[ 1 ] : '';
			}
		} );
		return out;
	}

	/** The same attributes for the editor canvas, where React wants className, htmlFor and a style object. */
	function nellEditor( attributi ) {
		const out = {};
		Object.entries( salvati( attributi ) ).forEach( ( [ name, value ] ) => {
			const lower = name.toLowerCase();
			if ( lower.startsWith( 'on' ) || lower === 'href' || lower === 'src' || lower === 'srcset' || lower === 'action' ) {
				return;
			}
			if ( lower === 'class' ) {
				out.className = value;
			} else if ( lower === 'for' ) {
				out.htmlFor = value;
			} else if ( lower === 'style' ) {
				const style = {};
				value.split( ';' ).forEach( ( rule ) => {
					const at = rule.indexOf( ':' );
					if ( at > 0 ) {
						const prop = rule.slice( 0, at ).trim();
						style[ prop.startsWith( '--' ) ? prop : prop.replace( /-([a-z])/g, ( m, c ) => c.toUpperCase() ) ] = rule.slice( at + 1 ).trim();
					}
				} );
				out.style = style;
			} else {
				out[ name ] = value;
			}
		} );
		// An image keeps its source in the canvas: it is shown, never followed.
		const src = salvati( attributi ).src;
		if ( src ) {
			out.src = src;
		}
		return out;
	}

	function parti( list ) {
		return ( list || [] ).map( ( part ) => ( typeof part === 'string' ? part : '{' + part.chiave + ( part.modi && part.modi.length ? ' | ' + part.modi.join( ' | ' ) : '' ) + '}' ) ).join( '' );
	}

	function setClass( attributi, value ) {
		const list = ( attributi || [] ).filter( ( pair ) => pair[ 0 ] !== 'class' );
		return value ? [ [ 'class', value ], ...list ] : list;
	}

	registerBlockType( 'fondamenta/elemento', {
		edit( { attributes, setAttributes } ) {
			const { tagName, attributi, modo, contenuto, legami } = attributes;
			const tag = tagName || 'div';
			const blockProps = useBlockProps( nellEditor( attributi ) );
			const innerProps = useInnerBlocksProps( blockProps );
			const bound = legami && legami[ '#testo' ];
			let canvas;
			if ( VUOTI.includes( tag ) || modo === 'vuoto' ) {
				canvas = el( tag, blockProps );
			} else if ( bound ) {
				canvas = el( tag, blockProps, el( 'span', { className: 'fondamenta-legame' }, parti( bound ) ) );
			} else if ( modo === 'testo' ) {
				canvas = el( RichText, { ...blockProps, tagName: tag, value: contenuto, onChange: ( value ) => setAttributes( { contenuto: value } ) } );
			} else {
				canvas = el( tag, innerProps );
			}
			const others = ( attributi || [] ).filter( ( pair ) => pair[ 0 ] !== 'class' );
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Elemento', 'fondamenta' ) },
						el( TextControl, { label: __( 'Tag', 'fondamenta' ), value: tag, onChange: ( value ) => setAttributes( { tagName: /^[a-z][a-z0-9-]*$/.test( value ) ? value : tag } ), __nextHasNoMarginBottom: true } ),
						el( TextControl, { label: __( 'Classi', 'fondamenta' ), value: salvati( attributi ).class || '', onChange: ( value ) => setAttributes( { attributi: setClass( attributi, value ) } ), __nextHasNoMarginBottom: true } ),
						others.length ? el( 'p', null, __( 'Altri attributi:', 'fondamenta' ), ' ', others.map( ( pair ) => pair[ 0 ] + '="' + pair[ 1 ] + '"' ).join( ' ' ) ) : null,
						legami ? el( Notice, { status: 'info', isDismissible: false }, sprintf( /* translators: %s: bound values */ __( 'Valori presi dal contenuto mostrato: %s', 'fondamenta' ), Object.entries( legami ).map( ( [ name, list ] ) => ( name === '#testo' ? __( 'testo', 'fondamenta' ) : name ) + ' = ' + parti( list ) ).join( '; ' ) ) ) : null
					)
				),
				canvas
			);
		},
		save( { attributes } ) {
			const { tagName, attributi, modo, contenuto } = attributes;
			const tag = tagName || 'div';
			const props = salvati( attributi );
			if ( VUOTI.includes( tag ) || modo === 'vuoto' ) {
				return el( tag, props );
			}
			if ( modo === 'testo' ) {
				return el( RichText.Content, { ...props, tagName: tag, value: contenuto } );
			}
			return el( tag, props, el( InnerBlocks.Content ) );
		},
	} );

	[
		{ name: 'sezione', title: __( 'Sezione', 'fondamenta' ), attributes: { tagName: 'section' } },
		{ name: 'contenitore', title: __( 'Contenitore', 'fondamenta' ), attributes: { tagName: 'div' } },
		{ name: 'immagine', title: __( 'Immagine', 'fondamenta' ), attributes: { tagName: 'img', modo: 'vuoto' } },
		{ name: 'collegamento', title: __( 'Collegamento', 'fondamenta' ), attributes: { tagName: 'a', modo: 'testo' } },
		{ name: 'pulsante', title: __( 'Pulsante', 'fondamenta' ), attributes: { tagName: 'button', modo: 'testo', attributi: [ [ 'type', 'button' ] ] } },
	].forEach( ( variation ) => registerBlockVariation( 'fondamenta/elemento', { ...variation, scope: [ 'inserter', 'transform' ], isActive: [ 'tagName' ] } ) );

	/** Loop and condition: a frame around their blocks, with what they do written on it. */
	function contenitore( label, panel ) {
		return function Edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps( { className: 'fondamenta-editor-gruppo', 'data-etichetta': label( attributes ) } );
			const innerProps = useInnerBlocksProps( blockProps );
			return el( Fragment, null, el( InspectorControls, null, el( PanelBody, { title: label( attributes ) }, panel ? panel( attributes, setAttributes ) : null ) ), el( 'div', innerProps ) );
		};
	}

	const fonti = ( a ) => ( {
		query: __( 'query', 'fondamenta' ),
		principale: __( 'query della pagina', 'fondamenta' ),
		termini: sprintf( /* translators: %s: taxonomy */ __( 'termini di %s', 'fondamenta' ), a.tassonomia ),
		campo: sprintf( /* translators: %s: field */ __( 'valori del campo %s', 'fondamenta' ), a.campo ),
		pagine: __( 'pagine dell\'archivio', 'fondamenta' ),
		righe: sprintf( /* translators: %s: repeater field */ __( 'righe del campo %s', 'fondamenta' ), a.campo ),
		filtri: sprintf(
			{
				opzioni: /* translators: 1: filtered list, 2: filter */ __( 'opzioni del filtro %2$s di %1$s', 'fondamenta' ),
				attivi: /* translators: %s: filtered list */ __( 'filtri attivi di %s', 'fondamenta' ),
				pagine: /* translators: %s: filtered list */ __( 'pagine di %s', 'fondamenta' ),
				nascosti: /* translators: %s: filtered list */ __( 'campi nascosti di %s', 'fondamenta' ),
			}[ a.elenco ] || /* translators: %s: filtered list */ __( 'risultati di %s', 'fondamenta' ),
			a.facet,
			a.filtro
		),
	}[ a.fonte ] || a.fonte );

	registerBlockType( 'fondamenta/loop', {
		edit: contenitore( ( a ) => sprintf( /* translators: %s: source */ __( 'Loop: %s', 'fondamenta' ), fonti( a ) ) ),
		save: () => el( InnerBlocks.Content ),
	} );

	registerBlockType( 'fondamenta/se', {
		edit: contenitore(
			( a ) => {
				const chiave = ( a.legame && a.legame.chiave ) || '';
				if ( Array.isArray( a.oppure ) ) {
					const prova = ( t ) => ( t.nega ? '!' : '' ) + t.chiave + ( t.confronto && t.confronto !== 'pieno' ? ' ' + t.confronto + ' ' + t.valore : '' );
					return sprintf( a.vuoto ? /* translators: %s: tests */ __( 'Se no: %s', 'fondamenta' ) : /* translators: %s: tests */ __( 'Se: %s', 'fondamenta' ), a.oppure.map( ( g ) => g.map( prova ).join( ' e ' ) ).join( ' oppure ' ) );
				}
				if ( a.confronto ) {
					return sprintf( /* translators: %s: comparison */ __( 'Se: %s', 'fondamenta' ), chiave + ' ' + a.confronto + ' ' + a.valore );
				}
				return sprintf( a.vuoto ? /* translators: %s: value */ __( 'Se manca: %s', 'fondamenta' ) : /* translators: %s: value */ __( 'Se c\'è: %s', 'fondamenta' ), chiave );
			},
			( attributes, setAttributes ) => el( ToggleControl, { label: __( 'Mostra quando il valore manca', 'fondamenta' ), checked: !! attributes.vuoto, onChange: ( value ) => setAttributes( { vuoto: value } ), __nextHasNoMarginBottom: true } )
		),
		save: () => el( InnerBlocks.Content ),
	} );

	registerBlockType( 'fondamenta/altrimenti', {
		edit: contenitore( () => __( 'Se il loop è vuoto', 'fondamenta' ) ),
		save: () => el( InnerBlocks.Content ),
	} );

	registerBlockType( 'fondamenta/testo', {
		edit: ( { attributes } ) => {
			const parti = ( attributes.legami && attributes.legami[ '#testo' ] ) || [];
			const testo = parti.map( ( p ) => ( 'string' === typeof p ? p.replace( /<[^>]*>/g, '' ) : '{' + p.chiave + '}' ) ).join( '' );
			return el( 'span', useBlockProps( { className: 'fondamenta-editor-testo' } ), testo || __( 'Testo legato', 'fondamenta' ) );
		},
		save: () => null,
	} );

	registerBlockType( 'fondamenta/contenuto', {
		edit: () => el( 'div', useBlockProps( { className: 'fondamenta-editor-gruppo' } ), __( 'Il contenuto del post mostrato.', 'fondamenta' ) ),
		save: () => null,
	} );
}( window.wp ) );
