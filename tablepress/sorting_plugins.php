<?php
/*
Plugin Name: TablePress Extension: DataTables Sorting plugins - modified for Plough
Plugin URI: https://tablepress.org/extensions/datatables-sorting-plugins/
Description: Custom Extension for TablePress to add more DataTables "Sorting plug-ins"
Version: 1.0
Author: Tobias Bäthge
Author URI: https://tobias.baethge.com/
*/

/*
 * See http://datatables.net/plug-ins/sorting/
 */

/*
 * Register necessary Plugin Filters.
 */
add_filter( 'tablepress_table_js_options', 'tablepress_enqueue_datatables_sorting_plugins_js_plough', 10, 3 );

/**
 * Enqueue JS files.
 *
 * @since 1.0
 *
 * @param array  $js_options    Current JS options.
 * @param string $table_id      Table ID.
 * @param array $render_options Render Options.
 * @return array Modified JS options.
 */
function tablepress_enqueue_datatables_sorting_plugins_js_plough( $js_options, $table_id, $render_options ) {
	$js_url = plugins_url( "jquery.datatables.sorting-plugins-plough.js", __FILE__ );
	wp_enqueue_script( 'tablepress-datatables-sorting-plugins-plough', $js_url, array( 'tablepress-datatables' ), '0.7', true );
	return $js_options;
}
