const { init, fileText } = require( '../../assets/js/copy.js' );

const file = ( name, lines ) => `
<div class="gist-file">
  <div class="gist-data" aria-label="${ name }">
    <table>${ lines
		.map( ( l, i ) => `<tr><td class="blob-num" data-line-number="${ i + 1 }"></td><td class="blob-code"><span class="pl-k">${ l }</span>\n</td></tr>` )
		.join( '' ) }</table>
  </div>
  <div class="gist-meta"><a href="#">view raw</a></div>
</div>`;

const mount = ( html ) => {
	document.body.innerHTML = html;
	return document.body;
};

beforeEach( () => {
	Object.defineProperty( window, 'isSecureContext', { value: true, configurable: true } );
	Object.defineProperty( navigator, 'clipboard', {
		value: { writeText: jest.fn( () => Promise.resolve() ) },
		configurable: true,
	} );
} );

test( 'file text is the visible code only, one line per row, no line numbers', () => {
	mount( file( 'a.sh', [ 'echo "a"', '  ls -la' ] ) );
	expect( fileText( document.querySelector( '.gist-file' ) ) ).toBe( 'echo "a"\n  ls -la\n' );
} );

test( 'one copy button per file and Copy all for multi-file gists', () => {
	mount( `<div class="gistful" data-copy="1"><div class="gist">${ file( 'a.sh', [ 'one' ] ) }${ file( 'b.sh', [ 'two' ] ) }</div></div>` );
	init( document );
	const buttons = [ ...document.querySelectorAll( 'button.gistful-copy' ) ];
	expect( buttons.map( ( b ) => b.getAttribute( 'aria-label' ) ) ).toEqual( [ 'Copy all files', 'Copy a.sh', 'Copy b.sh' ] );
	expect( document.querySelector( '[role="status"]' ) ).not.toBeNull();
} );

test( 'single-file gist has no Copy all, and init is idempotent', () => {
	mount( `<div class="gistful" data-copy="1">${ file( 'a.sh', [ 'one' ] ) }</div>` );
	init( document );
	init( document );
	expect( document.querySelectorAll( 'button.gistful-copy' ) ).toHaveLength( 1 );
} );

test( 'gists without the flag, and files without code, get no button', () => {
	mount( `<div class="gistful">${ file( 'a.sh', [ 'one' ] ) }</div><div class="gistful" data-copy="1"><div class="gist-file"><div class="markdown-body">text</div></div></div>` );
	init( document );
	expect( document.querySelectorAll( 'button.gistful-copy' ) ).toHaveLength( 0 );
} );

test( 'clicking copies the code and reports it', async () => {
	mount( `<div class="gistful" data-copy="1">${ file( 'a.sh', [ 'one' ] ) }${ file( 'b.sh', [ 'two' ] ) }</div>` );
	init( document );
	const [ all, first ] = document.querySelectorAll( 'button.gistful-copy' );
	first.click();
	await Promise.resolve();
	expect( navigator.clipboard.writeText ).toHaveBeenLastCalledWith( 'one\n' );
	expect( first.textContent ).toBe( 'Copied' );
	expect( document.querySelector( '[role="status"]' ).textContent ).toBe( 'Copied' );
	all.click();
	await Promise.resolve();
	expect( navigator.clipboard.writeText ).toHaveBeenLastCalledWith( 'one\n\ntwo\n' );
} );

test( 'a rendered CSV file copies as CSV, quoting where needed', () => {
	mount( `<div class="gistful" data-copy="1"><div class="gist-file"><div class="gist-data" aria-label="data.csv"><table class="csv-data">
		<thead><tr><td class="blob-num" data-line-number="1"></td><th>id</th><th>name</th></tr></thead>
		<tbody><tr><td class="blob-num" data-line-number="2"></td><td>1</td><td>Rossi, Mario</td></tr>
		<tr><td class="blob-num" data-line-number="3"></td><td>2</td><td>say "hi"</td></tr></tbody></table></div></div></div>` );
	init( document );
	expect( document.querySelectorAll( 'button.gistful-copy' ) ).toHaveLength( 1 );
	expect( fileText( document.querySelector( '.gist-file' ) ) ).toBe( 'id,name\n1,"Rossi, Mario"\n2,"say ""hi"""\n' );
} );

