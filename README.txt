This tool is to help developers working with hooks only customizations.

This gives a popup window for you to work with a package to do actions such as:
	- Reinstall hooks (adds/replaces matching hooks) as defined in the packages install action
	- Remove hooks (removes hooks as defined in the packages uninstall action
	- Pushes files out as per the packages install action
	- Pulls files in as per the packages install action
	- Compress customization into tgz (tar with gzip) and zip

This is intended for development purposes, not production uses.
This customization is intended to only be used with customizations that do not modify SMF sources (boardmod or xml) and are hook only.
To use this, your customization must be in the folder format, not in a compressed archive (.tar.gz or .zip) inside the Packages folder.
Extended information on how to use this tool can be found here: https://github.com/jdarwood007/smfmod_devtools/wiki

