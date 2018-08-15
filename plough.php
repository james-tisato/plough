<?php
/*
Plugin Name:	Plough
Description:	Provides Plough-specific features like stats generation
Version:		0.0.2
Author:			James Tisato
*/

require_once("stats/init.php");

function init()
{
    plough\stats\init();
}

function plough_activate()
{
	plough\stats\activate();
}


function plough_deactivate()
{
	plough\stats\deactivate();
}

register_activation_hook(__FILE__, "plough_activate");
register_deactivation_hook(__FILE__, "plough_deactivate");

init();
?>