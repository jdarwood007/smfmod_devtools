<?php

/**
 * The class for DevTools File Phar Tgz.
 * @package DevTools
 * @author SleePy <sleepy @ simplemachines (dot) org>
 * @copyright 2023
 * @license 3-Clause BSD https://opensource.org/licenses/BSD-3-Clause
 * @version 1.1
*/
final class DevToolsFilePharTgz Extends DevToolsFilePharBase implements DevToolsFileInterface
{
	// Set our extension to Tar
	protected $extension = Phar::TAR;

	// Compress our tar with GZ
	protected $compression = Phar::GZ;
}