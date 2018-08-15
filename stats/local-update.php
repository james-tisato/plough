<?php
	namespace plough\stats;
    require_once("local-wp-mock.php");
	require_once("updater.php");
	
	$updater = new Updater();
    $updater->update_stats();
?>