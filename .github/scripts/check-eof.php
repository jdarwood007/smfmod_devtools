<?php
// Stuff we will ignore.
$ignoreFiles = array(
	'\.github/',
);

// No file? Thats bad.
if (!isset($_SERVER['argv'], $_SERVER['argv'][1]))
	fatalError('Error: No File specified' . "\n");

// The file has to exist.
$currentFile = $_SERVER['argv'][1];
if (!file_exists($currentFile))
	fatalError('Error: File does not exist' . "\n");

// Is this ignored?
foreach ($ignoreFiles as $if)
	if (preg_match('~' . $if . '~i', $currentFile))
		die;

// Less efficent than opening a file with fopen, but we want to be sure to get the right end of the file. file_get_contents
$file = fopen($currentFile, 'r');

// Error?
if ($file === false)
	fatalError('Error: Unable to open file ' . $currentFile . "\n");

// Seek the end minus some bytes.
fseek($file, -100, SEEK_END);
$contents = fread($file, 100);

// There is some white space here.
if (preg_match('~}\s+$~', $contents, $matches))
	fatalError('Error: End of File contains extra spaces in ' . $currentFile . "\n");
// It exists! Leave.
elseif (preg_match('~}$~', $contents, $matches))
	die();

// There is some white space here.
if (preg_match('~\';\s+$~', $contents, $matches))
	fatalError('Error: End of File Strings contains extra spaces in ' . $currentFile . "\n");
// It exists! Leave.
elseif (preg_match('~\';$~', $contents, $matches))
	die();

function fatalError($msg)
{
	fwrite(STDERR, $msg);
	die;
}