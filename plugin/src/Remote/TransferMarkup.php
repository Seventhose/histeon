<?php
/**
 * @package XB
 */

declare(strict_types=1);

namespace XB\Remote;

/**
 * histos-transfer-markup-v1: the markup a portable transfer profile carries (docs/spec/transfer-markup.md), checked
 * the same way here and by Histeon Publish (`packages/cms/src/transfer-markup.ts`). The lists, the grammar sources and
 * the test vectors live in tests/fixtures/histos-transfer-markup-v1.json, a byte-identical copy of the canonical
 * fixture in Publish, which both test suites read, so the two ports cannot drift silently.
 *
 * Pure: no WordPress calls, no store, no network, so it runs in the Unit suite. Regular expressions use ASCII classes
 * written out and no `i` flag; every grammar is compiled with `/uD` (JavaScript: `u`), so PCRE and JavaScript agree.
 * A value that is not valid UTF-8 never matches a grammar and always matches a refusal pattern.
 *
 * Order of the element checks (the first failure wins, attributes in their stored order):
 * 1. tag (G1), then the content model of the element in its context (C1–C7);
 * 2. for each attribute: name syntax (G2), blocked names and form-only names (G4), blocked prefixes (G5), the
 *    allowlist of the HTML element (every one, old and new) or of the SVG element; SVG values: boolean or braces → `dynamic`, `&` or `\` →
 *    `value`, grammar → `value`, inline CSS of `style` (G7) → `style`; HTML values: numeric references (G3), the value
 *    rule of the attribute, URL schemes (G6), inline CSS (G7), form action and method (G9);
 * 3. required attributes: `action` and `method` on `form` (`form`), `srcset` on `source` (`attribute`).
 *
 * Media identities (`src`, `srcset` candidates as `histos-media://<uuid>` of the profile's images) are checked by the
 * caller, ProfileValidator::assert_page_blocks(), which knows the assets of the profile.
 *
 * @phpstan-type TransferContext array{parent: string|null, svg: bool, form: bool, button: bool}
 * @phpstan-type HtmlValueRule array{enum?: string, grammar?: string, maxCodePoints?: int, srcset?: true}
 */
final class TransferMarkup {

	public const ID = 'histos-transfer-markup-v1';

	/** Doc 23 item 121: a link (`<a href>`) to a media file of the project, as `histos-media://<uuid>`. */
	public const MEDIA_LINKS_ID = 'histos-media-links-v1';
	/** The site reads `url.path` in the expressions it receives and sends (item 189 of Publish's doc 23). */
	public const URL_PATH_ID = 'histos-url-path-v1';
	/** The site groups a query loop's result (`foto().groupBy("anno")`, item 187 of Publish's doc 23). */
	public const LOOP_GROUP_ID = 'histos-loop-group-v1';
	/**
	 * The site reads SVG drawings with text, gradients and inline style (SVG_TEXT_TAGS, SVG_TEXT_ATTRIBUTES, `aria-*` and
	 * `data-*` on SVG elements, `fill="url(#id)"`): Histeon 0.5.14 and later.
	 */
	public const SVG_TEXT_ID = 'histos-svg-text-v1';

	/** Reason codes, compared exactly by both test suites. */
	public const REASONS = array( 'tag', 'context', 'attribute', 'dynamic', 'value', 'reference', 'url', 'style', 'form', 'text', 'media' );

	/** @var TransferContext */
	public const ROOT_CONTEXT = array(
		'parent' => null,
		'svg'    => false,
		'form'   => false,
		'button' => false,
	);

	// ---------------------------------------------------------------- lists (equal to the fixture's `lists`)

	/** The HTML elements a transfer profile carries. */
	public const HTML_TAGS = array( 'a', 'abbr', 'article', 'aside', 'b', 'blockquote', 'br', 'button', 'caption', 'cite', 'code', 'dd', 'del', 'details', 'div', 'dl', 'dt', 'em', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i', 'img', 'input', 'label', 'legend', 'li', 'main', 'mark', 'nav', 'ol', 'optgroup', 'option', 'output', 'p', 'picture', 'pre', 'q', 's', 'section', 'select', 'small', 'source', 'span', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'textarea', 'th', 'thead', 'time', 'tr', 'u', 'ul' );

	/**
	 * HTML elements added by markup v1: the capability gate and the `*.attribute` value rules concern them. Since doc 23
	 * item 172 every HTML element, old and new, has a closed attribute allowlist (ELEMENT_ATTRIBUTES).
	 */
	public const NEW_HTML_TAGS = array( 'button', 'details', 'fieldset', 'form', 'input', 'label', 'legend', 'optgroup', 'option', 'output', 'picture', 'select', 'source', 'summary', 'textarea' );

	/**
	 * SVG elements added by histos-svg-text-v1: text, and gradients in `defs`. The only tags with upper-case letters are
	 * here, stored and written in this canonical case (G1).
	 */
	public const SVG_TEXT_TAGS = array( 'text', 'tspan', 'defs', 'linearGradient', 'radialGradient', 'stop' );

	/** The static inline SVG subset (the older elements, then SVG_TEXT_TAGS). */
	public const SVG_TAGS = array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'title', 'desc', 'text', 'tspan', 'defs', 'linearGradient', 'radialGradient', 'stop' );

	public const VOID_TAGS = array( 'br', 'hr', 'img', 'input', 'source', 'wbr' );

	/** Tags only a reader of histos-transfer-markup-v1 accepts (the capability gate in Publish). */
	public const MARKUP_V1_TAGS = array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'title', 'desc', 'text', 'tspan', 'defs', 'linearGradient', 'radialGradient', 'stop', 'picture', 'source', 'details', 'summary' );

	/**
	 * Inline formatting inside a text run; equal to \XB\Spec::TEXT_INLINE_HTML and to the DSL's INLINE_FORMAT.
	 *
	 * @var array<string, list<string>>
	 */
	public const INLINE_TAGS = array(
		'a'      => array( 'href', 'target', 'rel' ),
		'abbr'   => array( 'title' ),
		'b'      => array(),
		'br'     => array(),
		'code'   => array(),
		'em'     => array(),
		'i'      => array(),
		'kbd'    => array(),
		'mark'   => array(),
		'q'      => array(),
		's'      => array(),
		'small'  => array(),
		'span'   => array(),
		'strong' => array(),
		'sub'    => array(),
		'sup'    => array(),
		'time'   => array( 'datetime' ),
		'u'      => array(),
		'wbr'    => array(),
	);

	/**
	 * Attributes every HTML element accepts (except ELEMENTS_WITHOUT_GLOBAL_ATTRIBUTES), besides `aria-*` and `data-*`
	 * (GLOBAL_ATTRIBUTE_PREFIXES): the usual ones, `translate`, and microdata (inert; `itemtype` and `itemid` are URLs,
	 * checked by G6).
	 */
	public const GLOBAL_ATTRIBUTES          = array( 'class', 'id', 'style', 'title', 'lang', 'dir', 'hidden', 'tabindex', 'role', 'translate', 'itemscope', 'itemtype', 'itemprop', 'itemid', 'itemref' );
	public const GLOBAL_ATTRIBUTE_PREFIXES  = array( 'aria-', 'data-' );

	/**
	 * The closed allowlist of each HTML element besides the global attributes (doc 23 item 172: the older tags too, which
	 * until then accepted any name the blocked lists did not refuse). What the prototypes, the static import, the editor's
	 * fields and the libraries' sections write, and the standard inert attributes of each element; nothing presentational
	 * or obsolete (`align`, `border`), nothing that runs or loads (`usemap`, `popover`, `contenteditable`).
	 *
	 * @var array<string, list<string>>
	 */
	public const ELEMENT_ATTRIBUTES = array(
		'a'          => array( 'href', 'target', 'rel', 'hreflang', 'type', 'download', 'referrerpolicy' ),
		'abbr'       => array(),
		'article'    => array(),
		'aside'      => array(),
		'b'          => array(),
		'blockquote' => array( 'cite' ),
		'br'         => array(),
		'button'     => array( 'type', 'name', 'value', 'disabled' ),
		'caption'    => array(),
		'cite'       => array(),
		'code'       => array(),
		'dd'         => array(),
		'del'        => array( 'cite', 'datetime' ),
		'details'    => array( 'open', 'name' ),
		'div'        => array(),
		'dl'         => array(),
		'dt'         => array(),
		'em'         => array(),
		'fieldset'   => array( 'disabled' ),
		'figcaption' => array(),
		'figure'     => array(),
		'footer'     => array(),
		'form'       => array( 'action', 'method', 'enctype', 'autocomplete', 'novalidate' ),
		'h1'         => array(),
		'h2'         => array(),
		'h3'         => array(),
		'h4'         => array(),
		'h5'         => array(),
		'h6'         => array(),
		'header'     => array(),
		'hr'         => array(),
		'i'          => array(),
		'img'        => array( 'src', 'alt', 'width', 'height', 'srcset', 'sizes', 'loading', 'decoding', 'fetchpriority', 'referrerpolicy' ),
		'input'      => array( 'type', 'name', 'value', 'placeholder', 'required', 'disabled', 'readonly', 'checked', 'min', 'max', 'step', 'minlength', 'maxlength', 'size', 'multiple', 'pattern', 'inputmode', 'enterkeyhint', 'spellcheck', 'autocomplete' ),
		'label'      => array( 'for' ),
		'legend'     => array(),
		'li'         => array( 'value' ),
		'main'       => array(),
		'mark'       => array(),
		'nav'        => array(),
		'ol'         => array( 'start', 'reversed', 'type' ),
		'optgroup'   => array( 'label', 'disabled' ),
		'option'     => array( 'value', 'label', 'selected', 'disabled' ),
		'output'     => array( 'for', 'name' ),
		'p'          => array(),
		'picture'    => array(),
		'pre'        => array(),
		'q'          => array( 'cite' ),
		's'          => array(),
		'section'    => array(),
		'select'     => array( 'name', 'required', 'disabled', 'multiple', 'size', 'autocomplete' ),
		'small'      => array(),
		'source'     => array( 'srcset', 'sizes', 'media', 'type', 'width', 'height' ),
		'span'       => array(),
		'strong'     => array(),
		'sub'        => array(),
		'summary'    => array(),
		'sup'        => array(),
		'table'      => array(),
		'tbody'      => array(),
		'td'         => array( 'colspan', 'rowspan', 'headers' ),
		'textarea'   => array( 'name', 'rows', 'cols', 'placeholder', 'required', 'disabled', 'readonly', 'minlength', 'maxlength', 'wrap', 'autocomplete', 'spellcheck' ),
		'th'         => array( 'colspan', 'rowspan', 'headers', 'scope', 'abbr' ),
		'thead'      => array(),
		'time'       => array( 'datetime' ),
		'tr'         => array(),
		'u'          => array(),
		'ul'         => array(),
	);

	/** `source` accepts only its own list: no global attributes. */
	public const ELEMENTS_WITHOUT_GLOBAL_ATTRIBUTES = array( 'source' );

	public const SVG_COMMON_ATTRIBUTES = array( 'id', 'class', 'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'transform', 'vector-effect', 'color', 'clip-rule', 'shape-rendering', 'paint-order', 'display', 'visibility', 'role', 'aria-hidden', 'aria-label', 'aria-labelledby', 'aria-describedby' );

	/**
	 * Common attributes added by histos-svg-text-v1, on the same elements: inline style (checked as on an HTML element,
	 * G7), the language, and the presentation attributes of text.
	 */
	public const SVG_TEXT_ATTRIBUTES = array( 'style', 'lang', 'font-family', 'font-size', 'font-weight', 'font-style', 'letter-spacing', 'word-spacing', 'text-anchor', 'dominant-baseline', 'text-decoration' );

	/**
	 * histos-svg-text-v1: every `aria-*` and `data-*` name on the same elements (states a style sheet selects), with the
	 * blocked names and prefixes of G4 and G5 and the value grammar `svgData`.
	 */
	public const SVG_ATTRIBUTE_PREFIXES = array( 'aria-', 'data-' );

	/** SVG elements that accept the common attributes besides their own list (`title` and `desc` do not). */
	public const SVG_TAGS_WITH_COMMON_ATTRIBUTES = array( 'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs', 'linearGradient', 'radialGradient', 'stop' );

	/** @var array<string, list<string>> */
	public const SVG_ATTRIBUTES = array(
		'svg'            => array( 'viewBox', 'width', 'height', 'preserveAspectRatio', 'focusable' ),
		'g'              => array(),
		'path'           => array( 'd', 'pathLength' ),
		'circle'         => array( 'cx', 'cy', 'r' ),
		'ellipse'        => array( 'cx', 'cy', 'rx', 'ry' ),
		'rect'           => array( 'x', 'y', 'width', 'height', 'rx', 'ry' ),
		'line'           => array( 'x1', 'y1', 'x2', 'y2' ),
		'polyline'       => array( 'points' ),
		'polygon'        => array( 'points' ),
		'title'          => array( 'id' ),
		'desc'           => array( 'id' ),
		'text'           => array( 'x', 'y', 'dx', 'dy', 'textLength', 'lengthAdjust' ),
		'tspan'          => array( 'x', 'y', 'dx', 'dy', 'textLength', 'lengthAdjust' ),
		'defs'           => array(),
		'linearGradient' => array( 'x1', 'y1', 'x2', 'y2', 'gradientUnits', 'gradientTransform', 'spreadMethod' ),
		'radialGradient' => array( 'cx', 'cy', 'r', 'fx', 'fy', 'fr', 'gradientUnits', 'gradientTransform', 'spreadMethod' ),
		'stop'           => array( 'offset', 'stop-color', 'stop-opacity' ),
	);

	/** The only attribute names with upper-case letters, stored and written in this canonical case (G2). */
	public const SVG_CAMEL_CASE_ATTRIBUTES = array( 'viewBox', 'preserveAspectRatio', 'pathLength', 'textLength', 'lengthAdjust', 'gradientUnits', 'gradientTransform', 'spreadMethod' );

	/**
	 * G3: the named references a browser decodes in an attribute even without `;` (HTML "legacy" entities), which
	 * html_entity_decode leaves as they are: the two sides would read different values, so they are refused.
	 */
	public const LEGACY_REFERENCES = array( 'AElig', 'AMP', 'Aacute', 'Acirc', 'Agrave', 'Aring', 'Atilde', 'Auml', 'COPY', 'Ccedil', 'ETH', 'Eacute', 'Ecirc', 'Egrave', 'Euml', 'GT', 'Iacute', 'Icirc', 'Igrave', 'Iuml', 'LT', 'Ntilde', 'Oacute', 'Ocirc', 'Ograve', 'Oslash', 'Otilde', 'Ouml', 'QUOT', 'REG', 'THORN', 'Uacute', 'Ucirc', 'Ugrave', 'Uuml', 'Yacute', 'aacute', 'acirc', 'acute', 'aelig', 'agrave', 'amp', 'aring', 'atilde', 'auml', 'brvbar', 'ccedil', 'cedil', 'cent', 'copy', 'curren', 'deg', 'divide', 'eacute', 'ecirc', 'egrave', 'eth', 'euml', 'frac12', 'frac14', 'frac34', 'gt', 'iacute', 'icirc', 'iexcl', 'igrave', 'iquest', 'iuml', 'laquo', 'lt', 'macr', 'micro', 'middot', 'nbsp', 'not', 'ntilde', 'oacute', 'ocirc', 'ograve', 'ordf', 'ordm', 'oslash', 'otilde', 'ouml', 'para', 'plusmn', 'pound', 'quot', 'raquo', 'reg', 'sect', 'shy', 'sup1', 'sup2', 'sup3', 'szlig', 'thorn', 'times', 'uacute', 'ucirc', 'ugrave', 'uml', 'uuml', 'yacute', 'yen', 'yuml' );

	/**
	 * G4: refused on every element. `data-*` stays open (states and variants of components, Histeon's own behaviours
	 * `data-xb-*`, the scripts of an imported site); refused are the `data-*` names that widespread front-end libraries
	 * read as code, HTML or a request to send (doc 23 item 172): Knockout, Datastar, hyperscript, Rails UJS and Turbo,
	 * Bootstrap's HTML tooltips; with BLOCKED_PREFIXES also Dojo and Intercooler.
	 */
	public const BLOCKED_ATTRIBUTES = array(
		'formaction', 'formmethod', 'formenctype', 'formtarget', 'form', 'srcdoc', 'nonce', 'integrity', 'crossorigin',
		'xmlns', 'is', 'autofocus', 'popovertarget', 'popovertargetaction', 'command', 'commandfor', 'interestfor',
		'ping', 'attributionsrc', 'background', 'data-bind', 'data-xb-uid', 'data-signals', 'data-computed', 'data-effect',
		'data-text', 'data-show', 'data-class', 'data-attr', 'data-style', 'data-indicator', 'data-init', 'data-script',
		'data-method', 'data-remote', 'data-params', 'data-disable-with', 'data-turbo-method', 'data-turbo-stream',
		'data-html', 'data-template', 'data-sanitize', 'data-sanitize-fn', 'data-allow-list', 'data-bs-html', 'data-bs-template',
		'data-bs-sanitize', 'data-bs-sanitize-fn', 'data-bs-allow-list',
	);

	/** G4: allowed only on `form`. */
	public const FORM_ONLY_ATTRIBUTES = array( 'action', 'method' );

	/** G5: event and framework prefixes. */
	public const BLOCKED_PREFIXES = array( 'on', 'x-', 'v-', 'hx-', 'ng-', 'ng_', 'data-hx-', 'data-ng-', 'data-on-', 'data-bind-', 'data-signals-', 'data-computed-', 'data-class-', 'data-attr-', 'data-style-', 'data-indicator-', 'data-dojo-', 'data-ic-' );

	/** G6: attributes whose value is a URL checked against URL_SCHEMES. */
	public const URL_ATTRIBUTES = array( 'href', 'cite', 'longdesc', 'poster', 'data', 'codebase', 'manifest', 'imagesrcset', 'itemtype', 'itemid' );
	public const URL_SCHEMES    = array( 'http', 'https', 'mailto', 'tel' );

	/** Parents under which components, slots and slot fills are refused (as inside svg). */
	public const CONTENT_BLOCKED_PARENTS = array( 'option', 'optgroup', 'picture', 'select', 'textarea' );

	/**
	 * Enumerations of the HTML value rules, compared after ASCII lower-casing of the decoded value.
	 *
	 * @var array<string, list<string>>
	 */
	public const ENUMS = array(
		'autocomplete'     => array( 'on', 'off', 'name', 'honorific-prefix', 'given-name', 'additional-name', 'family-name', 'honorific-suffix', 'nickname', 'organization', 'organization-title', 'street-address', 'address-line1', 'address-line2', 'address-line3', 'address-level1', 'address-level2', 'address-level3', 'address-level4', 'country', 'country-name', 'postal-code', 'language', 'bday', 'bday-day', 'bday-month', 'bday-year', 'url', 'email', 'tel', 'tel-country-code', 'tel-national', 'tel-area-code', 'tel-local', 'tel-extension' ),
		'buttonType'       => array( 'button', 'submit', 'reset' ),
		'dir'              => array( 'ltr', 'rtl', 'auto' ),
		'enctype'          => array( 'application/x-www-form-urlencoded', 'multipart/form-data' ),
		'enterkeyhint'     => array( 'enter', 'done', 'go', 'next', 'previous', 'search', 'send' ),
		'formAutocomplete' => array( 'on', 'off' ),
		'inputmode'        => array( 'none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url' ),
		'inputTypes'       => array( 'text', 'search', 'email', 'tel', 'url', 'number', 'range', 'date', 'time', 'datetime-local', 'month', 'week', 'color', 'checkbox', 'radio', 'hidden', 'submit', 'reset', 'button' ),
		'sourceTypes'      => array( 'image/avif', 'image/webp', 'image/png', 'image/jpeg', 'image/gif' ),
		'spellcheck'       => array( 'true', 'false' ),
		'wrap'             => array( 'soft', 'hard' ),
	);

	/**
	 * Value rules of HTML attributes, keyed `tag.attribute`; `*.attribute` applies to every new HTML element. Every rule
	 * makes the value static: a string without `{` or `}` (otherwise `dynamic`).
	 *
	 * @var array<string, HtmlValueRule>
	 */
	public const HTML_VALUE_RULES = array(
		'*.dir'                 => array( 'enum' => 'dir' ),
		'button.type'           => array( 'enum' => 'buttonType' ),
		'details.name'          => array( 'grammar' => 'detailsName' ),
		'form.autocomplete'     => array( 'enum' => 'formAutocomplete' ),
		'form.enctype'          => array( 'enum' => 'enctype' ),
		'img.sizes'             => array( 'grammar' => 'sizes' ),
		'img.srcset'            => array(
			'grammar' => 'srcset',
			'srcset'  => true,
		),
		'input.autocomplete'    => array( 'enum' => 'autocomplete' ),
		'input.enterkeyhint'    => array( 'enum' => 'enterkeyhint' ),
		'input.inputmode'       => array( 'enum' => 'inputmode' ),
		'input.pattern'         => array( 'maxCodePoints' => 256 ),
		'input.spellcheck'      => array( 'enum' => 'spellcheck' ),
		'input.type'            => array( 'enum' => 'inputTypes' ),
		'select.autocomplete'   => array( 'enum' => 'autocomplete' ),
		'source.height'         => array( 'grammar' => 'dimension' ),
		'source.media'          => array( 'grammar' => 'sizes' ),
		'source.sizes'          => array( 'grammar' => 'sizes' ),
		'source.srcset'         => array(
			'grammar' => 'srcset',
			'srcset'  => true,
		),
		'source.type'           => array( 'enum' => 'sourceTypes' ),
		'source.width'          => array( 'grammar' => 'dimension' ),
		'textarea.autocomplete' => array( 'enum' => 'autocomplete' ),
		'textarea.wrap'         => array( 'enum' => 'wrap' ),
	);

	/**
	 * Grammar of each SVG attribute, keyed `tag.attribute` first, then `attribute`, then `prefix*` for the names of
	 * SVG_ATTRIBUTE_PREFIXES. Matched exactly (no lower-casing).
	 *
	 * @var array<string, string>
	 */
	public const SVG_VALUE_RULES = array(
		'svg.width'           => 'lengthOrAuto',
		'svg.height'          => 'lengthOrAuto',
		'width'               => 'length',
		'height'              => 'length',
		'x'                   => 'length',
		'y'                   => 'length',
		'cx'                  => 'length',
		'cy'                  => 'length',
		'r'                   => 'length',
		'x1'                  => 'length',
		'y1'                  => 'length',
		'x2'                  => 'length',
		'y2'                  => 'length',
		'rx'                  => 'lengthOrAuto',
		'ry'                  => 'lengthOrAuto',
		'viewBox'             => 'viewBox',
		'preserveAspectRatio' => 'preserveAspectRatio',
		'pathLength'          => 'number',
		'stroke-miterlimit'   => 'number',
		'd'                   => 'pathData',
		'points'              => 'points',
		'transform'           => 'transform',
		'fill'                => 'paintServer',
		'stroke'              => 'paintServer',
		'color'               => 'paint',
		'opacity'             => 'opacity',
		'fill-opacity'        => 'opacity',
		'stroke-opacity'      => 'opacity',
		'stroke-width'        => 'length',
		'stroke-dashoffset'   => 'length',
		'stroke-dasharray'    => 'dasharray',
		'fill-rule'           => 'fillRule',
		'clip-rule'           => 'fillRule',
		'stroke-linecap'      => 'strokeLinecap',
		'stroke-linejoin'     => 'strokeLinejoin',
		'vector-effect'       => 'vectorEffect',
		'shape-rendering'     => 'shapeRendering',
		'paint-order'         => 'paintOrder',
		'display'             => 'display',
		'visibility'          => 'visibility',
		'role'                => 'svgRole',
		'aria-hidden'         => 'boolean',
		'focusable'           => 'boolean',
		'class'               => 'svgClass',
		'id'                  => 'id',
		'aria-labelledby'     => 'idList',
		'aria-describedby'    => 'idList',
		'aria-label'          => 'ariaLabel',
		'text.x'              => 'lengthList',
		'text.y'              => 'lengthList',
		'tspan.x'             => 'lengthList',
		'tspan.y'             => 'lengthList',
		'dx'                  => 'lengthList',
		'dy'                  => 'lengthList',
		'textLength'          => 'length',
		'lengthAdjust'        => 'lengthAdjust',
		'fx'                  => 'length',
		'fy'                  => 'length',
		'fr'                  => 'length',
		'gradientUnits'       => 'gradientUnits',
		'gradientTransform'   => 'transform',
		'spreadMethod'        => 'spreadMethod',
		'offset'              => 'opacity',
		'stop-color'          => 'paint',
		'stop-opacity'        => 'opacity',
		'style'               => 'svgStyle',
		'lang'                => 'lang',
		'font-family'         => 'fontFamily',
		'font-size'           => 'length',
		'font-weight'         => 'fontWeight',
		'font-style'          => 'fontStyle',
		'letter-spacing'      => 'spacing',
		'word-spacing'        => 'spacing',
		'text-anchor'         => 'textAnchor',
		'dominant-baseline'   => 'dominantBaseline',
		'text-decoration'     => 'textDecoration',
		'aria-*'              => 'svgData',
		'data-*'              => 'svgData',
	);

	/** The SVG elements that hold text: plain, without markup (`text` and `tspan` since histos-svg-text-v1). */
	public const SVG_TEXT_PARENTS = array( 'title', 'desc', 'text', 'tspan' );

	// ---------------------------------------------------------------- grammars (equal to the fixture's `lists.grammars`)

	private const WS               = '[ \t\n\r\f]';
	private const NUM              = '[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?';
	private const LEN              = self::NUM . '(?:px|em|rem|%)?';
	private const IDENT            = '[A-Za-z][A-Za-z0-9_-]{0,63}';
	private const SEP              = '(?:' . self::WS . '*,' . self::WS . '*|' . self::WS . '+)';
	private const SRCSET_CANDIDATE = '[^ \t\n\r\f,]+(?:' . self::WS . '+(?:[1-9][0-9]{0,4}w|[0-9]{1,2}(?:\.[0-9]{1,2})?x))?';

	/**
	 * Regular expression sources shared with Publish, compiled here as `~source~uD`. Anchored ones match a whole value;
	 * `numericReference` is matched at each `&#`; `styleResource`, the escapes and the `*Forbidden` sets are searched.
	 *
	 * @var array<string, string>
	 */
	public const GRAMMARS = array(
		'ariaLabel'           => '^[^\x00-\x1F\x7F<>"]{1,256}$',
		'attributeName'       => '^[a-z][a-z0-9_-]*$',
		'boolean'             => '^(?:true|false)$',
		'cssCharEscape'       => '\\\\([^\r\n\f0-9a-fA-F])',
		'cssHexEscape'        => '\\\\([0-9a-fA-F]{1,6})' . self::WS . '?',
		'dasharray'           => '^(?:none|' . self::LEN . '(?:' . self::SEP . self::LEN . '){0,15})$',
		'detailsName'         => '^[A-Za-z][A-Za-z0-9_-]{0,63}$',
		'dimension'           => '^[0-9]{1,5}$',
		'display'             => '^(?:none|inline)$',
		'dominantBaseline'    => '^(?:auto|middle|central|hanging|alphabetic|ideographic|mathematical|text-before-edge|text-after-edge|text-top|text-bottom)$',
		'fillRule'            => '^(?:nonzero|evenodd)$',
		'fontFamily'          => '^(?:[A-Za-z0-9 ,\'._-]{1,256}|var\(--[A-Za-z0-9_-]{1,64}\))$',
		'fontStyle'           => '^(?:normal|italic|oblique)$',
		'fontWeight'          => '^(?:normal|bold|bolder|lighter|[1-9][0-9]{0,2}|1000)$',
		'formActionForbidden' => '[\p{Cc}\p{Z}' . "\u{FEFF}" . '\\\\#{}]',
		'gradientUnits'       => '^(?:userSpaceOnUse|objectBoundingBox)$',
		'id'                  => '^' . self::IDENT . '$',
		'idList'              => '^' . self::IDENT . '(?:' . self::WS . '+' . self::IDENT . '){0,7}$',
		'inlineAttribute'     => '^([a-zA-Z_:][a-zA-Z0-9_:.-]*)' . self::WS . '*=' . self::WS . '*(?:"([^"<>]*)"|\'([^\'<>]*)\')',
		'inlineTag'           => '^<(/?)([a-z][a-z0-9]*)([^>]*)>$',
		'lang'                => '^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8}){0,3}$',
		'length'              => '^' . self::LEN . '$',
		'lengthAdjust'        => '^(?:spacing|spacingAndGlyphs)$',
		'lengthList'          => '^' . self::LEN . '(?:' . self::SEP . self::LEN . '){0,63}$',
		'lengthOrAuto'        => '^(?:' . self::LEN . '|auto)$',
		'number'              => '^' . self::NUM . '$',
		'numericReference'    => '^&#(?:([0-9]{1,7})|[xX]([0-9A-Fa-f]{1,6}));',
		'opacity'             => '^' . self::NUM . '%?$',
		'paint'               => '^(?:#(?:[0-9A-Fa-f]{3,4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})|[A-Za-z]{3,24}|(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\([0-9A-Za-z.,%/+ -]{1,64}\)|var\(--[A-Za-z0-9_-]{1,64}\))$',
		'paintOrder'          => '^(?:normal|(?:fill|stroke|markers)(?: (?:fill|stroke|markers)){0,2})$',
		'paintServer'         => '^(?:#(?:[0-9A-Fa-f]{3,4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})|[A-Za-z]{3,24}|(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\([0-9A-Za-z.,%/+ -]{1,64}\)|var\(--[A-Za-z0-9_-]{1,64}\)|url\(#[A-Za-z][A-Za-z0-9_-]{0,63}\))$',
		'pathData'            => '^[MmZzLlHhVvCcSsQqTtAa0-9eE.,+ \t\n\r\f-]{0,32768}$',
		'points'              => '^[0-9eE.,+ \t\n\r\f-]{0,32768}$',
		'preserveAspectRatio' => '^(?:none|x(?:Min|Mid|Max)Y(?:Min|Mid|Max))(?:' . self::WS . '+(?:meet|slice))?$',
		'shapeRendering'      => '^(?:auto|optimizeSpeed|crispEdges|geometricPrecision)$',
		'sizes'               => '^[A-Za-z0-9 ().,:%+/*-]{1,512}$',
		'spacing'             => '^(?:normal|' . self::LEN . ')$',
		'spreadMethod'        => '^(?:pad|reflect|repeat)$',
		'srcset'              => '^' . self::WS . '*' . self::SRCSET_CANDIDATE . '(?:' . self::WS . '*,' . self::WS . '*' . self::SRCSET_CANDIDATE . '){0,15}' . self::WS . '*$',
		'strokeLinecap'       => '^(?:butt|round|square)$',
		'strokeLinejoin'      => '^(?:miter|miter-clip|round|bevel|arcs)$',
		'styleResource'       => 'url' . self::WS . '*\(|image-set' . self::WS . '*\(|cross-fade' . self::WS . '*\(|element' . self::WS . '*\(|src' . self::WS . '*\(|expression' . self::WS . '*\(|@import|-moz-binding|behavior' . self::WS . '*:|javascript' . self::WS . '*:|vbscript' . self::WS . '*:|data' . self::WS . '*:|<' . self::WS . '*/' . self::WS . '*style',
		'svgClass'            => '^[A-Za-z0-9_ -]{0,256}$',
		'svgData'             => '^[^\x00-\x1F\x7F<>"]{0,256}$',
		'svgRole'             => '^(?:img|presentation|none)$',
		'svgStyle'            => '^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]{0,4096}$',
		'textAnchor'          => '^(?:start|middle|end)$',
		'textDecoration'      => '^(?:none|underline|overline|line-through)$',
		'transform'           => '^' . self::WS . '*(?:(?:matrix|translate|scale|rotate|skewX|skewY)' . self::WS . '*\([0-9eE.,+ \t\n\r\f-]{0,128}\)' . self::WS . '*,?' . self::WS . '*){1,8}$',
		'urlIgnored'          => '[\x00-\x20\x7F]',
		'vectorEffect'        => '^(?:none|non-scaling-stroke)$',
		'verbatimForbidden'   => '[{}<&]',
		'viewBox'             => '^' . self::WS . '*' . self::NUM . self::SEP . self::NUM . self::SEP . self::NUM . self::SEP . self::NUM . self::WS . '*$',
		'visibility'          => '^(?:visible|hidden|collapse)$',
		'whitespace'          => '^' . self::WS . '*$',
	);

	private const MEDIA_URL = '~^histos-media://([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$~D';

	/** The PCRE pattern of a shared grammar (`~` never appears in a source; a test pins that). */
	public static function pattern( string $grammar ): string {
		if ( ! isset( self::GRAMMARS[ $grammar ] ) ) {
			throw new \LogicException( 'Unknown transfer markup grammar: ' . $grammar );
		}
		return '~' . self::GRAMMARS[ $grammar ] . '~uD';
	}

	/** Whether the value matches the grammar. Invalid UTF-8 never matches. */
	public static function matches( string $grammar, string $value ): bool {
		return 1 === preg_match( self::pattern( $grammar ), $value );
	}

	/** Whether a refusal pattern is found in the value. Invalid UTF-8 always counts as found. */
	private static function contains( string $grammar, string $value ): bool {
		return 0 !== preg_match( self::pattern( $grammar ), $value );
	}

	private static function is_whitespace( string $value ): bool {
		return self::matches( 'whitespace', $value );
	}

	private static function violation( string $reason, string $message ): TransferMarkupViolation {
		return new TransferMarkupViolation( $reason, $message );
	}

	/**
	 * The context of the children of an element; conditions, branches and loops pass their own context through.
	 *
	 * @param TransferContext $ctx
	 * @return TransferContext
	 */
	public static function child_context( array $ctx, string $tag ): array {
		return array(
			'parent' => $tag,
			'svg'    => $ctx['svg'] || 'svg' === $tag,
			'form'   => $ctx['form'] || 'form' === $tag,
			'button' => $ctx['button'] || 'button' === $tag,
		);
	}

	/** The asset id of a portable media identity (`histos-media://` and a lower-case UUID), or null. */
	public static function media_id( string $value ): ?string {
		return 1 === preg_match( self::MEDIA_URL, $value, $match ) ? $match[1] : null;
	}

	// ---------------------------------------------------------------- G3: numeric character references

	private static function allowed_reference_code_point( int $code ): bool {
		if ( ! ( ( $code >= 0x20 && $code <= 0x7E ) || ( $code >= 0xA0 && $code <= 0x10FFFF ) ) ) {
			return false;
		}
		if ( $code >= 0xD800 && $code <= 0xDFFF ) {
			return false;
		}
		if ( $code >= 0xFDD0 && $code <= 0xFDEF ) {
			return false;
		}
		// The last two code points of every plane are noncharacters, which html_entity_decode leaves undecoded.
		return ( $code & 0xFFFF ) < 0xFFFE;
	}

	/** G3: every `&#` is a complete reference (with `;`) to a code point both decoders decode the same way. */
	public static function references_are_safe( string $value ): bool {
		for ( $at = strpos( $value, '&#' ); false !== $at; $at = strpos( $value, '&#', $at + 2 ) ) {
			if ( 1 !== preg_match( self::pattern( 'numericReference' ), substr( $value, $at ), $match, PREG_UNMATCHED_AS_NULL ) ) {
				return false;
			}
			$code = null !== $match[1] ? (int) $match[1] : (int) hexdec( (string) $match[2] );
			if ( ! self::allowed_reference_code_point( $code ) ) {
				return false;
			}
		}
		return true;
	}

	private static function assert_references( string $value, string $what ): void {
		if ( ! self::references_are_safe( $value ) ) {
			throw self::violation( 'reference', $what . ' contains an incomplete or disallowed numeric character reference, which is not allowed in markup from Histeon CMS: write the character itself, or the reference with its semicolon.' );
		}
	}

	/** G3 for named references: a legacy one without `;` (`&amp`, `&nbsp`), which browsers decode in an attribute. */
	private static function assert_named_references( string $value, string $what ): void {
		if ( ! str_contains( $value, '&' ) ) {
			return;
		}
		$names = implode( '|', array_map( static fn ( string $name ): string => preg_quote( $name, '/' ), self::LEGACY_REFERENCES ) );
		if ( 1 === preg_match( '/&(?:' . $names . ')(?![;=A-Za-z0-9])/', $value ) ) {
			throw self::violation( 'reference', $what . ' contains a character reference without its semicolon (like &amp or &nbsp), which is not allowed in markup from Histeon CMS: add the semicolon or write the character itself.' );
		}
	}

	/** An attribute value as the browser reads it, after G3 (`reference` otherwise). */
	public static function decode_attribute( string $value, string $what = 'The value' ): string {
		self::assert_references( $value, $what );
		self::assert_named_references( $value, $what );
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	// ---------------------------------------------------------------- G6, G7, G9

	/** G6 on a decoded value: a scheme, if any, is http, https, mailto or tel. Relative and `//host` URLs pass. */
	public static function url_is_safe( string $decoded ): bool {
		$stripped = preg_replace( self::pattern( 'urlIgnored' ), '', $decoded );
		if ( null === $stripped ) {
			return false;
		}
		$value = strtolower( $stripped );
		$brace = strpos( $value, '{' );
		if ( false !== $brace ) {
			$value = substr( $value, 0, $brace );
		}
		$colon = strpos( $value, ':' );
		if ( false === $colon ) {
			return true;
		}
		$scheme = substr( $value, 0, $colon );
		if ( false !== strpbrk( $scheme, '/?#' ) ) {
			return true;
		}
		return in_array( $scheme, self::URL_SCHEMES, true );
	}

	/**
	 * The CSS escapes of a declaration resolved as the CSS tokenizer would (`\75 rl(` is `url(`), or null, after the CSS
	 * input preprocessing: CR LF, CR and FF become LF and NUL becomes U+FFFD, so `\75` + CR LF + `rl(` is `url(` too.
	 */
	public static function decode_css_escapes( string $value ): ?string {
		$value = preg_replace( '/\r\n?|\f/', "\n", $value );
		if ( null === $value ) {
			return null;
		}
		$value = str_replace( "\0", "\u{FFFD}", $value );
		$hex   = preg_replace_callback(
			self::pattern( 'cssHexEscape' ),
			static function ( array $match ): string {
				$code = (int) hexdec( $match[1] );
				return 0 === $code || $code > 0x10FFFF || ( $code >= 0xD800 && $code <= 0xDFFF ) ? "\u{FFFD}" : (string) mb_chr( $code, 'UTF-8' );
			},
			$value
		);
		return null === $hex ? null : preg_replace( self::pattern( 'cssCharEscape' ), '$1', $hex );
	}

	/**
	 * G7 on a decoded `style` value: true when it would load a resource or run an active behaviour. Any `/*` counts:
	 * inside a CSS string it starts no comment, so removing "comments" could hide a live url().
	 */
	public static function style_loads_resources( string $decoded ): bool {
		if ( str_contains( $decoded, '/*' ) ) {
			return true;
		}
		$css = self::decode_css_escapes( $decoded );
		return null === $css || self::contains( 'styleResource', strtolower( $css ) );
	}

	/**
	 * G9 on a decoded form endpoint: a same-site path or an absolute HTTPS URL with a host, without whitespace or control
	 * characters, backslashes, braces, credentials or fragments (the shared \XB\Security\MarkupPolicy rule).
	 */
	public static function form_action_is_safe( string $decoded ): bool {
		// The host of an absolute endpoint holds no `%` or `<>^|`: Publish's URL parser refuses them
		// (`https://evil.example%40good/` would decode to a credential), parse_url() does not.
		// The scheme in ASCII letters alone: the `i` flag of PCRE follows the PHP locale (T31, P1).
		if ( 1 === preg_match( '~^[A-Za-z][A-Za-z0-9+.-]*://([^/?#]*)~', $decoded, $authority ) && 1 === preg_match( '/[%<>^|]/', $authority[1] ) ) {
			return false;
		}
		return ! self::contains( 'formActionForbidden', $decoded ) && \XB\Security\MarkupPolicy::safe_decoded_form_action( $decoded );
	}

	// ---------------------------------------------------------------- srcset

	/**
	 * The candidates of a `srcset` that matches the shared grammar (at most 16, URLs without commas), or null.
	 *
	 * @return list<array{url: string, descriptor: string|null}>|null
	 */
	public static function parse_srcset( string $value ): ?array {
		if ( ! self::matches( 'srcset', $value ) ) {
			return null;
		}
		$candidates = array();
		foreach ( explode( ',', $value ) as $part ) {
			$words        = preg_split( '/[ \t\n\r\f]+/', trim( $part, " \t\n\r\f" ) ) ?: array( '' );
			$candidates[] = array(
				'url'        => $words[0],
				'descriptor' => $words[1] ?? null,
			);
		}
		return $candidates;
	}

	/** The index of the brace closing the interpolation opened at `$open` (string literals skipped), or -1. */
	private static function closing_brace( string $source, int $open ): int {
		$depth  = 0;
		$length = strlen( $source );
		for ( $index = $open; $index < $length; $index++ ) {
			$char = $source[ $index ];
			if ( '"' === $char || "'" === $char ) {
				++$index;
				while ( $index < $length && $source[ $index ] !== $char ) {
					if ( '\\' === $source[ $index ] ) {
						++$index;
					}
					++$index;
				}
				if ( $index >= $length ) {
					return -1;
				}
				continue;
			}
			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char && 0 === --$depth ) {
				return $index;
			}
		}
		return -1;
	}

	/** True when the whole value is one interpolation (`{props.image}`): a dynamic media source, resolved at render. */
	/**
	 * The only dynamic media source a transfer carries: a component prop as a whole (`{props.immagine}`). Any other
	 * expression (`{page.title}`, nested fields, operators) could print an arbitrary URL.
	 */
	public static function is_transfer_media_prop( string $value ): bool {
		return 1 === preg_match( '/^\{props\.[A-Za-z_][A-Za-z0-9_]{0,63}\}$/D', $value );
	}

	public static function is_whole_interpolation( string $value ): bool {
		return str_starts_with( $value, '{' ) && strlen( $value ) - 1 === self::closing_brace( $value, 0 );
	}

	// ---------------------------------------------------------------- elements

	/** The prefix of SVG_ATTRIBUTE_PREFIXES an SVG attribute name starts with (and goes beyond), or null. */
	private static function svg_attribute_prefix( string $name ): ?string {
		foreach ( self::SVG_ATTRIBUTE_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) && strlen( $name ) > strlen( $prefix ) ) {
				return $prefix;
			}
		}
		return null;
	}

	/**
	 * The grammar of an SVG attribute (`tag.attribute` first, then `attribute`, then `prefix*` for an `aria-*` or
	 * `data-*` name), or null: unknown names fail closed.
	 */
	private static function svg_rule( string $tag, string $name ): ?string {
		$prefix = self::svg_attribute_prefix( $name );
		return self::SVG_VALUE_RULES[ $tag . '.' . $name ] ?? self::SVG_VALUE_RULES[ $name ] ?? ( null === $prefix ? null : ( self::SVG_VALUE_RULES[ $prefix . '*' ] ?? null ) );
	}

	/** Whether an HTML element's allowlist (its own attributes and the global ones) holds the name. */
	private static function html_attribute_listed( string $tag, string $name ): bool {
		if ( in_array( $name, self::ELEMENT_ATTRIBUTES[ $tag ] ?? array(), true ) ) {
			return true;
		}
		if ( in_array( $tag, self::ELEMENTS_WITHOUT_GLOBAL_ATTRIBUTES, true ) ) {
			return false;
		}
		if ( in_array( $name, self::GLOBAL_ATTRIBUTES, true ) ) {
			return true;
		}
		foreach ( self::GLOBAL_ATTRIBUTE_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) && strlen( $name ) > strlen( $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return HtmlValueRule|null */
	private static function html_rule( string $tag, string $name ): ?array {
		return self::HTML_VALUE_RULES[ $tag . '.' . $name ] ?? ( in_array( $tag, self::NEW_HTML_TAGS, true ) ? ( self::HTML_VALUE_RULES[ '*.' . $name ] ?? null ) : null );
	}

	/** @param TransferContext $ctx */
	private static function check_element_context( string $tag, array $ctx ): void {
		$svg_tag = in_array( $tag, self::SVG_TAGS, true );
		$parent  = $ctx['parent'];
		if ( $svg_tag && 'svg' !== $tag && ! $ctx['svg'] ) {
			throw self::violation( 'context', '<' . $tag . '> is allowed only inside <svg>.' );
		}
		if ( 'svg' === $tag && $ctx['svg'] ) {
			throw self::violation( 'context', 'An <svg> inside another <svg> is not transferable.' );
		}
		if ( $ctx['svg'] && ! $svg_tag ) {
			throw self::violation( 'context', 'Only the static SVG elements are allowed inside <svg>, not <' . $tag . '>.' );
		}
		if ( ( 'title' === $tag || 'desc' === $tag ) && ! ( null !== $parent && in_array( $parent, self::SVG_TAGS, true ) && 'title' !== $parent && 'desc' !== $parent ) ) {
			throw self::violation( 'context', '<' . $tag . '> is allowed only as the child of an SVG element.' );
		}
		// histos-svg-text-v1 (C7): a line of text holds text, `tspan`, `title` and `desc`; a gradient holds its stops.
		if ( 'tspan' === $tag && 'text' !== $parent && 'tspan' !== $parent ) {
			throw self::violation( 'context', '<tspan> is allowed only inside <text> or another <tspan>.' );
		}
		if ( ( 'text' === $parent || 'tspan' === $parent ) && 'tspan' !== $tag && 'title' !== $tag && 'desc' !== $tag ) {
			throw self::violation( 'context', 'Only text and <tspan> go inside <' . $parent . '>, not <' . $tag . '>.' );
		}
		if ( 'stop' === $tag && 'linearGradient' !== $parent && 'radialGradient' !== $parent ) {
			throw self::violation( 'context', '<stop> is allowed only inside <linearGradient> or <radialGradient>.' );
		}
		if ( ( 'linearGradient' === $parent || 'radialGradient' === $parent ) && 'stop' !== $tag ) {
			throw self::violation( 'context', 'Only <stop> goes inside <' . $parent . '>, not <' . $tag . '>.' );
		}
		if ( 'stop' === $parent ) {
			throw self::violation( 'context', '<stop> has no content, not <' . $tag . '>.' );
		}
		if ( 'source' === $tag && 'picture' !== $parent ) {
			throw self::violation( 'context', '<source> is allowed only inside <picture>.' );
		}
		if ( 'picture' === $parent && 'source' !== $tag && 'img' !== $tag ) {
			throw self::violation( 'context', 'Only <source> and one <img> go inside <picture>, not <' . $tag . '>.' );
		}
		if ( 'summary' === $tag && 'details' !== $parent ) {
			throw self::violation( 'context', '<summary> is allowed only inside <details>.' );
		}
		if ( 'option' === $tag && 'select' !== $parent && 'optgroup' !== $parent ) {
			throw self::violation( 'context', '<option> is allowed only inside <select> or <optgroup>.' );
		}
		if ( 'optgroup' === $tag && 'select' !== $parent ) {
			throw self::violation( 'context', '<optgroup> is allowed only inside <select>.' );
		}
		if ( ( 'select' === $parent || 'optgroup' === $parent ) && 'option' !== $tag && 'optgroup' !== $tag ) {
			throw self::violation( 'context', 'Only <option> and <optgroup> go inside <' . $parent . '>, not <' . $tag . '>.' );
		}
		if ( 'option' === $parent || 'textarea' === $parent ) {
			throw self::violation( 'context', '<' . $parent . '> holds only text, not <' . $tag . '>.' );
		}
		if ( 'form' === $tag && $ctx['form'] ) {
			throw self::violation( 'context', 'A form inside another form is not transferable.' );
		}
		if ( 'button' === $tag && $ctx['button'] ) {
			throw self::violation( 'context', 'A button inside another button is not transferable.' );
		}
	}

	private static function check_attribute_name( string $tag, string $name, bool $svg ): void {
		if ( ! self::matches( 'attributeName', $name ) && ! ( $svg && in_array( $name, self::SVG_CAMEL_CASE_ATTRIBUTES, true ) ) ) {
			throw self::violation( 'attribute', 'The attribute name "' . mb_substr( $name, 0, 60 ) . '" on <' . $tag . '> is not allowed in markup from Histeon CMS: only lower-case letters, digits, - and _.' );
		}
		if ( in_array( $name, self::BLOCKED_ATTRIBUTES, true ) || ( in_array( $name, self::FORM_ONLY_ATTRIBUTES, true ) && 'form' !== $tag ) ) {
			throw self::violation( 'attribute', 'The attribute ' . $name . ' on <' . $tag . '> is not allowed in markup from Histeon CMS.' );
		}
		foreach ( self::BLOCKED_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				throw self::violation( 'attribute', 'The attribute ' . $name . ' is not allowed in markup from Histeon CMS: event handlers and framework attributes are not transferable.' );
			}
		}
	}

	private static function check_svg_attribute( string $tag, string $name, string|true $value ): void {
		self::check_attribute_name( $tag, $name, true );
		$allowed = self::SVG_ATTRIBUTES[ $tag ] ?? array();
		$common  = in_array( $name, self::SVG_COMMON_ATTRIBUTES, true ) || in_array( $name, self::SVG_TEXT_ATTRIBUTES, true ) || null !== self::svg_attribute_prefix( $name );
		if ( ! in_array( $name, $allowed, true ) && ! ( in_array( $tag, self::SVG_TAGS_WITH_COMMON_ATTRIBUTES, true ) && $common ) ) {
			throw self::violation( 'attribute', 'The attribute ' . $name . ' on <' . $tag . '> is not allowed in SVG markup from Histeon CMS.' );
		}
		if ( true === $value || false !== strpbrk( $value, '{}' ) ) {
			throw self::violation( 'dynamic', 'The attribute ' . $name . ' of <' . $tag . '> must have a static value.' );
		}
		if ( false !== strpbrk( $value, '&\\' ) ) {
			throw self::violation( 'value', 'The value of ' . $name . ' on <' . $tag . '> cannot contain & or \\.' );
		}
		$rule = self::svg_rule( $tag, $name );
		if ( null === $rule || ! self::matches( $rule, $value ) ) {
			throw self::violation( 'value', 'The value of ' . $name . ' on <' . $tag . '> is not allowed in SVG markup from Histeon CMS.' );
		}
		// G7, as on an HTML element; the value has no `&` and no `\`, so nothing is left to decode.
		if ( 'style' === $name && self::style_loads_resources( $value ) ) {
			throw self::violation( 'style', 'The inline style of <' . $tag . '> loads a resource or an active behaviour (url(), image-set(), @import…).' );
		}
	}

	/** @param HtmlValueRule $rule */
	private static function check_html_value_rule( string $tag, string $name, string|true $value, array $rule ): void {
		if ( true === $value || false !== strpbrk( $value, '{}' ) ) {
			throw self::violation( 'dynamic', 'The attribute ' . $name . ' of <' . $tag . '> must have a static value.' );
		}
		$decoded = self::decode_attribute( $value, 'The attribute ' . $name );
		$valid   = ( ! isset( $rule['enum'] ) || in_array( strtolower( $decoded ), self::ENUMS[ $rule['enum'] ] ?? array(), true ) )
			&& ( ! isset( $rule['grammar'] ) || self::matches( $rule['grammar'], $decoded ) )
			&& ( ! isset( $rule['maxCodePoints'] ) || mb_strlen( $decoded, 'UTF-8' ) <= $rule['maxCodePoints'] );
		$candidates = $valid && isset( $rule['srcset'] ) ? self::parse_srcset( $decoded ) : array();
		if ( ! $valid || null === $candidates ) {
			throw self::violation( 'value', 'The value of ' . $name . ' on <' . $tag . '> is not allowed in markup from Histeon CMS.' );
		}
		if ( isset( $rule['srcset'] ) ) {
			foreach ( $candidates as $candidate ) {
				if ( null === self::media_id( $candidate['url'] ) && ! self::url_is_safe( $candidate['url'] ) ) {
					throw self::violation( 'url', 'A srcset candidate on <' . $tag . '> uses a URL scheme that is not allowed in markup from Histeon CMS.' );
				}
			}
		}
	}

	private static function check_html_attribute( string $tag, string $name, string|true $value ): void {
		self::check_attribute_name( $tag, $name, false );
		if ( ! self::html_attribute_listed( $tag, $name ) ) {
			throw self::violation( 'attribute', 'The attribute ' . $name . ' on <' . $tag . '> is not allowed in markup from Histeon CMS.' );
		}
		if ( is_string( $value ) ) {
			self::assert_references( $value, 'The attribute ' . $name . ' of <' . $tag . '>' );
			self::assert_named_references( $value, 'The attribute ' . $name . ' of <' . $tag . '>' );
		}
		$rule = self::html_rule( $tag, $name );
		if ( null !== $rule ) {
			self::check_html_value_rule( $tag, $name, $value, $rule );
		}
		if ( ! is_string( $value ) ) {
			if ( 'form' === $tag && ( 'action' === $name || 'method' === $name ) ) {
				throw self::violation( 'form', 'The form ' . $name . ' must be a static value.' );
			}
			return;
		}
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// A link of an `a` may also be a media identity of the project (doc 23 item 121), checked as a media later.
		if ( in_array( $name, self::URL_ATTRIBUTES, true ) && ! self::url_is_safe( $decoded ) && ! ( 'a' === $tag && 'href' === $name && null !== self::media_id( $value ) ) ) {
			throw self::violation( 'url', 'The attribute ' . $name . ' of <' . $tag . '> uses a URL scheme that is not allowed in markup from Histeon CMS: only http, https, mailto and tel.' );
		}
		if ( 'style' === $name && false !== strpbrk( $value, '{}' ) ) {
			throw self::violation( 'dynamic', 'The inline style of <' . $tag . '> must be static: an expression could bring url() at render time.' );
		}
		if ( 'style' === $name && self::style_loads_resources( $decoded ) ) {
			throw self::violation( 'style', 'The inline style of <' . $tag . '> loads a resource or an active behaviour (url(), image-set(), @import…).' );
		}
		if ( 'form' === $tag && 'action' === $name && ( false !== strpbrk( $value, '{}' ) || ! self::form_action_is_safe( $decoded ) ) ) {
			throw self::violation( 'form', 'The form endpoint must be static: a path of the site or an HTTPS URL without whitespace, credentials or fragment.' );
		}
		if ( 'form' === $tag && 'method' === $name && ( false !== strpbrk( $value, '{}' ) || ! in_array( strtolower( $value ), array( 'get', 'post' ), true ) ) ) {
			throw self::violation( 'form', 'The form method must be GET or POST and cannot be dynamic.' );
		}
	}

	/**
	 * Checks one element of the transferable markup in its context (the media identities are checked by the caller).
	 *
	 * @param array<array-key, mixed> $attributes As stored in the block; values are strings or `true`.
	 * @param TransferContext         $ctx
	 */
	public static function check_element( string $tag, array $attributes, array $ctx ): void {
		$svg = in_array( $tag, self::SVG_TAGS, true );
		if ( ! $svg && ! in_array( $tag, self::HTML_TAGS, true ) ) {
			throw self::violation( 'tag', 'The element <' . mb_substr( $tag, 0, 40 ) . '> is not allowed in markup from Histeon CMS' . ( strtolower( $tag ) !== $tag ? ' (tags are written in lower case)' : '' ) . '.' );
		}
		self::check_element_context( $tag, $ctx );
		foreach ( $attributes as $name => $value ) {
			$name = (string) $name;
			if ( true !== $value && ! is_string( $value ) ) {
				throw self::violation( 'value', 'The attribute ' . mb_substr( $name, 0, 60 ) . ' of <' . $tag . '> has a value that is neither text nor true.' );
			}
			if ( $svg ) {
				self::check_svg_attribute( $tag, $name, $value );
			} else {
				self::check_html_attribute( $tag, $name, $value );
			}
		}
		if ( 'form' === $tag && ( ! array_key_exists( 'action', $attributes ) || ! array_key_exists( 'method', $attributes ) ) ) {
			throw self::violation( 'form', 'A transferable form requires a static same-site relative or HTTPS action and an explicit GET or POST method.' );
		}
		if ( 'source' === $tag && ! array_key_exists( 'srcset', $attributes ) ) {
			throw self::violation( 'attribute', '<source> requires srcset.' );
		}
	}

	// ---------------------------------------------------------------- children, blocks, text

	/**
	 * One token per child block, for check_children(): `element:<tag>`, `text`, `space`, `verbatim`, `control`,
	 * `component`, `slot`, or `block:<name>`.
	 *
	 * @param array<array-key, mixed> $block A parsed block (parse_blocks()).
	 */
	public static function child_kind( array $block ): string {
		$name  = $block['blockName'] ?? null;
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		switch ( $name ) {
			case 'xb/element':
				return 'element:' . ( is_string( $attrs['tag'] ?? null ) ? $attrs['tag'] : '' );
			case 'xb/text':
				if ( true === ( $attrs['verbatim'] ?? false ) ) {
					return 'verbatim';
				}
				return is_string( $attrs['content'] ?? null ) && self::is_whitespace( $attrs['content'] ) ? 'space' : 'text';
			case 'xb/if':
			case 'xb/loop':
				return 'control';
			case 'xb/component':
				return 'component';
			case 'xb/slot':
				return 'slot';
			default:
				return 'block:' . ( is_string( $name ) ? $name : '' );
		}
	}

	/**
	 * The content model of an element's children.
	 *
	 * @param list<string> $kinds Tokens from child_kind().
	 */
	public static function check_children( string $tag, array $kinds ): void {
		if ( in_array( $tag, self::VOID_TAGS, true ) && array() !== $kinds ) {
			throw self::violation( 'context', 'The void element <' . $tag . '> cannot have children.' );
		}
		$significant = array_values( array_filter( $kinds, static fn ( string $kind ): bool => 'space' !== $kind ) );
		if ( 'picture' === $tag ) {
			$index = 0;
			while ( 'element:source' === ( $significant[ $index ] ?? null ) ) {
				++$index;
			}
			if ( 'element:img' !== ( $significant[ $index ] ?? null ) || $index + 1 !== count( $significant ) ) {
				throw self::violation( 'context', '<picture> holds zero or more <source> and then exactly one <img>, without text, conditions or components.' );
			}
		}
		if ( 'details' === $tag ) {
			$summaries = count( array_filter( $significant, static fn ( string $kind ): bool => 'element:summary' === $kind ) );
			if ( $summaries > 1 || ( 1 === $summaries && 'element:summary' !== $significant[0] ) ) {
				throw self::violation( 'context', '<details> has at most one <summary>, as its first child.' );
			}
		}
		if ( 'textarea' === $tag && ! ( array() === $kinds || array( 'verbatim' ) === $kinds ) ) {
			throw self::violation( 'text', '<textarea> holds only its initial text, written verbatim.' );
		}
		if ( 'option' === $tag ) {
			foreach ( $kinds as $kind ) {
				if ( 'text' !== $kind && 'space' !== $kind ) {
					throw self::violation( 'context', '<option> holds only text.' );
				}
			}
		}
		if ( ( 'select' === $tag || 'optgroup' === $tag ) && in_array( 'text', $kinds, true ) ) {
			throw self::violation( 'context', '<' . $tag . '> holds no free text, only <option>.' );
		}
	}

	/**
	 * Components, slots, slot fills and WordPress blocks kept whole (`xb/passthrough`, audit note 88: another parser
	 * would read their HTML there) are refused inside svg and inside elements whose content is fixed.
	 *
	 * @param TransferContext $ctx
	 */
	public static function check_block( string $name, array $ctx ): void {
		if ( 'xb/component' !== $name && 'xb/slot' !== $name && 'xb/slot-fill' !== $name && 'xb/passthrough' !== $name ) {
			return;
		}
		if ( $ctx['svg'] || ( null !== $ctx['parent'] && in_array( $ctx['parent'], self::CONTENT_BLOCKED_PARENTS, true ) ) ) {
			$where = $ctx['svg'] ? 'svg' : (string) $ctx['parent'];
			throw self::violation( 'context', 'xb/passthrough' === $name ? 'A WordPress block is not allowed inside <' . $where . '>.' : 'Components and slots are not allowed inside <' . $where . '>.' );
		}
	}

	/**
	 * Where an `xb/branch` may stand, on the blocks as written (parse_blocks(), before any repair), as Publish's
	 * checkTransferBranches: in `xb/if`, or as the `{:else}` of an `xb/loop` (D1), which is at most one branch, the
	 * loop's last named block, without a `test` attribute (any `test` key, even one that is not a string). Anything else
	 * is a `context` violation. Blocks without a name (HTML between blocks) do not count as children. That the
	 * `{:else}` does not see the loop variable is a scope rule, checked by ProfileValidator.
	 *
	 * @param array<array<string, mixed>> $blocks
	 */
	public static function check_branches( array $blocks, ?string $parent = null ): void {
		foreach ( $blocks as $block ) {
			$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : null;
			if ( 'xb/branch' === $name && 'xb/if' !== $parent && 'xb/loop' !== $parent ) {
				throw self::violation( 'context', 'A conditional branch stands only in {#if} or, as {:else}, at the end of a {#loop}.' );
			}
			$children = is_array( $block['innerBlocks'] ?? null ) ? array_values( $block['innerBlocks'] ) : array();
			if ( 'xb/loop' === $name ) {
				$named    = array_values( array_filter( $children, static fn ( mixed $child ): bool => is_array( $child ) && is_string( $child['blockName'] ?? null ) ) );
				$branches = array_keys( array_filter( $named, static fn ( array $child ): bool => 'xb/branch' === $child['blockName'] ) );
				if ( count( $branches ) > 1 ) {
					throw self::violation( 'context', 'A {#loop} has at most one {:else}.' );
				}
				if ( array() !== $branches ) {
					if ( count( $named ) - 1 !== $branches[0] ) {
						throw self::violation( 'context', 'The {:else} of a {#loop} stands at the end of the loop, after the content of the items.' );
					}
					if ( is_array( $named[ $branches[0] ]['attrs'] ?? null ) && array_key_exists( 'test', $named[ $branches[0] ]['attrs'] ) ) {
						throw self::violation( 'context', 'The branch of a {#loop} is only {:else}, without a condition.' );
					}
				}
			}
			/** @var array<array<string, mixed>> $children */
			self::check_branches( $children, $name );
		}
	}

	/**
	 * The inline markup of a text run: only INLINE_TAGS with their quoted, static attributes, balanced, and no comments
	 * or processing instructions. Interpolations are skipped (their scopes are checked by ProfileValidator).
	 */
	public static function check_inline_markup( string $content ): void {
		self::assert_references( $content, 'The text' );
		$stack  = array();
		$length = strlen( $content );
		for ( $index = 0; $index < $length; $index++ ) {
			$char = $content[ $index ];
			$next = $content[ $index + 1 ] ?? '';
			if ( '\\' === $char && ( '{' === $next || '}' === $next ) ) {
				++$index;
				continue;
			}
			if ( '{' === $char ) {
				$close = self::closing_brace( $content, $index );
				if ( $close < 0 ) {
					throw self::violation( 'text', 'The text contains an unclosed expression.' );
				}
				$index = $close;
				continue;
			}
			if ( '<' !== $char ) {
				continue;
			}
			if ( '!' === $next || '?' === $next ) {
				throw self::violation( 'text', 'HTML comments and <? ?> instructions are not allowed in text from Histeon CMS.' );
			}
			if ( '' === $next || false === strpos( 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz/', $next ) ) {
				continue;
			}
			$end = strpos( $content, '>', $index + 1 );
			if ( false === $end ) {
				throw self::violation( 'text', 'The text contains unclosed inline markup.' );
			}
			$token   = substr( $content, $index, $end - $index + 1 );
			$matched = 1 === preg_match( self::pattern( 'inlineTag' ), $token, $match );
			$tag     = $matched ? $match[2] : '';
			$allowed = self::INLINE_TAGS[ $tag ] ?? null;
			if ( ! $matched || null === $allowed ) {
				throw self::violation( 'text', 'Inline markup outside the inline formatting list (' . mb_substr( $token, 0, 40 ) . ') is not allowed in text from Histeon CMS.' );
			}
			$tail        = $match[3];
			$self_closed = in_array( $tag, self::VOID_TAGS, true );
			if ( '/' === $match[1] ) {
				if ( ! self::is_whitespace( $tail ) ) {
					throw self::violation( 'text', 'The closing tag </' . $tag . '> contains attributes.' );
				}
				if ( $self_closed || array_pop( $stack ) !== $tag ) {
					throw self::violation( 'text', 'The inline markup <' . $tag . '> is not balanced.' );
				}
			} else {
				while ( ! self::is_whitespace( $tail ) ) {
					$tail = (string) preg_replace( '/^[ \t\n\r\f]+/', '', $tail );
					if ( 1 !== preg_match( self::pattern( 'inlineAttribute' ), $tail, $attribute, PREG_UNMATCHED_AS_NULL ) ) {
						throw self::violation( 'text', 'The inline tag <' . $tag . '> has an unquoted or invalid attribute.' );
					}
					$name  = (string) $attribute[1];
					$value = $attribute[2] ?? $attribute[3] ?? '';
					if ( ! in_array( $name, $allowed, true ) ) {
						throw self::violation( 'attribute', 'The inline attribute ' . $name . ' on <' . $tag . '> is not allowed in text from Histeon CMS.' );
					}
					if ( false !== strpbrk( $value, '{}' ) ) {
						throw self::violation( 'text', 'Attributes of inline markup cannot hold dynamic values (<' . $tag . '>).' );
					}
					if ( 'href' === $name && ! self::url_is_safe( self::decode_attribute( $value, 'The link' ) ) ) {
						throw self::violation( 'url', 'A link with a URL scheme other than http, https, mailto or tel is not allowed in text from Histeon CMS.' );
					}
					$tail = substr( $tail, strlen( (string) $attribute[0] ) );
				}
				if ( ! $self_closed ) {
					$stack[] = $tag;
				}
			}
			$index = $end;
		}
		if ( array() !== $stack ) {
			throw self::violation( 'text', 'The inline markup <' . (string) end( $stack ) . '> is not closed.' );
		}
	}

	/**
	 * A text block in its context. `verbatim` text (a textarea's initial value) is printed as is, never parsed.
	 *
	 * @param TransferContext $ctx
	 */
	public static function check_text( string $content, bool $verbatim, array $ctx ): void {
		$parent = $ctx['parent'];
		if ( $verbatim ) {
			if ( 'textarea' !== $parent ) {
				throw self::violation( 'text', 'Verbatim text is allowed only as the content of <textarea>.' );
			}
			if ( self::contains( 'verbatimForbidden', $content ) ) {
				throw self::violation( 'text', 'The initial text of <textarea> cannot contain { } < or &.' );
			}
			return;
		}
		$svg_text = null !== $parent && in_array( $parent, self::SVG_TEXT_PARENTS, true );
		if ( $ctx['svg'] && ! $svg_text ) {
			if ( ! self::is_whitespace( $content ) ) {
				throw self::violation( 'text', 'Inside <svg> text is allowed only in <title>, <desc>, <text> and <tspan>.' );
			}
			return;
		}
		if ( $svg_text || 'option' === $parent ) {
			if ( str_contains( $content, '<' ) ) {
				throw self::violation( 'text', '<' . $parent . '> holds only text, without markup.' );
			}
			self::assert_references( $content, 'The text' );
			return;
		}
		if ( 'select' === $parent || 'optgroup' === $parent || 'picture' === $parent ) {
			if ( ! self::is_whitespace( $content ) ) {
				throw self::violation( 'text', '<' . $parent . '> holds no free text.' );
			}
			return;
		}
		self::check_inline_markup( $content );
	}
}
