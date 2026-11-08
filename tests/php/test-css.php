<?php
/**
 * The frontend stylesheet must colour every syntax class GitHub emits.
 *
 * @package Gistful
 */

/**
 * Syntax token coverage of assets/css/gistful.css.
 */
class Test_Css extends Gistful_Test_Case {

	/**
	 * Every pl-* class styled by GitHub's own gist embed stylesheet.
	 */
	const GITHUB_CLASSES = array(
		'pl-c', 'pl-c1', 'pl-e', 'pl-en', 'pl-smi', 'pl-s1', 'pl-ent', 'pl-k', 'pl-s', 'pl-pds', 'pl-pse', 'pl-sr', 'pl-cce',
		'pl-sre', 'pl-sra', 'pl-v', 'pl-smw', 'pl-bu', 'pl-ii', 'pl-c2', 'pl-ml', 'pl-mh', 'pl-ms', 'pl-mi', 'pl-mb', 'pl-md',
		'pl-mi1', 'pl-mc', 'pl-mi2', 'pl-mdr', 'pl-ba', 'pl-sg', 'pl-corl',
	); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing

	/**
	 * Stylesheet source.
	 *
	 * @return string
	 */
	private function css(): string {
		return (string) file_get_contents( GISTFUL_DIR . 'assets/css/gistful.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function test_every_github_syntax_class_has_a_rule() {
		$css = $this->css();
		foreach ( self::GITHUB_CLASSES as $class ) {
			$this->assertMatchesRegularExpression( '/\.' . preg_quote( $class, '/' ) . '(?![\w-])/', $css, "No rule for .$class" );
		}
	}

	public function test_every_colour_variable_has_a_dark_value_in_both_dark_blocks() {
		$css = $this->css();
		preg_match( '/^\.gistful \{(.*?)\}/ms', $css, $light );
		preg_match_all( '/(--gistful-[\w-]+):/', $light[1], $vars );
		$this->assertNotEmpty( $vars[1] );

		preg_match( '/\.gistful\[data-scheme="dark"\] \{(.*?)\}/s', $css, $dark );
		preg_match( '/\.gistful\[data-scheme="auto"\] \{(.*?)\}/s', $css, $auto );
		foreach ( $vars[1] as $var ) {
			if ( '--gistful-line-highlight' === $var || 0 === strpos( $var, '--gistful-font' ) ) {
				$this->assertStringContainsString( $var . ':', $dark[1] );
				continue;
			}
			$this->assertStringContainsString( $var . ':', $dark[1], "$var missing for data-scheme=dark" );
			$this->assertStringContainsString( $var . ':', $auto[1], "$var missing for data-scheme=auto" );
		}
		$this->assertSame( trim( preg_replace( '/\s+/', ' ', $dark[1] ) ), trim( preg_replace( '/\s+/', ' ', $auto[1] ) ), 'Dark and auto-dark values differ.' );
	}
}
