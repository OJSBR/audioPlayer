{**
 * plugins/generic/audioPlayer/templates/settings.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings of the audiobook player.
 *}
<script>
	$(function() {ldelim}
		$('#audioPlayerSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="audioPlayerSettings" method="post"
      action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="audioPlayerSettingsNotification"}

	<div id="audioPlayerSettingsDescription">
		{translate key="plugins.generic.audioPlayer.settings.description"}
	</div>

	{fbvFormArea id="audioPlayerSettingsArea"}
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" id="autoplayNext" checked=$autoplayNext
			            label="plugins.generic.audioPlayer.settings.autoplayNext"}
			{fbvElement type="checkbox" id="rememberPosition" checked=$rememberPosition
			            label="plugins.generic.audioPlayer.settings.rememberPosition"}
		{/fbvFormSection}
		{fbvFormSection title="plugins.generic.audioPlayer.settings.defaultSpeed" for="defaultSpeed"}
			{fbvElement type="select" id="defaultSpeed" from=$speedOptions selected=$defaultSpeed translate=false}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
