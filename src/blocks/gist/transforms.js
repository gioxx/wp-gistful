import { createBlock } from '@wordpress/blocks';

const GIST_URL = /^https?:\/\/(www\.)?gist\.github\.com\/[^\s]+$/i;

export const isGistUrl = ( url ) => GIST_URL.test( ( url || '' ).trim() );

// Same semantics as PHP Attributes::to_bool(): false, 0, no, n and empty
// (case-insensitive, trimmed) are false; non-scalars are false; anything else is true.
export const toBool = ( value ) => {
	if ( typeof value === 'boolean' ) {
		return value;
	}
	if ( typeof value !== 'string' && typeof value !== 'number' ) {
		return false;
	}
	return ! [ '', 'false', '0', 'no', 'n' ].includes( String( value ).trim().toLowerCase() );
};

export const shortcodeToAttributes = ( named = {}, content = '' ) => {
	const attrs = {};
	const id = named.id || named.gist;
	const enclosed = ( content || '' ).trim();
	if ( id ) {
		attrs.url = /^https?:/i.test( id ) ? id : `https://gist.github.com/${ id }`;
	} else if ( isGistUrl( enclosed ) ) {
		// [gist]https://gist.github.com/user/id[/gist]
		attrs.url = enclosed;
	}
	[ 'file', 'lines', 'highlight', 'scheme' ].forEach( ( key ) => {
		if ( named[ key ] ) {
			attrs[ key ] = named[ key ];
		}
	} );
	if ( named.highlight_color ) {
		attrs.highlightColor = named.highlight_color;
	}
	if ( named.lines_start !== undefined && ! isNaN( parseInt( named.lines_start, 10 ) ) ) {
		attrs.linesStart = parseInt( named.lines_start, 10 );
	}
	if ( named.show_line_numbers !== undefined ) {
		attrs.showLineNumbers = toBool( named.show_line_numbers );
	}
	if ( named.show_meta !== undefined ) {
		attrs.showMeta = toBool( named.show_meta );
	}
	if ( named.copy !== undefined ) {
		attrs.copy = toBool( named.copy );
	}
	return attrs;
};

export const fromEmbed = {
	type: 'block',
	blocks: [ 'core/embed' ],
	isMatch: ( attributes ) => isGistUrl( attributes?.url ),
	transform: ( attributes ) => createBlock( 'gistful/gist', { url: attributes.url } ),
};

export const fromShortcode = {
	type: 'shortcode',
	tag: 'gist',
	attributes: {
		url: { type: 'string', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).url },
		file: { type: 'string', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).file },
		lines: { type: 'string', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).lines },
		linesStart: { type: 'number', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).linesStart },
		highlight: { type: 'string', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).highlight },
		highlightColor: { type: 'string', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).highlightColor },
		showLineNumbers: { type: 'boolean', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).showLineNumbers },
		showMeta: { type: 'boolean', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).showMeta },
		copy: { type: 'boolean', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).copy },
		scheme: { type: 'string', shortcode: ( { named }, { shortcode } = {} ) => shortcodeToAttributes( named, shortcode?.content ).scheme },
	},
};

export const fromRaw = {
	type: 'raw',
	isMatch: ( node ) => node.nodeName === 'P' && isGistUrl( node.textContent ),
	transform: ( node ) => createBlock( 'gistful/gist', { url: node.textContent.trim() } ),
};

export default {
	from: [ fromEmbed, fromShortcode, fromRaw ],
};
