/**
 * Javascript for DevTools.
 * @package DevTools
 * @author SleePy <sleepy @ simplemachines (dot) org>
 * @copyright 2022
 * @license 3-Clause BSD https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0
 */

/* Load up some logic for devtools once we are ready */
$(document).ready(function() {
	/* Inject the dev tools as a button in the user menu */
	let devtools_menu = '<li>' +
			'<a href="' + smf_scripturl + '?action=devtools" id="devtools_menu_top"><span class="textmenu">' + txt_devtools_menu + '</span></a>' +
			'<div id="devtools_menu" class="top_menu scrollable" style="width: 90vw; max-width: 1200px; position: absolute; left: 0;"></div>' +
		'</li>';
	$('ul#top_info').append(devtools_menu);
	let dev_menu = new smc_PopupMenu();
	dev_menu.add('devtools', smf_scripturl + '?action=devtools');

	/* Ensures admin login works */
	$("div#devtools_menu").on("submit", "#frmLogin", {form: "div#devtools_menu #frmLogin"}, devtools_formhandler);

	/* Ensures the hooks form works */
	$("div#devtools_menu").on("submit", "#HooksList", {form: "div#devtools_menu #HooksList", frame: "div#devtools_container"}, devtools_formhandler);

	/* Fixes links on the popup to use ajax */
	$("div#devtools_menu").on("click", "a",  devtools_links);
});

/* Ensures admin login works */
function devtools_formhandler(e) {
	e.preventDefault();
	e.stopPropagation();

	let form = $(e.data.form ?? "div#devtools_menu #frmLogin");
	let formData = form.serializeArray();

	/* Inject the button/input that was clicked */
	formData.push({ name: e.originalEvent.submitter.name, value: e.originalEvent.submitter.value });

	$.ajax({
		url: form.prop("action") + ";ajax",
		method: "POST",
		headers: {
			"X-SMF-AJAX": 1
		},
		xhrFields: {
			withCredentials: typeof allow_xhjr_credentials !== "undefined" ? allow_xhjr_credentials : false
		},
		data: formData,
		success: function(data, status, xhr) {
			if (typeof(e.data) !== 'undefined' && typeof(e.data.frame) !== 'undefined' && e.data.frame.length > 0) {
				$(document).find(e.data.frame).html($(data).html());
			}
			else if (data.indexOf("<bo" + "dy") > -1) {
				document.open();
				document.write(data);
				document.close();
			}
			else if (data.indexOf("<form") > -1) {
				form.html($(data).html());
			}
			else if ($(data).find(".roundframe").length > 0) {
				form.parent().html($(data).find(".roundframe").html());
			}
			else {
				form.parent().html($(data).html());
			}

			($("div#devtools_menu").data("scrollable")).resize();
			checkSuccessFailPrompt(data);
		},
		error: function(xhr) {
			var data = xhr.responseText;
			if (data.indexOf("<bo" + "dy") > -1) {
				document.open();
				document.write(data);
				document.close();
			}
			else
				form.parent().html($(data).filter("#fatal_error").html());

			($("div#devtools_menu").data("scrollable")).resize();
			checkSuccessFailPrompt(data);
		}
	});

	return false;
}

/* Fixes links on the popup to use ajax */
function devtools_links(e) {
	// If we need to skip the popup window, don't do anything.
	if ($(this).attr('data-nopopup') && ($(this).attr('data-nopopup')) == "true")
		return;

	e.preventDefault();
	e.stopPropagation();

	let currentLink = e.currentTarget.href;
	let contentBox = $("div#devtools_menu .overview");

	$.ajax({
		url: currentLink + ";ajax",
		method: "GET",
		headers: {
			"X-SMF-AJAX": 1
		},
		xhrFields: {
			withCredentials: typeof allow_xhjr_credentials !== "undefined" ? allow_xhjr_credentials : false
		},
		success: function(data, status, xhr) {
			if (data.indexOf("<bo" + "dy") > -1) {
				document.open();
				document.write(data);
				document.close();
			}
			else
				contentBox.html(data);

			($("div#devtools_menu").data("scrollable")).resize();
			checkSuccessFailPrompt(data);
		},
		error: function(xhr) {
			var data = xhr.responseText;
			if (data.indexOf("<bo" + "dy") > -1) {
				document.open();
				document.write(data);
				document.close();
			}
			else
				contentBox.html($(data).filter("#fatal_error").html());

			($("div#devtools_menu").data("scrollable")).resize();
			checkSuccessFailPrompt(data);
		}
	});
}

/* If a success prompt shows up, fade it away */
function checkSuccessFailPrompt(data)
{
	if ($(data).find('#devtool_success').length > 0)
	{
		$("#devtool_success").fadeOut(2000, function() {
			$(this).remove();
			($("div#devtools_menu").data("scrollable")).resize();
		});
	}
}