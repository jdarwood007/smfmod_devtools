<?php
/**
 * Template for DevTools.
 * @package DevTools
 * @author SleePy <sleepy @ simplemachines (dot) org>
 * @copyright 2022
 * @license 3-Clause BSD https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0
 */

/* The wrapper upper template */
function template_devtools_above()
{
	global $context, $txt;

	echo '
	<div id="devtools_container" class="scrollable">';

	if (!empty($context['saved_successful']))
		echo '
		<div id="devtool_success" class="infobox">', $context['saved_successful'], '</div>';

	template_button_strip($context['devtools_buttons']);

	echo '
	<hr>';
}

/* The wrapper lower template */
function template_devtools_below()
{
	echo '
	</div><!-- devtools_container -->';
}

/* This just calls the template for showing a list on our packages */
function template_packagesIndex()
{
	template_show_list('packages_lists_modification');
}

/* This just calls the template for showing data for syncing files */
function template_FilesSyncIn()
{
	template_show_list('syncfiles_list');
}

/* This just calls the template for showing data for syncing files */
function template_FilesSyncOut()
{
	template_show_list('syncfiles_list');
}

/* This just calls the template for showing a list on our hooks */
function template_hooksIndex()
{
	template_show_list('hooks_list');
}