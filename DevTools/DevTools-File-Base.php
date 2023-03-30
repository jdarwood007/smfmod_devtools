<?php

/**
 * The class for DevTools Hooks.
 * @package DevTools
 * @author SleePy <sleepy @ simplemachines (dot) org>
 * @copyright 2023
 * @license 3-Clause BSD https://opensource.org/licenses/BSD-3-Clause
 * @version 1.1
*/
abstract class DevToolsFileBase
{
	/*
	 * The filename we will use upon downloading.
	*/
	protected string $fileName;

	/*
	 * The physical path to the download file.
	*/
	protected string $physicalDownloadFile;

	/*
	 * The directory will be compressing.
	*/
	protected string $directory;

	/*
	 * Anything we are excluding.
	*/
	protected array $exclusions = [];

	/*
	 * Our temp working directory.
	*/
	private ?string $temp_dir = null;

	/*
	 * The data file we are looking for inside packages.
	*/
	protected string $packageInfoName = 'package-info.xml';

	/*
	 * Sets the file name to use on download.
	 *
	 * @param string $fileName Filename to use.
	 * @return self return our own class
	*/
	public function setFileName(string $fileName): self
	{
		$this->fileName = $fileName;

		return $this;
	}

	/*
	 * Sets the directory we will be compressing.
	 *
	 * @param string $directory directory to use.
	 * @return self return our own class
	*/
	public function setDirectory(string $directory): self
	{
		$this->directory = $directory;

		return $this;
	}

	/*
	 * Set a single exclusion.
	 *
	 * @param string $exclusion What we will be excluding.  Can use * wildcards
	 * @return self return our own class
	*/
	public function setExclusion(string $exclusion): self
	{
		$this->exclusions[] = $exclusion;

		return $this;
	}

	/*
	 * Sets multiple exclusions.
	 *
	 * @param array $exclusions What we will be excluding.  Can use * wildcards
	 * @return self return our own class
	*/
	public function setExclusions(array $exclusions): self
	{
		$this->exclusions += $exclusions;

		return $this;
	}

	/*
	 * Retreive the working file name.
	 * This searches for a valid temp directory we can work with.
	 *
	 * @return string the working file name
	*/
	public function GetWorkingFile(): string
	{
		$tempdir = $this->FindWorkingTempDirectory();
		
		return $tempdir . 'DevToolsTempArchive';
	}

	/*
	 * Find a working temp directory.
	 * Most of this is borrowed from Subs-Admin.php sm_temp_dir.
	 *
	 * @return string A valid temp directory
	*/
	private function FindWorkingTempDirectory(): string
	{
		global $cachedir;

		// Already did this.
		if (!empty($this->temp_dir))
			return $this->temp_dir;

		// Temp Directory options order.
		$temp_dir_options = array(
			0 => 'sys_get_temp_dir',
			1 => 'upload_tmp_dir',
			2 => 'session.save_path',
			3 => 'cachedir'
		);

		// Determine if we should detect a restriction and what restrictions that may be.
		$open_base_dir = ini_get('open_basedir');
		$restriction = !empty($open_base_dir) ? explode(':', $open_base_dir) : false;

		// Prevent any errors as we search.
		$old_error_reporting = error_reporting(0);

		// Search for a working temp directory.
		foreach ($temp_dir_options as $id_temp => $temp_option)
		{
			switch ($temp_option) {
				case 'cachedir':
					$possible_temp = rtrim($cachedir, '/');
					break;

				case 'session.save_path':
					$possible_temp = rtrim(ini_get('session.save_path'), '/');
					break;

				case 'upload_tmp_dir':
					$possible_temp = rtrim(ini_get('upload_tmp_dir'), '/');
					break;

				default:
					$possible_temp = sys_get_temp_dir();
					break;
			}

			// Check if we have a restriction preventing this from working.
			if ($restriction)
			{
				foreach ($restriction as $dir)
				{
					if (strpos($possible_temp, $dir) !== false && is_writable($possible_temp))
					{
						$this->temp_dir = $possible_temp;
						break;
					}
				}
			}
			// No restrictions, but need to check for writable status.
			elseif (is_writable($possible_temp))
			{
				$this->temp_dir = $possible_temp;
				break;
			}
		}

		// Fall back to sys_get_temp_dir even though it won't work, so we have something.
		if (empty($this->temp_dir))
			$this->temp_dir = sys_get_temp_dir();

		// Fix the path.
		$this->temp_dir = substr($this->temp_dir, -1) === '/' ? $this->temp_dir : $this->temp_dir . '/';

		// Put things back.
		error_reporting($old_error_reporting);

		return $this->temp_dir;
	}

	/*
	 * Delete the working file.
	 *
	 * @param ?string $file If provided we will delete this file, otherwise we get the working directory file.
	 * @return bool If we can't unlink the file or a error occurs, return false.
	*/
	protected function DeleteWorkingFile(?string $file): bool
	{
		try
		{
			return unlink($file ?? $this->GetWorkingFile());
		}
		catch (Exception $e)
		{
			return false;
		}
	}

	/*
	 * Actually downloads a file.  At this point we output the binary data and exit.
	 * Parts of this is borrowed from SMF's showAttachment function.
	 *
	 * @calls: $sourcedir/Load.php:isBrowser
	 * @return void Output is generated.
	*/
	public function downloadArchive(): void
	{
		$filesize = filesize($this->physicalDownloadFile);

		header('pragma: ');
		if (!isBrowser('gecko'))
			header('content-transfer-encoding: binary');
		header('expires: ' . gmdate('D, d M Y H:i:s', time() * 60));
		header('last-modified: ' . gmdate('D, d M Y H:i:s', time()));
		header('accept-ranges: bytes');
		header('connection: close');
		header('content-type: ' . (isBrowser('ie') || isBrowser('opera') ? 'application/octetstream' : 'application/octet-stream'));
		header('content-disposition: attachment; filename="' . $this->fileName . '"');
		header('cache-control: max-age=' . (60) . ', private');

		header("content-length: " . $filesize);

		if ($filesize > 4194304)
		{
			// Forcibly end any output buffering going on.
			while (@ob_get_level() > 0)
				@ob_end_clean();

			header_remove('content-encoding');

			$fp = fopen($this->GetWorkingFile(), 'rb');
			while (!feof($fp))
			{
				echo fread($fp, 8192);
				flush();
			}
			fclose($fp);
		}

		// On some of the less-bright hosts, readfile() is disabled.  It's just a faster, more byte safe, version of what's in the if.
		elseif (@readfile($this->physicalDownloadFile) === null)
			echo file_get_contents($this->physicalDownloadFile);

		$this->cleanupArchives();
		die();
	}

	/*
	 * Searches our working directory for any additional temp files and delete them.
	 *
	 * @return void Nothing is returned
	*/
	public function cleanupArchives(): void
	{
		$files = scandir($this->FindWorkingTempDirectory());

		if (!empty($files))
			foreach ($files as $f)
				if (strpos($f, 'DevToolsTempArchive') === 0)
					unlink($this->FindWorkingTempDirectory() . '/' . $f);
	}
}

/*
 * The interface for the file handler.
*/
interface DevToolsFileInterface
{
	public static function IsSupported(): bool;

	/*
	 * Sets the file name to use on download.
	 *
	 * @param string $fileName Filename to use.
	 * @return self return our own class
	*/
	public function setFileName(string $fileName);

	/*
	 * Sets the directory we will be compressing.
	 *
	 * @param string $directory directory to use.
	 * @return self return our own class
	*/
	public function setDirectory(string $directory);

	/*
	 * Set a single exclusion.
	 *
	 * @param string $exclusion What we will be excluding.  Can use * wildcards
	 * @return self return our own class
	*/
	public function setExclusion(string $exclusion);

	/*
	 * Sets multiple exclusions.
	 *
	 * @param array $exclusions What we will be excluding.  Can use * wildcards
	 * @return self return our own class
	*/
	public function setExclusions(array $exclusions);

	/*
	 * Actually generate our archive for downloading.
	 *
	 * @return self return our own class
	*/
	public function generateArchive();

	/*
	 * Actually downloads a file.  At this point we output the binary data and exit.
	 * Parts of this is borrowed from SMF's showAttachment function.
	 *
	 * @return void Output is generated.
	*/
	public function downloadArchive();

	/*
	 * Searches our working directory for any additional temp files and delete them.
	 *
	 * @return void Nothing is returned
	*/
	public function cleanupArchives(): void;
}