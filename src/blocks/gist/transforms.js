import { createBlock } from '@wordpress/blocks';

const GIST_URL = /^https?:\/\/(www\.)?gist\.github\.com\/[^\s]+$/i;

export const isGistUrl = ( url ) => GIST_URL.test( ( url || '' ).trim() );

const toBool = ( value ) => ! [ 'false', '0', 'no', 'off' ].includes( String( value ).toLowerCase() );

export const shortcodeToAttributes = ( named = {} ) => {
	const attrs = {};
	const id = named.id || named.gist;
	if ( id ) {
		attrs.url = /^https?:/i.test( id ) ? id : `https://gist.github.com/${ id }`;
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
		url: { type: 'string', shortcode: ( { named } ) => shortcodeToAttributes( named ).url },
		file: { type: 'string', shortcode: ( { named } ) => shortcodeToAttributes( named ).file },
		lines: { type: 'string', shortcode: ( { named } ) => shortcodeToAttributes( named ).lines },
		linesStart: { type: 'number', shortcode: ( { named } ) => shortcodeToAttributes( named ).linesStart },
		highlight: { type: 'string', shortcode: ( { named } ) => shortcodeToAttributes( named ).highlight },
		highlightColor: { type: 'string', shortcode: ( { named } ) => shortcodeToAttributes( named ).highlightColor },
		showLineNumbers: { type: 'boolean', shortcode: ( { named } ) => shortcodeToAttributes( named ).showLineNumbers },
		showMeta: { type: 'boolean', shortcode: ( { named } ) => shortcodeToAttributes( named ).showMeta },
		scheme: { type: 'string', shortcode: ( { named } ) => shortcodeToAttributes( named ).scheme },
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
