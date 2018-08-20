<?php
	namespace plough\stats;
    
    use plough\log;
    
    require_once("config.php");
    require_once("local-wp-mock.php");
	require_once("updater.php");
    require_once(__DIR__ . "/../logger.php");
    
    $config = Config::fromXmlFile("config/local-test.xml");
    
    log\init();
    log\info("Initialising local update test run");
	
	$updater = new Updater($config);
    $updater->update_stats();
?>