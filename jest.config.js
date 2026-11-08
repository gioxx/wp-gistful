// Extends the wp-scripts unit config; @wordpress/blocks is stubbed (ESM-only transitive deps).
const base = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...base,
	moduleNameMapper: {
		...( base.moduleNameMapper || {} ),
		'^@wordpress/blocks$': '<rootDir>/tests/js/__mocks__/wordpress-blocks.js',
	},
};
