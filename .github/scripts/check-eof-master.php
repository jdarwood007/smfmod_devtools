<?php
// Stuff we will ignore.
$ignoreFiles = array(
	'\.github/',
);

$curDir = '.';
if (isset($_SERVER['argv'], $_SERVER['argv'][1]))
	$curDir = $_SERVER['argv'][1];

$foundBad = false;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($curDir, FilesystemIterator::UNIX_PATHS)) as $currentFile => $fileInfo)
{
	// Only check PHP
	if ($fileInfo->getExtension() !== 'php')
		continue;

	foreach ($ignoreFiles as $if)
		if (preg_match('~' . $if . '~i', $currentFile))
			continue 2;

	$result = trim(shell_exec('php .github/scripts/check-eof.php ' . $currentFile . ' 2>&1'));

	if (!preg_match('~Error:([^$]+)~', $result))
		continue;

	$foundBad = true;
	fwrite(STDERR, $result . "\n");
}

if (!empty($foundBad))
	exit(1);