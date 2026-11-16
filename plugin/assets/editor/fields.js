/**
 * XB fields in the block editor — docs/spec/content.md §6. A "Campi" panel in the document sidebar for the
 * fields an administrator defined in the builder (Content Hub). Values are post meta saved with the post through
 * the REST API; the server sanitizes each one by its type and checks edit_post. A repeater is a list of rows
 * (add, remove, reorder), each with exactly its sub fields: the REST schema refuses any other key.
 */
( function ( wp, config ) {
	if ( ! wp || ! config || ! Array.isArray( config.fields ) || config.fields.length === 0 ) {
		return;
	}
	const h = wp.element.createElement;
	const { __ } = wp.i18n;
	const C = wp.components;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const blockEditor = wp.blockEditor || {};
	if ( ! Panel ) {
		return;
	}

	function ImageField( props ) {
		const id = Number( props.value ) || 0;
		const media = wp.data.useSelect( ( select ) => ( id ? select( 'core' ).getMedia( id ) : null ), [ id ] );
		const url = media && media.media_details && media.media_details.sizes && media.media_details.sizes.thumbnail ? media.media_details.sizes.thumbnail.source_url : media ? media.source_url : '';
		const Upload = blockEditor.MediaUpload;
		const Check = blockEditor.MediaUploadCheck || wp.element.Fragment;
		return h(
			C.BaseControl,
			{ label: props.field.label + ( props.field.required ? ' *' : '' ), help: props.field.help || undefined, id: 'xb-field-' + props.field.name },
			h(
				'div',
				{ className: 'xb-field-image' },
				url ? h( 'img', { src: url, alt: '' } ) : null,
				Upload
					? h( Check, null, h( Upload, {
						allowedTypes: [ 'image' ],
						value: id || undefined,
						onSelect: ( item ) => props.onChange( item && item.id ? Number( item.id ) : 0 ),
						render: ( o ) => h( C.Button, { variant: 'secondary', onClick: o.open }, id ? __( 'Sostituisci', 'xb' ) : __( 'Scegli immagine', 'xb' ) ),
					} ) )
					: null,
				id ? h( C.Button, { variant: 'tertiary', isDestructive: true, onClick: () => props.onChange( 0 ) }, __( 'Rimuovi', 'xb' ) ) : null
			)
		);
	}

	/** A file of the library, any type (doc 26 voce 211): its name, and the buttons of the image field. */
	function FileField( props ) {
		const id = Number( props.value ) || 0;
		const media = wp.data.useSelect( ( select ) => ( id ? select( 'core' ).getMedia( id ) : null ), [ id ] );
		const name = media ? ( media.source_url || '' ).split( '/' ).pop() : '';
		const Upload = blockEditor.MediaUpload;
		const Check = blockEditor.MediaUploadCheck || wp.element.Fragment;
		return h(
			C.BaseControl,
			{ label: props.field.label + ( props.field.required ? ' *' : '' ), help: props.field.help || undefined, id: 'xb-field-' + props.field.name },
			h(
				'div',
				{ className: 'xb-field-file' },
				name ? h( 'code', null, name ) : null,
				Upload
					? h( Check, null, h( Upload, {
						value: id || undefined,
						onSelect: ( item ) => props.onChange( item && item.id ? Number( item.id ) : 0 ),
						render: ( o ) => h( C.Button, { variant: 'secondary', onClick: o.open }, id ? __( 'Sostituisci', 'xb' ) : __( 'Scegli file', 'xb' ) ),
					} ) )
					: null,
				id ? h( C.Button, { variant: 'tertiary', isDestructive: true, onClick: () => props.onChange( 0 ) }, __( 'Rimuovi', 'xb' ) ) : null
			)
		);
	}

	/** A choice with several values: one checkbox per option. */
	function ChecksField( props ) {
		const f = props.field;
		const values = Array.isArray( props.value ) ? props.value : [];
		return h(
			C.BaseControl,
			{ label: f.label + ( f.required ? ' *' : '' ), help: f.help || undefined, id: 'xb-field-' + f.name },
			f.options.map( ( o ) =>
				h( C.CheckboxControl, {
					key: o.value,
					label: o.label,
					checked: values.indexOf( o.value ) !== -1,
					onChange: ( on ) => props.onChange( on ? values.concat( [ o.value ] ) : values.filter( ( v ) => v !== o.value ) ),
					__nextHasNoMarginBottom: true,
				} )
			)
		);
	}

	function PostField( props ) {
		const f = props.field;
		const records = wp.data.useSelect(
			( select ) => select( 'core' ).getEntityRecords( 'postType', f.postType, { per_page: 100, status: 'publish', orderby: 'title', order: 'asc', _fields: 'id,title' } ),
			[ f.postType ]
		);
		const options = [ { label: '—', value: '0' } ].concat(
			( records || [] ).map( ( r ) => ( { label: ( r.title && ( r.title.raw || r.title.rendered ) ) || '#' + r.id, value: String( r.id ) } ) )
		);
		return h( C.SelectControl, {
			label: f.label + ( f.required ? ' *' : '' ),
			help: f.help || undefined,
			value: String( Number( props.value ) || 0 ),
			options,
			onChange: ( v ) => props.onChange( Number( v ) || 0 ),
			__nextHasNoMarginBottom: true,
		} );
	}

	/** A row with exactly the sub fields of the repeater: the REST schema refuses any other key. */
	function cleanRow( field, row ) {
		const out = {};
		field.sub.forEach( ( s ) => {
			const v = row && Object.prototype.hasOwnProperty.call( row, s.name ) ? row[ s.name ] : undefined;
			out[ s.name ] = v !== undefined ? v : { toggle: false, number: null, decimal: null, integer: null, image: 0, file: 0, repeater: [] }[ s.type ] ?? '';
		} );
		return out;
	}

	function RepeaterField( props ) {
		const f = props.field;
		const rows = Array.isArray( props.value ) ? props.value : [];
		const set = ( next ) => props.onChange( next.map( ( r ) => cleanRow( f, r ) ) );
		const move = ( i, d ) => {
			const j = i + d;
			if ( j < 0 || j >= rows.length ) {
				return;
			}
			const next = rows.slice();
			next[ i ] = rows[ j ];
			next[ j ] = rows[ i ];
			set( next );
		};
		return h(
			C.BaseControl,
			{ label: f.label + ( f.required ? ' *' : '' ), help: f.help || undefined, id: 'xb-field-' + f.name },
			h(
				'div',
				{ className: 'xb-field-rows' },
				rows.map( ( row, i ) =>
					h(
						'div',
						{ key: i, className: 'xb-field-row', role: 'group', 'aria-label': f.label + ' ' + ( i + 1 ) },
						h(
							'div',
							{ className: 'xb-field-row-head' },
							h( 'strong', null, String( i + 1 ) ),
							h( C.Button, { icon: 'arrow-up-alt2', label: __( 'Sposta su', 'xb' ), size: 'small', disabled: i === 0, onClick: () => move( i, -1 ) } ),
							h( C.Button, { icon: 'arrow-down-alt2', label: __( 'Sposta giù', 'xb' ), size: 'small', disabled: i === rows.length - 1, onClick: () => move( i, 1 ) } ),
							h( C.Button, { icon: 'trash', label: __( 'Rimuovi la riga', 'xb' ), size: 'small', isDestructive: true, onClick: () => set( rows.filter( ( _, k ) => k !== i ) ) } )
						),
						f.sub.map( ( s ) =>
							h( Field, {
								key: s.name,
								field: Object.assign( {}, s, { name: f.name + '-' + i + '-' + s.name } ),
								value: row ? row[ s.name ] : undefined,
								onChange: ( v ) => set( rows.map( ( r, k ) => ( k === i ? Object.assign( {}, r, { [ s.name ]: v } ) : r ) ) ),
							} )
						)
					)
				),
				rows.length < ( config.maxRows || 200 )
					? h( C.Button, { variant: 'secondary', onClick: () => set( rows.concat( [ {} ] ) ) }, __( 'Aggiungi riga', 'xb' ) )
					: null
			)
		);
	}

	function MultipleField( props ) {
		const f = props.field;
		const values = Array.isArray( props.value ) ? props.value : [];
		const set = ( next ) => props.onChange( next );
		const itemField = Object.assign( {}, f, {
			multiple: false,
			label: f.label + ' ' + ( props.indexLabel || '' ),
		} );
		const emptyValue = { toggle: false, number: null, decimal: null, integer: null, image: 0, file: 0, post: 0 }[ f.type ] ?? '';
		return h(
			C.BaseControl,
			{ label: f.label + ( f.required ? ' *' : '' ), help: f.help || undefined, id: 'xb-field-' + f.name },
			h(
				'div',
				{ className: 'xb-field-multiple' },
				values.map( ( value, index ) =>
					h(
						'div',
						{ key: index, className: 'xb-field-multiple-item' },
						h( Field, {
							field: Object.assign( {}, itemField, { label: f.label + ' ' + ( index + 1 ) } ),
							value,
							onChange: ( nextValue ) => set( values.map( ( current, currentIndex ) => currentIndex === index ? nextValue : current ) ),
						} ),
						h( C.Button, { variant: 'tertiary', isDestructive: true, onClick: () => set( values.filter( ( _, currentIndex ) => currentIndex !== index ) ) }, __( 'Rimuovi valore', 'xb' ) )
					)
				),
				values.length < 1000
					? h( C.Button, { variant: 'secondary', onClick: () => set( values.concat( [ emptyValue ] ) ) }, __( 'Aggiungi valore', 'xb' ) )
					: null
			)
		);
	}

	function Field( props ) {
		const f = props.field;
		const value = props.value;
		const common = { label: f.label + ( f.required ? ' *' : '' ), help: f.help || undefined, __nextHasNoMarginBottom: true };
		if ( f.multiple && 'select' === f.type ) {
			return h( ChecksField, props );
		}
		if ( f.multiple ) {
			return h( MultipleField, props );
		}
		switch ( f.type ) {
			case 'textarea':
				return h( C.TextareaControl, Object.assign( {}, common, { value: value || '', onChange: props.onChange } ) );
			case 'number':
			case 'decimal':
			case 'integer':
				return h( C.TextControl, Object.assign( {}, common, {
					type: 'number',
					step: 'integer' === f.type ? 1 : 'any',
					value: value === null || value === undefined || value === '' ? '' : String( value ),
					// An empty number removes the value (null deletes the meta in the REST API).
					onChange: ( v ) => props.onChange( v === '' || isNaN( Number( v ) ) ? null : Number( v ) ),
				} ) );
			case 'toggle':
				return h( C.ToggleControl, Object.assign( {}, common, { checked: !! value, onChange: ( v ) => props.onChange( !! v ) } ) );
			case 'select':
				if ( f.radio ) {
					return h( C.RadioControl, Object.assign( {}, common, {
						selected: value || '',
						options: f.options.map( ( o ) => ( { label: o.label, value: o.value } ) ),
						onChange: props.onChange,
					} ) );
				}
				return h( C.SelectControl, Object.assign( {}, common, {
					value: value || '',
					options: [ { label: '—', value: '' } ].concat( f.options.map( ( o ) => ( { label: o.label, value: o.value } ) ) ),
					onChange: props.onChange,
				} ) );
			case 'image':
				return h( ImageField, props );
			case 'file':
				return h( FileField, props );
			case 'post':
				return h( PostField, props );
			case 'repeater':
				return h( RepeaterField, props );
			default:
				return h( C.TextControl, Object.assign( {}, common, {
					type: { url: 'url', email: 'email', date: 'date', time: 'time' }[ f.type ] || 'text',
					value: value || '',
					onChange: props.onChange,
				} ) );
		}
	}

	function FieldsPanel() {
		const meta = wp.data.useSelect( ( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {}, [] );
		const editPost = wp.data.useDispatch( 'core/editor' ).editPost;
		return h(
			Panel,
			{ name: 'xb-fields', title: config.title || __( 'Campi', 'xb' ), className: 'xb-fields-panel' },
			h(
				'div',
				{ className: 'xb-fields' },
				config.fields.map( ( field ) =>
					h( Field, {
						key: field.name,
						field,
						value: meta[ field.name ],
						onChange: ( v ) => editPost( { meta: { [ field.name ]: v } } ),
					} )
				)
			)
		);
	}

	wp.plugins.registerPlugin( 'xb-fields', { render: FieldsPanel } );
} )( window.wp, window.xbFields );
