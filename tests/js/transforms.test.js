import { shortcodeToAttributes, isGistUrl, toBool, fromShortcode } from '../../src/blocks/gist/transforms';

test( 'maps shortcode attrs', () => {
	expect( shortcodeToAttributes( { id: 'abc123', lines: '2-4', show_meta: 'false', lines_start: '10' } ) )
		.toEqual( { url: 'https://gist.github.com/abc123', lines: '2-4', showMeta: false, linesStart: 10 } );
} );

test( 'detects gist urls', () => {
	expect( isGistUrl( 'https://gist.github.com/gioxx/abc123#file-a-php' ) ).toBe( true );
	expect( isGistUrl( 'https://github.com/gioxx/abc123' ) ).toBe( false );
} );

test( 'toBool matches PHP Attributes::to_bool', () => {
	[ 'false', 'FALSE', ' False ', '0', 'no', 'No', 'n', 'N', '', '  ', false, null, undefined ].forEach( ( v ) => {
		expect( [ v, toBool( v ) ] ).toEqual( [ v, false ] );
	} );
	[ 'true', '1', 'yes', 'y', 'off', 'anything', true, 1 ].forEach( ( v ) => {
		expect( [ v, toBool( v ) ] ).toEqual( [ v, true ] );
	} );
	expect( toBool( 0 ) ).toBe( false );
} );

test( 'maps enclosed gist url', () => {
	expect( shortcodeToAttributes( {}, ' https://gist.github.com/u/abc123#file-a-sh ' ) )
		.toEqual( { url: 'https://gist.github.com/u/abc123#file-a-sh' } );
	expect( shortcodeToAttributes( { id: 'def456' }, 'https://gist.github.com/u/abc123' ).url )
		.toBe( 'https://gist.github.com/def456' );
	expect( shortcodeToAttributes( {}, 'https://example.com/abc' ) ).toEqual( {} );
	expect( fromShortcode.attributes.url.shortcode( { named: {} }, { shortcode: { content: 'https://gist.github.com/u/abc123' } } ) )
		.toBe( 'https://gist.github.com/u/abc123' );
} );

test( 'maps the copy attribute', () => {
	expect( shortcodeToAttributes( { id: 'abc123', copy: 'no' } ).copy ).toBe( false );
	expect( shortcodeToAttributes( { id: 'abc123' } ).copy ).toBeUndefined();
} );
