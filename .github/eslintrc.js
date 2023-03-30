module.exports = {
	'env': {
		'browser': true,
		'es2021': true,
		'jquery': true
	},
	'extends': 'eslint:recommended',
	'parserOptions': {
		'ecmaVersion': 12,
		'sourceType': 'module'
	},
	'rules': {
		'indent': [
			'error',
			'tab',
			{"SwitchCase": 1}
		],
		'linebreak-style': [
			'error',
			'unix'
		],
		'quotes': [
			'error',
			'single'
		],
		'no-unused-vars': [
			'error',
			{
				'vars': 'local',
				'args' : 'none'
			}
		]
	},
	'globals': {
		'smf_scripturl': 'readonly',
		'txt_devtools_menu': 'readonly',
		'smc_PopupMenu': 'readonly',
		'allow_xhjr_credentials': 'readonly'
	}
};
