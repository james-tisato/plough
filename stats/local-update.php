<?php
	namespace plough\stats;
    require_once("config.php");
    require_once("local-wp-mock.php");
	require_once("updater.php");
	
	$updater = new Updater(Config::fromXmlFile("config/local-test.xml"));
    $updater->update_stats();
?>