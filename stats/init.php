<?php
	namespace plough;
	
	require_once("update-stats.php");
	
	const UPDATE_HOOK_NAME = "plough_update_stats";
	const UPDATE_FUNCTION = __NAMESPACE__ . "\\update_stats";
	
	function init_stats()
	{
	    add_action(UPDATE_HOOK_NAME, UPDATE_FUNCTION);
	}
	
	function activate_stats()
	{
	    if (!wp_next_scheduled ("plough_update_stats"))
	    {
	        wp_schedule_event(time(), "quarterhourly", "plough_update_stats");
	    }
	}
	
	function deactivate_stats()
	{
		wp_clear_scheduled_hook("plough_update_stats");
	}
?>