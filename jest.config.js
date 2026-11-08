// Legacy Jest setup kept with @wordpress/scripts 37 (see its vitest-migration.md);
// @wordpress/blocks is stubbed (ESM-only transitive deps).
module.exports = {
	preset: '@wordpress/jest-preset-default',
	transform: {
		'\\.[jt]sx?$': [ 'babel-jest', { presets: [ '@wordpress/babel-preset-default' ] } ],
	},
	moduleNameMapper: {
		'^@wordpress/blocks$': '<rootDir>/tests/js/__mocks__/wordpress-blocks.js',
	},
};
