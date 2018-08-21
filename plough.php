<?php
/*
Plugin Name:	Plough
Description:	Provides Plough-specific features like stats generation
Version:		0.0.4
Author:			James Tisato
*/

use plough\log;

require_once("logger.php");
require_once("stats/init.php");

function init()
{
    log\init();
    plough\stats\init();
}

function plough_activate()
{
    log\info("Activating Plough plugin");
	plough\stats\activate();
}


function plough_deactivate()
{
    log\info("Deactivating Plough plugin");
	plough\stats\deactivate();
}

register_activation_hook(__FILE__, "plough_activate");
register_deactivation_hook(__FILE__, "plough_deactivate");

init();
?>