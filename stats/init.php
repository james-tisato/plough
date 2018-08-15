<?php
	namespace plough\stats;
	
	require_once("updater.php");
	
	const UPDATE_HOOK_NAME = "plough_update_stats";
	const UPDATE_FUNCTION = "update_stats";
	
	function init()
	{
        $updater = new Updater();
        
	    add_action(UPDATE_HOOK_NAME, array($updater, UPDATE_FUNCTION);
	}
	
	function activate()
	{
	    if (!wp_next_scheduled ("plough_update_stats"))
	    {
	        wp_schedule_event(time(), "quarterhourly", "plough_update_stats");
	    }
	}
	
	function deactivate()
	{
		wp_clear_scheduled_hook("plough_update_stats");
	}
?>