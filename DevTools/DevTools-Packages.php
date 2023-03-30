<?php

/**
 * The class for DevTools Packages.
 * @package DevTools
 * @author SleePy <sleepy @ simplemachines (dot) org>
 * @copyright 2023
 * @license 3-Clause BSD https://opensource.org/licenses/BSD-3-Clause
 * @version 1.1
*/
class DevToolsPackages
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
	private array $txt;
	private array $settings;
	private bool $db_show_debug;
	/* 
	 * SMF has this both as an array and a bool, no type delcartion.
	*/
	private $package_cache;

	/*
	 * The data file we are looking for inside packages.
	*/
	private string $packageInfoName = 'package-info.xml';

	/*
	 * This is the package id of dev tools, used to hide itself from being modified with under normal circumstances
	*/
	private string $devToolsPackageID = 'sleepy:devtools';

	/*
	 * Builds the DevTools Packages object.  This also loads a few globals into easy to access properties, some by reference so we can update them
	*/
	public function __construct()
	{
		foreach (['scripturl', 'packagesdir', 'settings', 'boarddir', 'sourcedir'] as $f)
			$this->{$f} = $GLOBALS[$f];
		foreach (['context', 'smcFunc', 'package_cache', 'modSettings'] as $f)
			$this->{$f} = &$GLOBALS[$f];

		$this->dt = &$this->context['instances']['DevTools'];
		$this->dt->loadSources(['Packages', 'Subs-Package', 'Subs-List', 'Class-Package']);
		$this->dt->loadLanguage(['Admin', 'Packages']);
	}

	/*
	 * Loads the main package listing.
	 *
	 * @calls: $sourcedir/Subs-List.php:createList
	*/
	public function packagesIndex(): void
	{
		$this->context['available_packages'] = 0;
		createList($this->context['packages'] = $this->buildPackagesList());

		// An action was successful.
		if (isset($_REQUEST['success']))
			$this->dt->showSuccessDialog($this->successMsg((string) $_REQUEST['success']));
	}

	/*
	 * Reinstall hooks logic.  Will issue a failure if we can't do any step in this process.
	 * Upon success, this will redirect back to package listing.
	 *
	 * @calls: $sourcedir/Errors.php:fatal_lang_error
	 * @calls: $sourcedir/Subs.php:redirectexit
	*/
	public function HooksReinstall(): void
	{
		// Ensure the file is valid.
		if (($package = $this->getRequestedPackage()) == '' || !$this->isValidPackage($package))
			fatal_lang_error('package_no_file', false);
		else if (($basedir = $this->getPackageBasedir($package)) == '')
			fatal_lang_error('package_get_error_not_found', false);

		$infoFile = $this->getPackageInfo($basedir . DIRECTORY_SEPARATOR . $this->packageInfoName);
		if (!is_a($infoFile, 'xmlArray'))
			fatal_lang_error('package_get_error_missing_xml', false);

		$install = $this->findInstall($infoFile, SMF_VERSION);

		if (!is_a($infoFile, 'xmlArray'))
			fatal_lang_error('package_get_error_packageinfo_corrupt', false);

		$hooks = $this->findHooks($install);
		
		if (!$this->uninstallHooks($hooks) || !$this->installHooks($hooks))
			fatal_lang_error('devtools_hook_reinstall_fail', false);

		redirectexit('action=devtools;sa=packages;success=reinstall');		
	}

	/*
	 * Uninstall hooks logic.  Will issue a failure if we can't do any step in this process.
	 * Upon success, this will redirect back to package listing.
	 *
	 * @calls: $sourcedir/Errors.php:fatal_lang_error
	 * @calls: $sourcedir/Subs.php:redirectexit
	*/
	public function HooksUninstall(): void
	{
		// Ensure the file is valid.
		if (($package = $this->getRequestedPackage()) == '' || !$this->isValidPackage($package))
			fatal_lang_error('package_no_file', false);
		else if (($basedir = $this->getPackageBasedir($package)) == '')
			fatal_lang_error('package_get_error_not_found', false);

		$infoFile = $this->getPackageInfo($basedir . DIRECTORY_SEPARATOR . $this->packageInfoName);
		if (!is_a($infoFile, 'xmlArray'))
			fatal_lang_error('package_get_error_missing_xml', false);

		$install = $this->findInstall($infoFile, SMF_VERSION);

		if (!is_a($infoFile, 'xmlArray'))
			fatal_lang_error('package_get_error_packageinfo_corrupt', false);

		$hooks = $this->findHooks($install);
		
		if (!$this->uninstallHooks($hooks))
			fatal_lang_error('devtools_hook_reinstall_fail', false);

		redirectexit('action=devtools;sa=packages;success=uninstall');		
	}

	/*
	 * Sync Files into packages.  Will issue a failure if we can't do any step in this process.
	 * Upon success, this will redirect back to package listing.
	 *
	 * @calls: $sourcedir/Subs-List.php:createList
	 * @calls: $sourcedir/Errors.php:fatal_lang_error
	 * @calls: $sourcedir/Subs.php:redirectexit
	*/
	public function FilesSyncIn(): void
	{
		// Ensure the file is valid.
		if (($package = $this->getRequestedPackage()) == '' || !$this->isValidPackage($package))
			fatal_lang_error('package_no_file', false);
		else if (($basedir = $this->getPackageBasedir($package)) == '')
			fatal_lang_error('package_get_error_not_found', false);

		$infoFile = $this->getPackageInfo($basedir . DIRECTORY_SEPARATOR . $this->packageInfoName);
		if (!is_a($infoFile, 'xmlArray'))
			fatal_lang_error('package_get_error_missing_xml', false);

		$install = $this->findInstall($infoFile, SMF_VERSION);

		if (!is_a($infoFile, 'xmlArray'))
			fatal_lang_error('package_get_error_packageinfo_corrupt', false);

		// File Operations we will do.
		$ops = $this->findFileOperations($install, $basedir);

		// Sync the files.
		$acts = $this->doSyncFiles($ops);

		// Find out if we have an error.
		$has_error = array_search(false, array_column($acts, 'res'));

		// No errors, just return.
		if (!$has_error)
			redirectexit('action=devtools;sa=packages;success=syncin');

		// Create a list showing what failed to sync.
		createList($this->context['syncfiles'] = $this->buildSyncStatusList($acts, $package));
	}

	/*
	 * Sync Files out to SMF.  Will issue a failure if we can't do any step in this process.
	 * Upon success, this will redirect back to package listing.
	 *
	 * @calls: $sourcedir/Subs-List.php:createList
	 * @calls: $sourcedir/Errors.php:fatal_lang_error
	 * @calls: $sourcedir/Subs.php:redirectexit
	*/
	public function FilesSyncOut(): void
	{
		// Ensure the file is valid.
		if (($package = $this->getRequestedPackage()) == '' || !$this->isValidPackage($package))
			fatal_lang_error('package_no_file', false);
		else if (($basedir = $this->getPackageBasedir($package)) == '')
			fatal_lang_error('package_get_error_not_found', false);

		$infoFile = $this->getPackageInfo($basedir . DIRECTORY_SEPARATOR . $this->packageInfoName);
		if (!is_a($infoFile, 'xmlArray'))
			fatal_lang_error('package_get_error_missing_xml', false);

		$install = $this->findInstall($infoFile, SMF_VERSION);

		if (!is_a($infoFile, 'xmlArray'))
			fatal_lang_error('package_get_error_packageinfo_corrupt', false);

		// File Operations we will do.
		$ops = $this->findFileOperations($install, $basedir);

		// Sync the files.
		$acts = $this->doSyncFiles($ops, true);

		// Find out if we have an error.
		$has_error = array_search(false, array_column($acts, 'res'));

		// No errors, just return.
		if (!$has_error)
			redirectexit('action=devtools;sa=packages;success=syncout');

		// Create a list showing what failed to sync.
		createList($this->context['syncfiles'] = $this->buildSyncStatusList($acts, $package, true));
	}

	/*
	 * Returns an array that will be passed into SMF's createList logic to build a packages listing.
	*
	 * @calls: $sourcedir/Subs.php:timeformat
	*/
	private function buildPackagesList(): array
	{
		return [
			'id' => 'packages_lists_modification',
			'no_items_label' => $this->dt->txt('no_packages'),
			'get_items' => [
				'function' => [$this, 'listGetPackages'],
				'params' => ['modification'],
			],
			'base_href' => $this->scripturl . '?action=devtools;area=packages',
			'default_sort_col' => 'idmodification',
			'columns' => [
				'idmodification' => [
					'header' => [
						'value' => $this->dt->txt('package_id'),
						'style' => 'width: 52px;',
					],
					'data' => [
						'db' => 'sort_id',
					],
					'sort' => [
						'default' => 'sort_id',
						'reverse' => 'sort_id'
					],
				],
				'mod_namemodification' => [
					'header' => [
						'value' => $this->dt->txt('mod_name'),
						'style' => 'width: 25%;',
					],
					'data' => [
						'db' => 'name',
					],
					'sort' => [
						'default' => 'name',
						'reverse' => 'name',
					],
				],
				'versionmodification' => [
					'header' => [
						'value' => $this->dt->txt('mod_version'),
					],
					'data' => [
						'db' => 'version',
					],
					'sort' => [
						'default' => 'version',
						'reverse' => 'version',
					],
				],
				'time_installedmodification' => [
					'header' => [
						'value' => $this->dt->txt('mod_installed_time'),
					],
					'data' => [
						'function' => function($package)
						{
							return !empty($package['time_installed'])
								? timeformat($package['time_installed'])
								: $this->dt->txt('not_applicable');
						},
						'class' => 'smalltext',
					],
					'sort' => [
						'default' => 'time_installed',
						'reverse' => 'time_installed',
					],
				],
				'operationsmodification' => [
					'header' => [
						'value' => '',
					],
					'data' => [
						'function' => [$this, 'listColOperations'],
						'class' => 'righttext',
					],
				],
			],
		];
	}

	/*
	 * Get a listing of packages from SMF, then run through a filter to remove any compressed files.
	 * This also will exclude our own package.
	 *
	 * @param ...$args all params that will just be passed directly into SMF's native list_getPackages
	 * @See: $sourcedir/Packages.php:list_getPackages
	 * @return array List of filtered packages we can work with.
	*/
	public function listGetPackages(...$args): array
	{
		// Filter out anything with an extension, we don't support working with compressed files.
		// list_getPackages is from SMF in Packages.php
		return array_filter(list_getPackages(...$args), function($p) {
			return $this->isValidPackage($p['filename']) && (!empty($this->modSettings['dt_showAllPackages']) || strpos($p['id'], $this->devToolsPackageID) === false);
		});
	}

	/*
	 * All possible operations we can perform on a package.
	 * If a package can not be uninstalled, we remove the uninstall/reinstall actions.
	 *
	 * @param array $packagethe package data
	 * @return string The actions we can perform.
	*/
	public function listColOperations(array $package): string
	{
		$actions = [
			'uninstall' => '<a href="' . $this->scripturl . '?action=devtools;sa=uninstall;package=' . $package['filename'] . '" class="button floatnone">' . $this->dt->txt('devtools_packages_uninstall') . '</a>',
			'reinstall' => '<a href="' . $this->scripturl . '?action=devtools;sa=reinstall;package=' . $package['filename'] . '" class="button floatnone">' . $this->dt->txt('devtools_packages_reinstall') . '</a>',
			'syncin' => '<a href="' . $this->scripturl . '?action=devtools;sa=syncin;package=' . $package['filename'] . '" class="button floatnone">' . $this->dt->txt('devtools_packages_syncin') . '</a>',
			'syncout' => '<a href="' . $this->scripturl . '?action=devtools;sa=syncout;package=' . $package['filename'] . '" class="button floatnone">' . $this->dt->txt('devtools_packages_syncout') . '</a>',
		];

		if (!$package['can_uninstall'])
			unset($actions['uninstall'], $actions['reinstall']);

		return implode('', $actions);
	}

	/*
	 * Builds a list for our sync status to show what errored out.
	 *
	 * @param array $actsThe actions we took.
	 * @param string $packageThe package we are performing the action on.
	 * @param bool $reverseThe direction we are going.  When reversing we are syncing from SMF to the package.
	 * @return array The data that we will pass to createList.
	*/
	private function buildSyncStatusList(array $acts, string $package, bool $reverse = false): array
	{
		$src = $reverse ? 'smf' : 'pkg';
		$dst = $reverse ? 'pkg' : 'smf';

		return [
			'id' => 'syncfiles_list',
			'no_items_label' => $this->dt->txt('no_packages'),
			'get_items' => [
				'value' => $acts,
			],
			'columns' => [
				'file' => [
					'header' => [
						'value' => $this->dt->txt('package_file'),
					],
					'data' => [
						'function' => function ($data) use ($src) {
							return basename($data[$src]);
						},
					],
				],
				'src' => [
					'header' => [
						'value' => $this->dt->txt('file_location'),
					],
					'data' => [
						'function' => function ($data) use ($src) {
							return $this->cleanPath(dirname($data[$src]));
						},
					],
				],
				'dst' => [
					'header' => [
						'value' => $this->dt->txt('package_extract'),
					],
					'data' => [
						'function' => function ($data) use ($dst) {
							return $this->cleanPath($data[$dst]);
						},
					],
				],
				'writeable' => [
					'header' => [
						'value' => $this->dt->txt('package_file_perms_status'),
					],
					'data' => [
						'function' => function ($data) {
							return $this->dt->txt(empty($data['isw']) ? 'package_file_perms_not_writable' : 'package_file_perms_writable');
						},
					],
				],
				'status' => [
					'header' => [
						'value' => $this->dt->txt('package_file_perms_status'),
					],
					'data' => [
						'function' => function ($data) {
							return $this->dt->txt(empty($data['res']) ? 'package_restore_permissions_action_failure' : 'package_restore_permissions_action_success');
						},
					],
				],
			],
			'additional_rows' => [
				[
					'position' => 'bottom_of_list',
					'value' => '<a href="' . $this->scripturl . '?action=devtools;sa=' . ($reverse ? 'syncout' : 'syncin') . ';package=' . $package . '" class="button floatnone">' . $this->dt->txt($reverse ? 'devtools_packages_syncout' : 'devtools_packages_syncin') . '</a>',
					'class' => 'floatright',
				],
			],
		];
	}

	/*
	 * Get the requested package, filtering the data in the reuqest for santity checks.
	 *
	 * @return string The cleaned package.
	*/
	private function getRequestedPackage(): string
	{
		return (string) preg_replace('~[^a-z0-9\-_\.]+~i', '-', $_REQUEST['package'] ?? '');
	}
	
	/*
	 * Tests whether this package is valid.  Looks for the directory to exist in the packages folder.
	 *
	 * @param string $package A package name.
	 * @return bool True if the directory exists, false otherwise.
	*/
	private function isValidPackage(string $package): bool
	{
		return is_dir($this->packagesdir . DIRECTORY_SEPARATOR . $package);
	}

	/*
	 * This looks in a package and attempts to get the info file.  SMF only normally supports it in the root directory.
	 * This attempts to do a bit more work to find it.  As such, SMF may not actually install and the sync logic may not work.
	 *
	 * @param string $package The package we are looking at.
	 * @return string The path to the directory inside the package that contains the info file.
	*/
	private function getPackageBasedir(string $package): string
	{
		// Simple, its at the file root
		if (file_exists($this->packagesdir . DIRECTORY_SEPARATOR . $package . DIRECTORY_SEPARATOR . $this->packageInfoName))
			return $this->packagesdir . DIRECTORY_SEPARATOR . $package;

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$this->packagesdir . DIRECTORY_SEPARATOR . $package,
				RecursiveDirectoryIterator::SKIP_DOTS
			)
		);

		// Someday we could simplify this?
		foreach ($files as $f)
		{
			if ($f->getFilename() == $this->packageInfoName)
			{
				return dirname($f->getPathName());
				break;
			}
		}

		return '';
	}
	
	/*
	 * This will pass the info file through SMF's xmlArray object and returns a valid xmlArray we will use to parse it.
	 * This uses SMF's xmlArray rather than the built in xml tools in PHP as it is what package manager is using.
	 *
	 * @calls: $sourcedir/Class-Package.php:xmlArray
	 * @param string $packageInfoFile The info we are looking at.
	 * @return xmlArray A valid object of xml data from the info file.
	*/
	private function getPackageInfo(string $packageInfoFile): xmlArray
	{
		return new xmlArray(file_get_contents($packageInfoFile));
	}

	/*
	 * Finds the valid install action for a customization.
	 * Note: This will match <install> and <install for="SMF X.Y"> with a matching SMF version.  Ideally we should limit this to a matching version.
	 *
	 * @calls: $sourcedir/Class-Package.php:xmlArray
	 * @calls: $sourcedir/Sub-Package.php:matchPackageVersion
	 * @param xmlArray $packageXML A valid xmlArray object.
	 * @param string $smfVersion The current SMF version we are looking for.
	 * @return xmlArray A valid object of xml data from the info file, limited to the matched install actions.
	*/
	private function findInstall(xmlArray $packageXML, string $smfVersion): xmlArray
	{
		$methods = $packageXML->path('package-info[0]')->set('install');

		// matchPackageVersion is in Subs-Package.php
		foreach ($methods as $i)
		{
			// Found a for in the install, skip if it doesn't match our version.
			if ($i->exists('@for') && !matchPackageVersion($smfVersion, $i->fetch('@for')))
				continue;
			return $i;
		}
	}

	/*
	 * Processes a xmlArray install action for any hook related call.
	 *
	 * @calls: $sourcedir/Class-Package.php:xmlArray
	 * @param xmlArray $installXML A valid xmlArray install object.
	 * @return array All valid hooks in the package.
	*/
	private function findHooks(xmlArray $installXML): array
	{
		$hooks = [];
		$actions = $installXML->set('*');
		foreach ($actions as $action)
		{
			$actionType = $action->name();

			if (!in_array($actionType, ['hook']))
				continue;

			$hooks[] = [
				'function' => $action->exists('@function') ? $action->fetch('@function') : '',
				'hook' => $action->exists('@hook') ? $action->fetch('@hook') : $action->fetch('.'),
				'include_file' => $action->exists('@file') ? $action->fetch('@file') : '',
				'reverse' => $action->exists('@reverse') && $action->fetch('@reverse') == 'true' ? true : false,
				'object' => $action->exists('@object') && $action->fetch('@object') == 'true' ? true : false,
			];
		}

		return $hooks;
	}

	/*
	 * Processes a xmlArray install action for any file operations related call.
	 *
	 * @calls: $sourcedir/Class-Package.php:xmlArray
	 * @param xmlArray $installXML A valid xmlArray install object.
	 * @param string $basedir The base directory we are working with.  This should be the directory we found the info file in.
	 * @return array All valid hooks in the package.
	*/
	private function findFileOperations(xmlArray $installXML, string $basedir): array
	{
		$hooks = [];
		$actions = $installXML->set('*');
		foreach ($actions as $action)
		{
			$actionType = $action->name();

			// Only supporting right now require file/dir as it is used to move files from the package into SMF.
			if (!in_array($actionType, ['require-file', 'require-dir']))
				continue;

			$hooks[] = [
				'pkg' => $action->exists('@from') ? $this->parsePath($action->fetch('@from')) : $basedir . DIRECTORY_SEPARATOR . $action->fetch('@name'),
				'smf' => $this->parsePath($action->fetch('@destination')) . DIRECTORY_SEPARATOR . basename($action->fetch('@name'))
			];
		}

		return $hooks;
	}

	/*
	 * Syncs files from one location to another.  The direction of the search is handled by the bool $reverse logic.
	 *
	 * @calls: $sourcedir/Subs-Package.php:package_chmod
	 * @calls: $sourcedir/Subs-Package.php:copytree
	 * @calls: $sourcedir/Subs-Package.php:package_put_contents
	 * @calls: $sourcedir/Subs-Package.php:package_get_contents
	 * @param array $ops All the file operations we need to take.
	 * @param bool $reverse When reversed we sync from the packages to SMF.
	 * @return array All operations and the result status.
	*/
	private function doSyncFiles(array $ops, bool $reverse = false): array
	{
		$this->disablePackageCache();
		$src = $reverse ? 'pkg' : 'smf';
		$dst = $reverse ? 'smf' : 'pkg';

		// package_put/get_contents in Subs-Package.php
		return array_map(function($op) use ($src, $dst) {
			// Let us know the writable status.
			$op['isw'] = package_chmod($op[$dst]);

			if (is_dir($op[$src]))
				$op['res'] = copytree($op[$src], $op[$dst]);
			elseif (is_file($op[$src]))
				$op['res'] =  package_put_contents($op[$dst], package_get_contents($op[$src]));
			else
				$op['res'] =  'unknown';

			// Do a empty file check.
			if (!$op['res'] && is_file($op[$dst]) && package_get_contents($op[$src]) == package_get_contents($op[$dst]))
				$op['res'] = true;
			elseif (is_dir($op[$src]) && is_dir($op[$dst]))
				$op['res'] = $this->validateDirectoriesAreEqual($op[$src], $op[$dst]);
				
			return $op;
		}, $ops);
	}

	/*
	 * Uninstall all hooks specified in this action.
	 * This may be confusing, but SMF may be telling us to "reverse" the action", so we would actually install it.
	 *
	 * @calls: $sourcedir/Subs.php:remove_integration_function
	 * @calls: $sourcedir/Subs.php:add_integration_function
	 * @param array $hooks All the hooks we will process.
	 * @return bool Successful indication of hook removal or not.  We currently don't track this as SMF doesn't indicate success/failure.
	*/
	private function uninstallHooks(array $hooks): bool
	{
		return array_walk($hooks, function($action) {
			// During uninstall we will typically "remove", but try to handle "adds" that are "removes", confusing.
			if (!$action['reverse'])
				remove_integration_function($action['hook'], $action['function'], true, $action['include_file'], $action['object']);
			else
				add_integration_function($action['hook'], $action['function'], true, $action['include_file'], $action['object']);
		});
	}

	/*
	 * Install all hooks specified in this action.
	 * This may be confusing, but SMF may be telling us to "reverse" the action", so we would actually uninstall it.
	 *
	 * @calls: $sourcedir/Subs.php:remove_integration_function
	 * @calls: $sourcedir/Subs.php:add_integration_function
	 * @param array $hooks All the hooks we will process.
	 * @return bool Successful indication of hook removal or not.  We currently don't track this as SMF doesn't indicate success/failure.
	*/
	private function installHooks(array $hooks): bool
	{
		return array_walk($hooks, function($action) {
			if ($action['reverse'])
				remove_integration_function($action['hook'], $action['function'], true, $action['include_file'], $action['object']);
			else
				add_integration_function($action['hook'], $action['function'], true, $action['include_file'], $action['object']);
		});
	}

	/*
	 * This checks if our success message is valid, if so we can use that text string, otherwise we use a generic message.
	 *
	 * @param string $action The success action we took
	 * @return string The language string we will use on our succcess message.
	*/
	private function successMsg(string $action): string
	{
		return in_array($action, ['reinstall', 'uninstall', 'syncin', 'syncout']) ? 'devtools_success_' . $action : 'settings_saved';
	}

	/*
	 * ParsePath from SMF, but wrap it incase we need to do cleanup.
	 *
	 * @calls: $sourcedir/Subs-Package.php:parse_path
	 * @param string $p The current path.
	 * @return string A parsed parse with a valid directory.
	*/
	private function parsePath(string $p): string
	{
		return parse_path($p);
	}

	/*
	 * SMF will cache package directory information.  This disables it so we can work with the data without delays.
	*/
	private function disablePackageCache(): void
	{
		$this->package_cache = false;
		$this->modSettings['package_disable_cache'] = true;
	}

	/*
	 * Cleanup any paths we find to what they would be parsed out as with placeholders.
	 *
	 * @param string $path The path to be cleaned.
	 * @return string THe path with placeholders.
	*/
	private function cleanPath(string $path): string
	{
		return strtr($path, [
			$this->settings['default_theme_dir'] . DIRECTORY_SEPARATOR . basename($GLOBALS['settings']['default_images_url']) => '$imagesdir',
			$this->settings['default_theme_dir'] . '/languages' => '$languagedir',
			$this->settings['default_theme_dir'] => '$themedir',
			$this->modSettings['avatar_directory'] => '$avatardir',
			$this->modSettings['smileys_dir'] => '$smileysdir',
			$this->boarddir . '/Themes' => '$themes_dir',
			$this->sourcedir => '$sourcedir',
			$this->packagesdir => '$packagesdir',
			$this->boarddir => '$boarddir',
		]);
	}

	/*
	 * Compare two directories to see if they appear consistent.
	 * We do this by reading them, finding their sha1_file, json_encode the array and then sha1 that string.
	 * By comparing two directories this way, we should end up with the same sha1 hash.
	 *
	 * @param string $src Source directory to compare.
	 * @param string $dst Destination directory to compare.
	 * @return bool True if they match, false otherwise.
	*/
	private function validateDirectoriesAreEqual(string $src, string $dst): bool
	{
		$srcFiles = $dstFiles = [];

		// Get our files.
		foreach (['src', 'dst'] as $op)
		{
			$s = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$$op,
					RecursiveDirectoryIterator::SKIP_DOTS
				),
			);

			foreach ($s as $file)
			{
				if ($file->isDir())
					return true;
				$basePath = substr($file->getPathname(), strlen($$op . DIRECTORY_SEPARATOR), null);
				${$op . 'Files'}[$basePath] = sha1_file($file->getPathname());		
			}
		}

		return sha1(json_encode($srcFiles)) == sha1(json_encode($dstFiles));
	}
}