<?php
//namespace SMF\DevTools;

/**
 * The class for DevTools File Phar Base.
 * @package DevTools
 * @author SleePy <sleepy @ simplemachines (dot) org>
 * @copyright 2023
 * @license 3-Clause BSD https://opensource.org/licenses/BSD-3-Clause
 * @version 1.1
*/
class DevToolsFilePharBase Extends DevToolsFileBase
{
	/*
	 * PharData handler.
	*/
	protected $pd;

	/*
	 * Extension from PharData we will be creating.
	*/
	protected $extension = Phar::ZIP;

	/*
	 * Compression method we are using.
	*/
	protected $compression = Phar::NONE;

	/*
	 * Our file we are working with.
	*/
	protected $workingFile = null;

	/*
	 * Temp directory we are working with.
	*/
	protected $workingDir = null;

	/*
	 * The extension we are using for temp files.
	*/
	protected $tmpExtension = 'tmp';

	/*
	 * file Extensions for earch phar extesnion.
	*/
	protected $extensionMap = [
		Phar::PHAR => 'tmp',
		Phar::TAR => 'tar',
		Phar::ZIP => 'zip'
	];

	/*
	 * Is this file export method supported?
	 * This is currently not used, but exists for future expansion.
	 *
	 * @return bool True if this file export method appears to have all the support needed.
	*/
	public static function IsSupported(): bool
	{
		return class_exists('PharData');
	}

	/*
	 * Set what extension we are exporting.
	 * This should come in one of 3 options. Phar::PHAR, Phar::TAR, Phar::ZIP
	 *
	 * @param int $extension The extension we are exporting.
	 * @return self return our own class
	*/
	public function setExtension(int $extension): self
	{
		if (in_array($extension, [Phar::PHAR, Phar::TAR, Phar::ZIP]))
			$this->extension = $extension;

		return $this;
	}

	/*
	 * Actually generate our archive for downloading.
	 * This logic handles setting up PharData, adding the files, compressing (if needed) and sets the physical download file.
	 *
	 * @return self return our own class
	*/
	public function generateArchive(): self
	{
		// Change to the working directory.
		$this->workingDir = dirname($this->GetWorkingFile());
		chdir($this->workingDir);

		// Set our working info
		$this->workingFile = basename($this->GetWorkingFile()) . '.' . $this->tmpExtension;

		// Start our phar file.
		$this->pd = $this->PharData(
			$this->workingFile,
			FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS,
			null,
			$this->extension
		);

		// Run a iterator of another iterator with a filter that has a iterator.
		$this->buildFromIterator($this->directory);

		// No files made it into the archive.
		if ($this->pd->count() === 0)
			throw new \ErrorException($this->dt->txt('attachment_transfer_no_find'), 0, E_ERROR, __FILE__, __LINE__);

		$this->convertToData();
		$this->physicalDownloadFile = $this->GetWorkingFile() . '.' . $this->getRealExtension();

		return $this;
	}

	/*
	 * Wrapper for PharData to handle errors with open_basedir.
	 *
	 * @param mixed ...$args All the standard arguments you can pass to phardata
	 * @return ?PharData A valid PharData object is returned if valid, null is returned otherwise.
	*/
	protected function PharData(...$args): ?PharData
	{
		// Safely build our phar, but handle a safe error with open_basedir restrictions.
		try
		{
			set_error_handler(static function ($severity, $message, $file, $line) {
				throw new \ErrorException($message, 0, $severity, $file, $line);
			});

			// Start our phar file.
			$this->pd = new PharData(...$args);
			return $this->pd;
		}
		catch (Exception $e)
		{
			if (strpos($e->getMessage(), 'open_basedir') == false)
				throw new \ErrorException($e->getMessage(), 0, $e->getSeverity(), $e->getFile(), $e->getLine());
		}
		finally
		{
			restore_error_handler();
		}

		return null;
	}

	/*
	 * Usinga a iterator, we build a list of files we will compress, skipping directories.
	 * This logic does skip empty directories.
	 * This will attempt to exclude files matching a direct name match and wildcards.
	 * Upon a successful match, matches are automatically added to the phardata file.
	 *
	 * @param string $directory Directory we will scan for all matching files.
	 * @return void No data is returned, howerver our PharData object is updated.
	*/
	protected function buildFromIterator(string $directory): void
	{
		$filter = function ($file, $key, $iterator) use ($directory) {
			// Simple is directory or exact matches.
			if ($iterator->hasChildren() && !in_array($file->getFilename(), $this->exclusions))
				return true;

			// More complex wildcard matches or sub directories. Get a base directory, then run through all excludes to see if any more complex patterns match.
			$workingDirectory = $file->getPath();
			if (0 === strpos($workingDirectory, $directory . DIRECTORY_SEPARATOR))
				$workingDirectory = substr($workingDirectory, strlen($directory . DIRECTORY_SEPARATOR));
			foreach ($this->exclusions as $e)
				if (fnmatch($e, $workingDirectory . DIRECTORY_SEPARATOR . $file->getFilename()))
					return false;

			// Otherwise, only include this if its a file.
			return $file->isFile();
		};

		$this->pd->buildFromIterator(
			new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator(
						$directory,
						RecursiveDirectoryIterator::SKIP_DOTS
					),
					$filter
				)
			),
			$directory
		);
	}
	
	/*
	 * Convert the phar archive to a valid archive file.
	 *
	 * @return ?PharData PharData object is returned if successful, null if a error occurs.
	*/
	protected function convertToData(): ?PharData
	{
		// One more sanity check, Phar doesn't do overwrite
		if (file_exists($this->GetWorkingFile() . '.' . $this->getRealExtension()))
			unlink($this->GetWorkingFile() . '.' . $this->getRealExtension());

		// Safely build our file, but handle a safe error with open_basedir restrictions.
		try
		{
			set_error_handler(static function ($severity, $message, $file, $line) {
				throw new \ErrorException($message, 0, $severity, $file, $line);
			});

			return $this->pd->convertToData(
				$this->extension,
				$this->compression,
				$this->getRealExtension()
			);
		}
		catch (BadMethodCallException $e)
		{
				throw new \ErrorException($e->getMessage(), 0, E_ERROR, $e->getFile(), $e->getLine());
		}
		catch (Exception $e)
		{
			if (strpos($e->getMessage(), 'open_basedir') == false)
			{
				$this->cleanupArchives();
				throw new \ErrorException($e->getMessage(), 0, $e->getSeverity(), $e->getFile(), $e->getLine());
			}
		}
		finally
		{
			restore_error_handler();
		}

		return null;
	}

	/*
	 * Get the real extension we are wanting.
	 *
	 * @return string Our extension we are using.
	*/
	protected function getRealExtension(): string
	{
		return $this->extensionMap[$this->extension] ?? 'tar';
	}
}