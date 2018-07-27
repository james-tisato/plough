<?php
/*
Plugin Name:	Plough
Description:	Provides Plough-specific features like stats generation
Version:		0.0.2
Author:			James Tisato
*/

require_once("stats/init.php");

function plough_activate()
{
	plough\activate_stats();
}

function plough_deactivate()
{
	plough\deactivate_stats();
}

register_activation_hook(__FILE__, "plough_activate");
register_deactivation_hook(__FILE__, "plough_deactivate");
?>