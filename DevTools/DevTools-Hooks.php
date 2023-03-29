<?php

/**
 * The class for DevTools Hooks.
 * @package DevTools
 * @author SleePy <sleepy @ simplemachines (dot) org>
 * @copyright 2023
 * @license 3-Clause BSD https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0
*/
class DevToolsHooks
{
	/*
	 * Handler for our Developer tools main object.
	*/
	private DevTools $dt;

	/*
	 * SMF variables we will load into here for easy reference later.
	*/
	private string $scripturl;
	private string $packagesdir;
	private string $boarddir;
	private string $sourcedir;
	private array $context;
	private array $smcFunc;
	private array $modSettings;
	private array $settings;

	/*
	 * The data file we are looking for inside packages.
	*/
	private string $packageInfoName = 'package-info.xml';

	/*
	 * Valid search terms we can search for.
	*/
	private array $searchTerms = ['hook_name', 'included_file', 'real_function'];

	/*
	 * Our hooks can be sorted, this is how.
	*/
	private array $sortTypes = [
		'hook_name' => ['hook_name', SORT_ASC],
		'hook_name DESC' => ['hook_name', SORT_DESC],
		'real_function' => ['real_function', SORT_ASC],
		'real_function DESC' => ['real_function', SORT_DESC],
		'included_file' => ['included_file', SORT_ASC],
		'included_file DESC' => ['included_file', SORT_DESC],
		'status' => ['status', SORT_ASC],
		'status DESC' => ['status', SORT_DESC],
	];

	/*
	 * How many hooks to show per page.
	*/
	private int $hooksPerPage = 20;

	/*
	 * Builds the DevTools Hooks object.  This also loads a few globals into easy to access properties, some by reference so we can update them
	*/
	public function __construct()
	{
		foreach (['scripturl', 'packagesdir', 'settings', 'boarddir', 'sourcedir', 'packagesdir'] as $f)
			$this->{$f} = $GLOBALS[$f];
		foreach (['context', 'smcFunc', 'modSettings'] as $f)
			$this->{$f} = &$GLOBALS[$f];

		$this->dt = &$this->context['instances']['DevTools'];
		$this->dt->loadSources(['Subs-List', 'ManageMaintenance']);
		$this->dt->loadLanguage(['Admin', 'Packages']);
	}

	/*
	 * Loads the main hooks listing.
	 * This will also look for various actions we are taking on thooks such as toggle, add or modify.
	 *
	 * @calls: $sourcedir/Subs-List.php:createList
	 * @calls: $sourcedir/Security.php:validateToken
	*/
	public function hooksIndex(): void
	{
		// We are doing a action.
		if (isset($_POST['toggle']) || isset($_POST['add']) || isset($_POST['modify']) || isset($_POST['delete']))
			validateToken('devtools_hooks');

		// We are asking to save data.
		if (isset($_POST['toggle']))
			$this->toggleHook($_POST['toggle']);
		elseif (isset($_POST['add']))
			$this->addHook();
		elseif (isset($_POST['modify']))
			$this->modifyHook($_POST['modify']);
		elseif (isset($_POST['delete']))
			$this->deleteHook($_POST['delete']);

		// Build a list.
		$this->context['available_packages'] = 0;
		createList($this->context['hooks'] = $this->buildHooksList());
	}

	/*
	 * Builds a list to pass to SMF's creatList for all valid hooks.  This is mocked up similar to the built in SMF logic to list hooks.
	 *
	 * @calls: $sourcedir/Security.php:createToken
	*/
	private function buildHooksList(): array
	{
		createToken('devtools_hooks');
		$hookData = $this->getHookData($_POST['edit'] ?? '');

		return [
			'id' => 'hooks_list',
			'no_items_label' => $this->dt->txt('hooks_no_hooks'),
			'items_per_page' => $this->hooksPerPage,
			'base_href' => $this->scripturl . '?action=devtools;area=hooks',
			'default_sort_col' => 'hook_name',
			'get_items' => [
				'function' => [$this, 'listGetHooks'],
			],
			'get_count' => [
				'function' => [$this, 'listGetHooksCount'],
			],
			'form' => [
				'include_start' => true,
				'include_sort' => true,
				'token' => 'devtools_hooks',
				'href' => $this->scripturl . '?action=devtools;area=hooks',
				'name' => 'HooksList',
			],
			'columns' => [
				'hook_name' => [
					'header' => [
						'value' => $this->dt->txt('hooks_field_hook_name'),
					],
					'data' => [
						'db' => 'hook_name',
					],
					'sort' => [
						'default' => 'hook_name',
						'reverse' => 'hook_name DESC',
					],
				],
				'instance' => [
					'header' => [
						'value' => $this->dt->txt('devtools_instance'),
					],
					'data' => [
						'function' => function($data)
						{
							return is_null($data['instance']) ? '' : ('<span class="main_icons ' . (!empty($data['instance']) ? 'post_moderation_deny' : 'post_moderation_allow') . '" title="' . $this->dt->txt('hooks_field_function_method') . '"></span>');
						},
					],
				],
				'function_name' => [
					'header' => [
						'value' => $this->dt->txt('hooks_field_function_name'),
					],
					'data' => [
						'db' => 'real_function',
					],
					'sort' => [
						'default' => 'real_function',
						'reverse' => 'real_function DESC',
					],
				],
				'included_file' => [
					'header' => [
						'value' => $this->dt->txt('hooks_field_file_name'),
					],
					'data' => [
						'db' => 'included_file',
					],
					'sort' => [
						'default' => 'included_file',
						'reverse' => 'included_file DESC',
					],
				],
				'status' => [
					'header' => [
						'value' => $this->dt->txt('hooks_field_hook_exists'),
						'style' => 'width:3%;',
					],
					'data' => [
						'function' => function($data)
						{
							if (is_null($data['status']))
								return '';

							$change_status = array('before' => '', 'after' => '');

							if ($data['can_disable'])
							{
								$actionData = base64_encode($this->smcFunc['json_encode']([
									'do' => $data['enabled'] ? 'disable' : 'enable',
									'hook' => $data['hook_name'],
									'function' => $data['real_function']
								]));
								$change_status['before'] = '<button name="toggle" value="' . $data['key'] . '" data-confirm="' . $this->dt->txt('quickmod_confirm') . '" class="you_sure">';
								$change_status['after'] = '</button>';
							}

							return $change_status['before'] . '<span class="main_icons ' . $data['status'] . '" title="' . $this->dt->txt('hook_' . ($data['enabled'] ? 'active' : 'disabled')). '"></span>' . $change_status['after'];
						},
						'class' => 'centertext',
					],
					'sort' => [
						'default' => 'status',
						'reverse' => 'status DESC',
					],
				],
				'actions' => [
					'header' => [
						'value' => $this->dt->txt('package_install_action'),
					],
					'data' => [
						'function' => function($data) {
							if (is_null($data['instance']))
								return '<input type="submit" value="' . $this->dt->txt('search') . '" class="button" />';
							return '<button name="edit" value="' . $data['key'] . '" class="button">' . $this->dt->txt('edit') . '</button>';
						},
					],
				],
			],
			'additional_rows' => [
				[
					'position' => 'bottom_of_list',
					'value' => $this->template_hooks_modify($hookData),
				],
			],
		];
	}

	/*
	 * Get all valid hook data, sort it and return it with our filtered search data.
	 *
	 * @param int $start The start of the list, defaults to 0.
	 * @param int $per_page The amount of hooks to show per page.
	 * @param string $sort The current sorting method.
	 * @return array Filtered, sorted and paginated hook data.
	*/
	public function listGetHooks(int $start, int $per_page, string $sort): array
	{
		$hooks = $this->getRawHooks();

		// Sort the data.
		uasort($hooks, function($a, $b) use ($sort) {
			return (strcasecmp($a[$this->sortTypes[$sort][0]], $b[$this->sortTypes[$sort][0]]) ?? 0) * ($this->sortTypes[$sort][1] === SORT_DESC ? -1 : 1);
		});

		// Add in our "search" row, slice the data and return.
		return array_merge(
			$this->insertSearchRow(),
			array_slice($hooks, $start, $per_page, true)
		);
	}

	/*
	 * Gets a proper count of how many hooks we have, so we can paginate properly.
	 *
	 * @return int The number of hooks in the system.
	*/
	public function listGetHooksCount(): int 
	{
		return array_reduce(
			$this->getRawHooks(),
			function($accumulator, $functions)
			{
				return ++$accumulator;
			},
			0
		);
	}

	/*
	 * Get all the hook data.  We parse the strings from the settings table into the valid data.
	 * If the hidden setting, dt_showAllHooks is set, we will show dev tool hooks.
	 *
	 * @calls $sourcedir/ManageMaintenance.php:parse_integration_hook
	 * @param bool $rebuildHooks When true, we will ignore the cached data.
	 * @return array All valid hook data.
	*/
	private function getRawHooks(bool $rebuildHooks = false): array
	{
		static $hooks = [];

		if (!empty($hooks) && empty($rebuildHooks))
			return $hooks;
		elseif (!empty($rebuildHooks))
			$hooks = [];

		$temp = array_map(
			// Expand by the comma delimiter.
			function ($value) {
				return explode(',', $value);
			},
			// Filter out modSettings that are not hooks.
			array_filter(
				$this->modSettings,
				function ($value, $key) {
					return substr($key, 0, 10) === 'integrate_' && !empty($value) && (!empty($this->modSettings['dt_showAllHooks']) || strpos($value, 'DevTools') === false);
				},
				ARRAY_FILTER_USE_BOTH
			)
		);

		// Flatten, PHP doesn't have a better way to do this than to loop foreaches.
		foreach ($temp as $hookName => $rawFuncs)
			foreach ($rawFuncs as $func)
			{
				$hookParsedData = parse_integration_hook($hookName, $func);

				$hooks[] = [
					'key' => md5($func),
					'hook_name' => $hookName,
					'function_name' => $hookParsedData['rawData'],
					'real_function' => $hookParsedData['call'],
					'included_file' => $hookParsedData['hookFile'],
					'instance' => $hookParsedData['object'],
					'status' => $hookParsedData['enabled'] ? 'valid' : 'error',
					'enabled' => $hookParsedData['enabled'],
					'can_disable' => $hookParsedData['call'] != '',
				];
			}

		// Filter the results by our search terms.
		foreach ($this->searchTerms as $term)
			$hooks = array_filter(
				$hooks,
				function($value, $key) use ($term) {
					return stripos($value[$term], $this->getSearchTerm($term)) > -1;
				},
				ARRAY_FILTER_USE_BOTH
			);
	
		return $hooks;
	}

	/*
	 * This adds a "fake" row to the hooks data that will act as our handler to hold search terms.
	 *
	 * @return array A "row" that contains search input fields.
	*/
	private function insertSearchRow(): array
	{
		return [
			[
				'hook_name' => '<input type="text" name="search[hook_name]" value="' . $this->getSearchTerm('hook_name') . '" size="30">',
				'instance' => null,
				'function_name' => null,
				'included_file' => '<input type="text" name="search[included_file]" value="' . $this->getSearchTerm('included_file') . '" size="60">',
				'status' => null,
				'enabled' => null,
				'can_disable' => false,
				'real_function' => '<input type="text" name="search[real_function]" value="' . $this->getSearchTerm('real_function') . '" size="30">',
			]
		];
	}

	/*
	 * Looks for the requested search term and sanitizes the input.
	 * If we can't find the requested input, use a empty string.
	 *
	 * @param string $key, the search term we are looking for.
	 * @return string The sanitized search term.
	*/
	private function getSearchTerm(string $key): string
	{
		return filter_var($_REQUEST['search'][$key] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
	}

	/*
	 * This checks if our success message is valid, if so we can use that text string, otherwise we use a generic message.
	 *
	 * @param string $action The success action we took
	 * @return string The language string we will use on our succcess message.
	*/
	private function successMsg(string $action): string
	{
		return in_array($action, ['toggle', 'add', 'modify']) ? 'devtools_success_' . $action : 'settings_saved';
	}

	/*
	 * Toggle a hook on/off.  We dtermine which way to toggle by checked the enabled status of the hook.
	 *
	 * @calls: $sourcedir/Subs.php:remove_integration_function
	 * @calls: $sourcedir/Subs.php:add_integration_function
	 * @param string $hookID The ID of the hook we are looking for.
	*/
	private function toggleHook(string $hookID): void
	{
		$hooks = $this->getRawHooks();
		$hook = array_filter(
			$hooks,
			function($value) use ($hookID) {
				return stripos($value['key'], $hookID) > -1;
			}
		);

		// Can't toggle this, its not  unique.
		if (count($hook) !== 1)
			return;

		$hook = $hook[array_key_first($hook)];

		$new_func = $old_func = $hook['real_function'];
		if ($hook['enabled'])
			$new_func = '!' . $new_func;
		else
			$old_func = '!' . $old_func;

		remove_integration_function($hook['hook_name'], $old_func, true, $hook['included_file'], $hook['instance']);
		add_integration_function($hook['hook_name'], $new_func, true, $hook['included_file'], $hook['instance']);

		// Force the hooks to rebuild.
		$this->getRawHooks(true);

		$this->dt->showSuccessDialog($this->successMsg('toggle'));
	}

	/*
	 * Add a hook.  Adds a hook to the system
	 *
	 * @calls: $sourcedir/Subs.php:add_integration_function
	 * @param bool $rebuildHooks When true, we will issue the rebuild hooks.  This is used as we may use other logic elsewhere and we wish to wait on the rebuild logic.
	*/
	private function addHook(bool $rebuildHooks = true): void
	{
		$replacements = [
			' ' => '_',
			"\0" => '',
		];

		$hook = [
			'hook_name' => strtr(strip_tags($_POST['hook_name']), $replacements),
			'real_function' => strtr(strip_tags($_POST['real_function']), $replacements),
			'included_file' => strtr(strip_tags($_POST['included_file']), $replacements),
			'instance' => isset($_POST['instance']),
		];

		// Ensure the hook has the integrate prefix.
		if (substr($hook['hook_name'], 0, 10) !== 'integrate_')
			$hook['hook_name'] = 'integrate_' . $hook['hook_name'];

		add_integration_function($hook['hook_name'], $hook['real_function'], true, $hook['included_file'], $hook['instance']);
		$this->dt->showSuccessDialog($this->successMsg('addhook'));

		// Rebuild the hooks?
		$this->getRawHooks(true);
	}

	/*
	 * Delete a hook.  Removes a hook to the system
	 *
	 * @calls: $sourcedir/Subs.php:remove_integration_function
	 * @param string $hookID The ID of the hook we are looking for.
	 * @param bool $rebuildHooks When true, we will issue the rebuild hooks.  This is used as we may use other logic elsewhere and we wish to wait on the rebuild logic.
	*/
	private function deleteHook(string $hookID, bool $rebuildHooks = true): void
	{
		// Find the hook we are looking for.
		$hooks = $this->getRawHooks();
		$hook = array_filter(
			$hooks,
			function($value) use ($hookID) {
				return stripos($value['key'], $hookID) > -1;
			}
		);

		// Can't toggle this, its not  unique.
		if (count($hook) !== 1)
			return;
		$hook = $hook[array_key_first($hook)];

		// Remove the hook.
		remove_integration_function($hook['hook_name'], $hook['real_function'], true, $hook['included_file'], $hook['instance']);
		$this->dt->showSuccessDialog($this->successMsg('deletehook'));

		// Rebuild the hooks?
		if ($rebuildHooks)
			$this->getRawHooks(true);
	}

	/*
	 * Modify a hook.  This simply calls the deleteHook logic to remove the old and then the addHook logic to add the hook.
	 * This will only rebuild the hooks data after we complete the addHook logic.
	 *
	 * @param string $hookID The ID of the hook we are looking for.
	*/
	private function modifyHook(string $hookID): void
	{
		// Call the remove hook to remove it.
		$this->deleteHook($hookID, false);

		// Call the add hook, to add it.
		$this->addHook();

		// Thus we lie and say the hook was "modified".
		$this->dt->showSuccessDialog($this->successMsg('modifyhook'));
	}

	/*
	 * Takes a hook ID and returns the requested hook data, otherwise if it can't be found, returns empty hook data.
	 *
	 * @param string $hookID The ID of the hook we are looking for.
	*/	 
	private function getHookData(string $hookID): array
	{
		$hooks = $this->getRawHooks();
		$hook = [];

		if ($hookID !== '')
			$hook = array_filter(
				$hooks,
				function($value) use ($hookID) {
					return stripos($value['key'], $hookID) > -1;
				}
			);

		// Can't toggle this, its not  unique.
		if (count($hook) !== 1)
			return [
				'key' => '',
				'hook_name' => '',
				'real_function' => '',
				'included_file' => '',
				'instance' => false,
				'new' => true
			];

		return $hook[array_key_first($hook)];
	}

	/*
	 * This the add/modify template for hooks that is appended to the end of the createList function.
	 *
	 * @param array $hook All the hook data, or empty hook data.
	 * @return string the Strinified HTML data to append to createList.
	*/
	private function template_hooks_modify(array $hook): string
	{
		$rt = '<fieldset><dl class="settings">'

				. '<dt><label for="hook_name">' . $this->dt->txt('hooks_field_hook_name') . '</label></dt>'
				. '<dd><input type="text" name="hook_name" value="' . $hook['hook_name'] . '"></dt>'

				. '<dt><label for="real_function">' . $this->dt->txt('hooks_field_function_name') . '</label></dt>'
				. '<dd><input type="text" name="real_function" value="' . $hook['real_function'] . '"></dt>'

				. '<dt><label for="included_file">' . $this->dt->txt('hooks_field_included_file') . '</label></dt>'
				. '<dd><input type="text" name="included_file" value="' . $hook['included_file'] . '"></dt>'

				. '<dt><label for="instance">' . $this->dt->txt('devtools_instance') . '</label></dt>'
				. '<dd><input type="checkbox" name="instance" value="1"' . (!empty($hook['instance']) ? ' checked': '') . '></dt>'

				. '<dt></dt><dd><button name="' . (!empty($hook['new']) ? 'add' : 'modify') . '" value="' . $hook['key'] . '" class="button">' . $this->dt->txt(!empty($hook['new']) ? 'new' : 'edit') . '</button>' . (empty($hook['new']) ? (' <button name="delete" value="' . $hook['key'] . '" class="button you_sure">' . $this->dt->txt('delete') . '</button>') : '') . '</dd>'
			. '</dl></fieldset>'
		;

		return $rt;
	}
}