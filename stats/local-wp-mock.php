<?php
	namespace plough\stats;
    use plough\log;

    require_once(__DIR__ . "/../logger.php");

    // Constants
    define("WP_PLUGIN_DIR", "../..");

    // Wordpress functions
    function do_action($tag, $arg = '')
    {
        log\info("Mock WP do_action call with tag [$tag]");
    }
?>
