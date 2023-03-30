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
		'var2': 'smf_scripturl',
		'var2': 'txt_devtools_menu',
		'var2': 'smc_PopupMenu',
		'var2': 'allow_xhjr_credentials'
	}
};
