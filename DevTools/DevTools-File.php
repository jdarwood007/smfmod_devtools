<?php

/**
 * The class for DevTools Hooks.
 * @package DevTools
 * @author SleePy <sleepy @ simplemachines (dot) org>
 * @copyright 2023
 * @license 3-Clause BSD https://opensource.org/licenses/BSD-3-Clause
 * @version 1.1
*/
class DevToolsFiles
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
	/* Sometimes in SMF, this is null, which is unusal for a boolean */
	private ?bool $db_show_debug;

	/* 
	 * SMF has this both as an array and a bool, no type delcartion.
	*/
	private $package_cache;

	/*
	 * The data file we are looking for inside packages.
	*/
	private string $packageInfoName = 'package-info.xml';

	/*
	 * The extensions we support.
	*/
	private array $extensions = ['tgz', 'zip'];

	/*
	 * The providers we support.
	*/
	private array $providers = ['phar'];

	/*
	 * Builds the DevTools Packages object.  This also loads a few globals into easy to access properties, some by reference so we can update them
	*/
	public function __construct()
	{
		foreach (['scripturl', 'packagesdir', 'settings', 'boarddir', 'sourcedir', 'db_show_debug'] as $f)
			$this->{$f} = $GLOBALS[$f];
		foreach (['context', 'smcFunc', 'package_cache', 'modSettings'] as $f)
			$this->{$f} = &$GLOBALS[$f];

		$this->dt = &$this->context['instances']['DevTools'];
		$this->dt->loadSources([
			'DevToolsFile-Base',
			'DevToolsFile-PharBase',
			'DevToolsFile-PharTgz',
			'DevToolsFile-PharZip',
			'Packages',
			'Subs-Package',
			'Subs-List',
			'Class-Package'
		]);
		$this->dt->loadLanguage(['Admin', 'Packages']);
	}

	/*
	 * Loads the main package listing.
	 *
	 * @calls: $sourcedir/Subs-List.php:createList
	*/
	public function filesIndex(): void
	{
		$this->context['available_packages'] = 0;
		createList($this->context['packages'] = $this->buildPackagesList());

		// An action was successful.
		if (isset($_REQUEST['success']))
			$this->dt->showSuccessDialog($this->successMsg((string) $_REQUEST['success']));
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
			'base_href' => $this->scripturl . '?action=devtools;area=files',
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
		$actions = [];

		foreach ($this->providers as $provider)
			foreach ($this->extensions as $ext)
				$actions[$ext . $provider] = '<a href="' . $this->scripturl . '?action=devtools;area=files;sa=archive;package=' . $package['filename'] . ';extension=' . $ext . ';provider=' . $provider . '" class="button floatnone" data-nopopup="true">' . $this->dt->txt('devtools_extension_' . $ext) . '</a>';

		return implode('', $actions);
	}

	/*
	 * Download Archive.  Will issue a failure if we can't do any step in this process.
	 * Upon success, this will redirect back to package listing.
	 *
	 * @calls: $sourcedir/Errors.php:fatal_lang_error
	 * @calls: $sourcedir/Errors.php:fatal_error
	 * @calls: $sourcedir/Subs.php:redirectexit
	*/
	public function downloadArchive(): void
	{
		// Ensure the file is valid.
		if (($package = $this->getRequestedPackage()) == '' || !$this->isValidPackage($package))
			fatal_lang_error('package_no_file', false);
		else if (($basedir = $this->getPackageBasedir($package)) == '')
			fatal_lang_error('package_get_error_not_found', false);

		$infoFile = $this->getPackageInfo($basedir . DIRECTORY_SEPARATOR . $this->packageInfoName);
		if (!is_a($infoFile, 'xmlArray'))
			fatal_lang_error('package_get_error_missing_xml', false);

		$devtools = $this->findDevTools($infoFile);

		// If we can't find some data in our package info, just do defaults.
		$packageName = null;
		$exclusions = [];
		if (is_a($devtools, 'xmlArray'))
		{
			$packageName = $this->findPackageName($devtools) ?? null;
			$exclusions = $this->findExclusions($devtools) ?? [];
		}

		if (empty($packageName))
			$packageName = $this->defaultPackageName($package);

		// Handle some substitutions.
		$infoVersion = $this->findPackageInfoVersion($infoFile);
		$infoName = $this->findPackageInfoName($infoFile);
		$packageName = strtr($packageName, [
			'{VERSION}' => $infoVersion,
			'{VERSION-}' => str_replace('.', '-', $infoVersion),
			'{VERSION_}' => str_replace('.', '_', $infoVersion),
			'{CUSTOMIZATION-NAME}' => preg_replace('~\s~i', '-', $packageName),
			'{CUSTOMIZATION_NAME}' => preg_replace('~\s~i', '_', $packageName),
			'{CUSTOMIZATION NAME}' => $packageName,
		]);

		$className = 'DevToolsFile' . mb_convert_case($this->getRequestProvider(), MB_CASE_TITLE, 'UTF-8') . mb_convert_case($this->getRequestedExtension(), MB_CASE_TITLE, 'UTF-8');
		$handler = new $className;

		//Set our file directory and exclusions.  Also cleanup before we do anything else.
		$handler
			->setFileName($packageName . '.' . $this->getRequestedExtension())
			->setDirectory($this->getPackageBasedir($package))
			->setExclusions($exclusions)
			->cleanupArchives()
		;

		// Catch any error during generation and just show a standard error.
		try
		{
			$handler->generateArchive();
		}
		catch (Exception $e)
		{
			$handler->cleanupArchives();
			
			if (empty($this->db_show_debug))
				fatal_lang_error('devtools_error_archive_generation', false);
			else
				fatal_error($this->dt->txt('devtools_error_archive_generation') . "<br>" . $e->getMessage() . '<br>' . $e->getFile() . ':' . $e->getLine(), false);
		}

		$handler->downloadArchive();
	}

	/*
	 * Get the requested extension, filtering the data in the reuqest for santity checks.
	 *
	 * @return string The provider.
	*/
	private function getRequestProvider(): string
	{
		return isset($_REQUEST['provider']) && in_array($_REQUEST['provider'], $this->providers) ? $_REQUEST['provider'] : $this->providers[0];
	}

	/*
	 * Get the requested extension, filtering the data in the reuqest for santity checks.
	 *
	 * @return string The extension.
	*/
	private function getRequestedExtension(): string
	{
		return isset($_REQUEST['extension']) && in_array($_REQUEST['extension'], $this->extensions) ? $_REQUEST['extension'] : $this->extensions[0];
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
	 * Finds the valid devtools action for a customization.
	 * Note: This will match <devtools>.
	 *
	 * @calls: $sourcedir/Class-Package.php:xmlArray
	 * @calls: $sourcedir/Sub-Package.php:matchPackageVersion
	 * @param xmlArray $packageXML A valid xmlArray object.
	 * @return xmlArray A valid object of xml data from the info file, limited to the matched install actions.
	*/
	private function findDevTools(xmlArray $packageXML): ?xmlArray
	{
		return $packageXML->path('package-info[0]')->exists('devtools') ? $packageXML->path('package-info[0]')->set('devtools')[0] ?? null : null;
	}

	/*
	 * Finds the valid package name for download.
	 * Note: This will match <devtools>.
	 *
	 * @calls: $sourcedir/Class-Package.php:xmlArray
	 * @calls: $sourcedir/Sub-Package.php:matchPackageVersion
	 * @param xmlArray $devtoolsXML A valid xmlArray object.
	 * @return xmlArray A valid object of xml data from the info file, limited to the matched install actions.
	*/
	private function findPackageName(xmlArray $devtoolsXML): ?string
	{
		$packageName = $devtoolsXML->fetch('packagename');

		if (!empty($packageName))			
			return $packageName;

		return null;
	}

	private function defaultPackageName(string $package): string
	{
		return filter_var($package, FILTER_SANITIZE_URL);
	}

	/*
	 * Finds the version.
	 * Note: This will match <version>.
	 *
	 * @calls: $sourcedir/Class-Package.php:xmlArray
	 * @calls: $sourcedir/Sub-Package.php:matchPackageVersion
	 * @param xmlArray $packageXML A valid xmlArray object.
	 * @return string The version we found.
	*/
	private function findPackageInfoVersion(xmlArray $packageXML): string
	{
		return $packageXML->path('package-info[0]')->exists('version') ? $packageXML->path('package-info[0]')->fetch('version') ?? '' : '';
	}

	/*
	 * Finds the name.
	 * Note: This will match <name>.
	 *
	 * @calls: $sourcedir/Class-Package.php:xmlArray
	 * @calls: $sourcedir/Sub-Package.php:matchPackageVersion
	 * @param xmlArray $packageXML A valid xmlArray object.
	 * @return string The Package Name
	*/
	private function findPackageInfoName(xmlArray $packageXML): string
	{
		return $packageXML->path('package-info[0]')->exists('name') ? $packageXML->path('package-info[0]')->fetch('name') ?? '' : '';
	}

	/*
	 * Finds the valid exclusions for packaging.
	 * Note: This will match <devtools>.
	 *
	 * @calls: $sourcedir/Class-Package.php:xmlArray
	 * @calls: $sourcedir/Sub-Package.php:matchPackageVersion
	 * @param xmlArray $devtoolsXML A valid xmlArray object.
	 * @return xmlArray A valid object of xml data from the info file, limited to the matched install actions.
	*/
	private function findExclusions(xmlArray $devtoolsXML): array
	{
		$excludes = [];
		
		if ($devtoolsXML->exists('exclusion'))
		{
			$exs = $devtoolsXML->set('exclusion');
			foreach ($exs as $ex)
				$excludes[] = $ex->fetch('');
		}

		return $excludes;
	}

	/*
	 * This checks if our success message is valid, if so we can use that text string, otherwise we use a generic message.
	 *
	 * @param string $action The success action we took
	 * @return string The language string we will use on our succcess message.
	*/
	private function successMsg(string $action): string
	{
		return in_array($action, ['package']) ? 'devtools_success_' . $action : 'settings_saved';
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
	 * This is currently unused and a place holder for possible expansion to using the operating systems
	 *	built in zip/tar utilties to comrpess files.
	*/
	private function haveSystemSupport(): bool
	{
		if (isset($_SESSION['devToolsFile-haveSystemSupport']))
			return (bool) $_SESSION['devToolsFile-haveSystemSupport'];

		$hasSystemSupport = true;

		// We need shell exec.
		if (!function_exists('exec'))
			$hasSystemSupport = false;

		$output = null;
		$result_code = null;
		if ($hasSystemSupport)
		{
			exec('command -v zip', $output, $result_code);
			if ($result_code != 0 || empty($output))
				$hasSystemSupport = false;
		}

		$output = null;
		$result_code = null;
		if ($hasSystemSupport)
		{
			exec('command -v tar', $output, $result_code);
			if ($result_code != 0 || empty($output))
				$hasSystemSupport = false;
		}

		$_SESSION['devToolsFile-haveSystemSupport'] = $hasSystemSupport;
		return $hasSystemSupport;
	}
}