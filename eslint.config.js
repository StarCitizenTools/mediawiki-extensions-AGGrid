'use strict';

const { defineConfig, globalIgnores } = require( 'eslint/config' );
const wikimedia = require( 'eslint-config-wikimedia/flat' );

const modernSyntaxRules = {
	'prefer-template': 'error',
	'object-shorthand': 'error',
	'prefer-spread': 'error',
	'prefer-rest-params': 'error',
	'no-useless-rename': 'error'
};

module.exports = defineConfig( [
	globalIgnores( [
		'docs/',
		'i18n/',
		'modules/lib/',
		'vendor/'
	] ),

	// modules/ runs in the browser and has its own config below
	{
		ignores: [ 'modules/**' ],
		extends: [ wikimedia( 'server' ) ],
		rules: modernSyntaxRules
	},
	{
		files: [ 'vitest.config.js' ],
		languageOptions: {
			sourceType: 'module'
		},
		rules: {
			'n/no-missing-require': 'off'
		}
	},
	{
		files: [ 'tests/vitest/**/*.js' ],
		languageOptions: {
			globals: {
				vi: 'readonly',
				describe: 'readonly',
				it: 'readonly',
				expect: 'readonly',
				beforeEach: 'readonly',
				afterEach: 'readonly',
				beforeAll: 'readonly',
				afterAll: 'readonly'
			}
		},
		rules: {
			'n/no-missing-require': 'off',
			'no-undef': 'off',
			strict: 'off',
			'no-underscore-dangle': 'off'
		}
	},

	// Code which runs in MediaWiki
	{
		files: [ 'modules/**' ],
		extends: [ wikimedia( 'client', 'mediawiki' ) ],
		languageOptions: {
			ecmaVersion: 2020,
			sourceType: 'module'
		},
		rules: {
			'es-x/no-nullish-coalescing-operators': 'off',
			'jsdoc/no-undefined-types': 'off',
			'max-len': 'off',
			...modernSyntaxRules
		}
	}
] );
