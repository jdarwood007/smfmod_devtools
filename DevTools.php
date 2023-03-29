<?php

/**
 * The class for DevTools Main class.
 * @package DevTools
 * @author SleePy <sleepy @ simplemachines (dot) org>
 * @copyright 2022
 * @license 3-Clause BSD https://opensource.org/licenses/BSD-3-Clause
 * @version 1.1
*/
class DevTools
{
	/*
	 * The javascript files hashed and this logic is cached for this length of time.
	*/
	private int $cacheTime = 900;

	/*
	 * This logic ensures if SMF hooks gets in a loop or happens to be called more than once, we prevent that.
	*/
	private array $calledOnce = [];

	/*
	 * SMF variables we will load into here for easy reference later.
	*/
	private string $scripturl;
	private array $context;
	private array $smcFunc;
	/* This is array in "theory" only.  SMF sometimes will null this when pulling from cache and causes an error */
	private ?array $modSettings;
	private array $txt;
	/* Sometimes in SMF, this is null, which is unusal for a boolean */
	private ?bool $db_show_debug;

	/*
	 * Builds the main DevTools object.  This also loads a few globals into easy to access properties, some by reference so we can update them
	*/
	public function __construct()
	{
		$this->scripturl = $GLOBALS['scripturl'];
		foreach (['context', 'smcFunc', 'txt', 'db_show_debug', 'modSettings'] as $f)
			$this->{$f} = &$GLOBALS[$f];

		$this->loadLanguage(['DevTools', 'Admin']);
		$this->loadSources(['DevToolsPackages', 'DevToolsHooks', 'DevToolsFile', 'Subs-Menu']);
	}

	/*
	 * Inject into the menu system current action.
	 * Nothing is returned, but we do inject some javascript and css.
	 *
	 * @CalledBy $sourcedir/Subs.php:setupMenuContext - integrate_current_action
	*/
	public function hook_current_action(): void
	{
		if (!empty($this->calledOnce[__FUNCTION__])) return;
		$this->calledOnce[__FUNCTION__] = true;

		// Don't bother with non admins.
		if (!$this->isAdmin())
			return;

		// Fixes a minor bug where the content isn't sized right.
		addInlineCss('
			div#devtools_menu .half_content { width: 49%;}
		');
	}


	/*
	 * Inject into the menu system valid action.
	 * Nothing is returned, but we do add to the actionArray.
	 *
	 * @CalledBy $boarddir/index.php:smf_main - integrate_actions
	*/
	public function hook_actions(array &$actionArray): void
	{
		if (!empty($this->calledOnce[__FUNCTION__])) return;
		$this->calledOnce[__FUNCTION__] = true;

		$actionArray['devtools'] = ['DevTools.php', [$this->context['instances'][__CLASS__], 'main_action']];
	}

	/*
	 * When we are on the logs sub action, we allow a ajax action to strip html.
	 *
	 * @CalledBy $sourcedir/Admin.php:AdminLogs - integrate_manage_logs
	*/
	public function hook_validateSession(&$types): void
	{
		if (!empty($this->calledOnce[__FUNCTION__])) return;
		$this->calledOnce[__FUNCTION__] = true;

		// Not a AJAX request.
		if (
			!isset($_REQUEST['ajax'], $_REQUEST['action'])
			|| $_REQUEST['action'] != 'devtools'
		)
			return;

		// Strip away layers and remove debugger.
		$this->setTemplateLayer('', true);
		$this->db_show_debug = false;
	}

	/*
	 * When we are on the logs sub action, we allow a ajax action to strip html.
	 *
	 * @CalledBy $sourcedir/Subs.php:redirectexit - integrate_redirect
	*/
	public function hook_redirect(&$setLocation, &$refresh, &$permanent): void
	{
		if (!empty($this->calledOnce[__FUNCTION__])) return;
		$this->calledOnce[__FUNCTION__] = true;

		// We are on a error log action such as delete.
		if (
			isset($_REQUEST['ajax'], $_REQUEST['action'])
			&& $_REQUEST['action'] == 'devtools'
		)
			$setLocation .= ';ajax';
	}

	/*
	 * When we load the theme we will add some extra javascript we need..
	 *
	 * @CalledBy $sourcedir/Load.php:loadTheme - integrate_load_theme
	 * @calls: $sourcedir/Load.php:cache_put_data
	 * @calls: $sourcedir/Load.php:loadJavaScriptFile
	 * @calls: $sourcedir/Load.php:addJavaScriptVar
	*/
	public function hook_load_theme(): void
	{
		if (!empty($this->calledOnce[__FUNCTION__])) return;
		$this->calledOnce[__FUNCTION__] = true;

		if (empty($this->modSettings['dt_debug']) && ($hash = cache_get_data('devtools-js-hash', $this->cacheTime)) === null)
		{
			$hash = base64_encode(hash_file('sha384', $GLOBALS['settings']['default_theme_dir'] . '/scripts/DevTools.js', true));
			cache_put_data('devtools-js-hash', $hash, $this->cacheTime);
		}

		// Load up our javascript files.
		loadJavaScriptFile(
			'DevTools.js',
			[
				'defer' => true,
				'minimize' => false,
				'seed' => microtime(),
				'attributes' => [
					'integrity' => !empty($hash) ? 'sha384-' . $hash : false,
				],
			],
			'devtools'
		);

		addJavaScriptVar('txt_devtools_menu', $this->txt('devtools_menu'), true);
	}
 
	/*
	 * This is called when we first enter the devtools action.  We check for admin access here.
	 * This will determine what we do next, prepare all output handles.
	 *
	 * @calls: $sourcedir/Subs.php:redirectexit
	 * @calls: $sourcedir/Security.php:validateSession
	*/
	public function main_action(): void
	{
		if (!$this->isAdmin())
			redirectexit();
		validateSession();

		// If this is from ajax, prepare the system to do the popup container.
		if ($this->isAjaxRequest())
			$this->preareAjaxRequest();

		// Valid actions we can take.
		$areas = [
			'index' => 'action_index',
			'packages' => 'action_packages',
			'hooks' => 'action_hooks',
			'files' => 'action_files',
		];

		$this->{$this->getAreaAction($areas, 'packages')}();
		$this->setupDevtoolLayers();
	}

	/*
	 * When the area=packages, this chooses the sub action we want to work with.
	*/
	private function action_packages(): void
	{
		$subActions = [
			'list' => 'packagesIndex',
			'reinstall' => 'HooksReinstall',
			'uninstall' => 'HooksUninstall',
			'syncin' => 'FilesSyncIn',
			'syncout' => 'FilesSyncOut'
		];

		if (!isset($this->context['instances']['DevToolsPackages']))
			$this->context['instances']['DevToolsPackages'] = new DevToolsPackages;

		$this->context['instances']['DevToolsPackages']->{$this->getSubAction($subActions, 'list')}();
		$this->setSubTemplate($this->getSubAction($subActions, 'list'));
	}

	/*
	 * When the area=hooks, this chooses the sub action we want to work with.
	*/
	private function action_hooks(): void
	{
		$subActions = [
			'list' => 'hooksIndex',
		];

		if (!isset($this->context['instances']['DevToolsHooks']))
			$this->context['instances']['DevToolsHooks'] = new DevToolsHooks;

		$this->context['instances']['DevToolsHooks']->{$this->getSubAction($subActions, 'list')}();
		$this->setSubTemplate($this->getSubAction($subActions, 'list'));
	}

	/*
	 * When the area=files, this chooses the sub action we want to work with.
	*/
	private function action_files(): void
	{
		$subActions = [
			'list' => 'filesIndex',
			'archive' => 'downloadArchive',
		];

		if (!isset($this->context['instances']['DevToolsFiles']))
			$this->context['instances']['DevToolsFiles'] = new DevToolsFiles;

		$this->context['instances']['DevToolsFiles']->{$this->getSubAction($subActions, 'list')}();
		$this->setSubTemplate($this->getSubAction($subActions, 'list'));
	}

	/*
	 * Loads a sub template.  If we specify the second parameter, we will also load the template file.
	 *
	 * @param string $subTemplate(default: index) The sub template we wish to use in SMF.
	 * @param string $template(optional) If specified, we will call the loadTemplate function.
	*/
	public function setSubTemplate(string $subTemplate = 'index', string $template = ''): void
	{
		if (!empty($template))
			$this->loadTemplate($template);

		$this->context['sub_template'] = $subTemplate;
	}

	/*
	 * Set the template layers, we can optionally clear all the layers out if needed.
	 *
	 * @param string $layerThe layer we wish to add.  If we are clearing, this can be any string.
	 * @param bool $clear(optional) If specified, this clears all layers.
	*/
	public function setTemplateLayer(string $layer, bool $clear = false): void
	{
		if ($clear)
			$this->context['template_layers'] = [];
		else
			$this->context['template_layers'][] = $layer;
	}
 
	/*
	 * Handles loading languages and calling our strings, as well as passing to sprintf if we are using args.
	 *
	 * @param string $keyThe language string key we will call.
	 * @param mixed ...$args If we specify any additional args after this, we will pass them into a sprintf process.
	*/
	public function txt(string $key, string ...$args): string
	{
		// If we have args passed, we want to pass this to sprintf.  We will keep args in a array and unpack it into sprintf.
		if (!empty($args))
			return isset($this->txt[$key]) ? sprintf($this->txt[$key], ...$args) : $key;
	
		return $this->txt[$key] ?? $key;
	}

	/*
	 * This passes data along to our txt handler, but returns it to SMF's handler for showing a success dialog box.
	 *
	 * @param mixed ...$args: All args are passed through to the txt function.
	*/
	public function showSuccessDialog(...$args): void
	{
		$this->context['saved_successful'] = $this->txt(...$args);
	}

	/*
	 * Determines if the current requests is a valid request from a javascript based request.
	 *
	 * @return bool True if this was a ajax based request, false otherwise.
	*/
	private function isAjaxRequest(): bool
	{
		return isset($_REQUEST['ajax']);
	}

	/*
	 * Determines if the current user has admin access.
	 *
	 * @return bool True if this user is an administrator, false otherwise.
	*/
	private function isAdmin(): bool
	{
		return !empty($this->context['user']['is_admin']);
	}

	/*
	 * Prepares a the output for a Ajax based response.
	*/
	private function preareAjaxRequest(): void
	{
		// Strip away layers and remove debugger.
		$this->setTemplateLayer('', true);
		$this->db_show_debug = false;
	}

	/*
	 * Gets the current area or the default.
	 * We do a null check on both the rqeuest input and the area.  It fixes a issue where the input is invalid and we force the default again.
	 *
	 * @param array $areasAll valid areas allowed.
	 * @param string $defaultAreaThe default area to take.
	 * @return bool True if this user is an administrator, false otherwise.
	*/
	private function getAreaAction(array $areas, string $defaultArea): string
	{
		return $areas[$_REQUEST['area'] ?? $defaultArea] ?? $areas[$defaultArea]; 
	}

	/*
	 * Gets the current sub action or the default.
	 * We do a null check on both the rqeuest input and the area.  It fixes a issue where the input is invalid and we force the default again.
	 *
	 * @param array $subActionsAll valid sub actions allowed.
	 * @param string $defaultSubActionThe default sub action to take.
	 * @return bool True if this user is an administrator, false otherwise.
	*/
	private function getSubAction(array $subActions, string $defaultSubAction): string
	{
		return $subActions[$_REQUEST['sa'] ?? $defaultSubAction] ?? $subActions[$defaultSubAction]; 
	}
	
	/*
	 * @calls the correct logic to setup the developer tools layers and add menu button injections.
	 *
	 * @param bool $removeWill remove the dev tools logic.
	*/
	private function setupDevtoolLayers(bool $remove = false): void
	{
		if ($remove)
			$this->context['template_layers'] = array_diff($context['template_layers'], ['devtools']);
		else
		{
			$this->loadTemplate(['DevTools', 'GenericMenu']);
			$this->loadMenuButtons();
			$this->setTemplateLayer('devtools');
		}
	}

	/*
	 * Loads up all valid buttons on our dev tools section.  This is passed into SMF's logic to build a button menu.
	 *
	 * @param string $activeWhich action is the 'default' action we will load.
	*/
	private function loadMenuButtons(string $active = 'packages'): void
	{
		$this->context['devtools_buttons'] = [
			'packages' => [
				'text' => 'installed_packages',
				'url' => $this->scripturl . '?action=devtools;area=packages',
			],
			'hooks' => [
				'text' => 'hooks_title_list',
				'url' => $this->scripturl . '?action=devtools;area=hooks',
			],
			'files' => [
				'text' => 'files_title_list',
				'url' => $this->scripturl . '?action=devtools;area=files',
			],
		];

		$this->context['devtools_buttons'][$active]['active'] ?? null;
	}

	/*
	 * Load additional language files.
	 * There are 3 way to pass multiple languages in.  A single string, SMF's tradditional + separated list or an array.
	 *
	 * @param $languages array|string The list of languages to load.
	*/
	public function loadLanguage(array|string $languages): string
	{
		return loadLanguage(implode('+', (array) $languages));
	}

	/*
	 * Load additional sources files.
	 *
	 * @param array $sourcesThe list of additional sources to load.
	*/
	public function loadSources(array $sources): void
	{
		array_map(function($rs) {
			require_once($GLOBALS['sourcedir'] . '/' . strtr($rs, ['DevTools' => 'DevTools/DevTools-']) . '.php');
		}, $sources);
	}

	/*
	 * Load additional template files.
	 * There are 2 way to pass multiple languages in.  A single string or an array.
	 *
	 * @calls: $sourcedir/Load.php:loadTemplate
	 * @param $languages array|string The list of languages to load.
	*/
	public function loadTemplate(array|string $templates): void
	{
		array_map(function($t) {
			loadTemplate($t);
		}, (array) $templates);
	}

	/*
	 * Loads up this class into a instance.  We use the same storage location SMF uses so SMF could also load this instance.
	*/
	public static function load(): self
	{
		if (!isset($GLOBALS['context']['instances'][__CLASS__]))
			$GLOBALS['context']['instances'][__CLASS__] = new self();

		return $GLOBALS['context']['instances'][__CLASS__];
	}
}