import { shortcodeToAttributes, isGistUrl } from '../../src/blocks/gist/transforms';

test( 'maps shortcode attrs', () => {
	expect( shortcodeToAttributes( { id: 'abc123', lines: '2-4', show_meta: 'false', lines_start: '10' } ) )
		.toEqual( { url: 'https://gist.github.com/abc123', lines: '2-4', showMeta: false, linesStart: 10 } );
} );

test( 'detects gist urls', () => {
	expect( isGistUrl( 'https://gist.github.com/gioxx/abc123#file-a-php' ) ).toBe( true );
	expect( isGistUrl( 'https://github.com/gioxx/abc123' ) ).toBe( false );
} );
