<?php
	namespace plough\stats;
    require_once("config.php");
    require_once("local-wp-mock.php");
	require_once("updater.php");
	
    $config = new Config();
	$updater = new Updater($config);
    $updater->update_stats();
?>