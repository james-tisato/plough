<?php
	namespace plough;
	
	require_once("update-stats.php");
	
	const UPDATE_HOOK_NAME = "plough_update_stats";
	const UPDATE_FUNCTION = __NAMESPACE__ . "\\update_stats";
	
	function activate_stats()
	{
		add_action(UPDATE_HOOK_NAME, UPDATE_FUNCTION);
	}
	
	function deactivate_stats()
	{
		remove_action(UPDATE_HOOK_NAME, UPDATE_FUNCTION);
	}
?>