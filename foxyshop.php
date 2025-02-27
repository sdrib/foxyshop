<?php /*

**************************************************************************
Plugin Name: FoxyShop
Plugin URI: https://www.foxy-shop.com/
Description: FoxyShop is a full integration for FoxyCart and WordPress, providing a robust shopping cart and inventory management tool.
Author: SparkWeb Interactive, Inc.
Version: 4.9.3
Author URI: https://www.foxy-shop.com/

**************************************************************************

Copyright (C) 2015 SparkWeb Interactive, Inc.

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

**************************************************************************

Thanks you for using this plugin. Please see https://www.foxy-shop.com/ for
installation instructions and lots of helpful advice on how to get
the most out of FoxyShop.

**************************************************************************/

//Exit if not called in proper context
if (!defined('ABSPATH')) exit();

//Setup Plugin Variables
define('FOXYSHOP_VERSION', "4.9.3");
define('FOXYSHOP_DIR', substr(plugin_dir_url(__FILE__),0, strlen(plugin_dir_url(__FILE__))-1) );
define('FOXYSHOP_PATH', substr(plugin_dir_path(__FILE__),0, strlen(plugin_dir_path(__FILE__))-1) );
define('FOXYSHOP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FOXYSHOP_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('FOXYSHOP_PRODUCTS_SLUG')) define('FOXYSHOP_PRODUCTS_SLUG', 'products');
if (!defined('FOXYSHOP_PRODUCT_CATEGORY_SLUG')) define('FOXYSHOP_PRODUCT_CATEGORY_SLUG', 'product-cat');
if (!defined('FOXYSHOP_PRODUCT_NAME_SINGULAR')) define('FOXYSHOP_PRODUCT_NAME_SINGULAR', __('Product', 'foxyshop'));
if (!defined('FOXYSHOP_PRODUCT_NAME_PLURAL')) define('FOXYSHOP_PRODUCT_NAME_PLURAL', __('Products', 'foxyshop'));
if (!defined('FOXYSHOP_URL_BASE')) define('FOXYSHOP_URL_BASE', '');
if (!defined('FOXYSHOP_PRODUCT_SITEMAP_SLUG')) define('FOXYSHOP_PRODUCT_SITEMAP_SLUG', 'product-sitemap');
if (!defined('FOXYSHOP_API_ENTRIES_PER_PAGE')) define('FOXYSHOP_API_ENTRIES_PER_PAGE', 50);
if (!defined('FOXYSHOP_JQUERY_VERSION')) define('FOXYSHOP_JQUERY_VERSION', '1.11.2');
if (!defined('FOXYSHOP_DECIMAL_PLACES')) define('FOXYSHOP_DECIMAL_PLACES', 2);
load_plugin_textdomain('foxyshop', 0, dirname(plugin_basename(__FILE__)).'/languages/');
$foxycart_version_array = array('2.0' => '2.0', '1.1' => '1.1', '1.0' => '1.0', '0.7.2' => '0.7.2', '0.7.1' => '0.7.1', '0.7.0' => '0.7.0');
$google_product_field_names = array('google_product_category', 'mpn', 'gtin', 'identifier_exists', 'brand', 'condition', 'age_group', 'gender', 'color', 'size', 'material', 'pattern');

//Setup Admin Functions
require(FOXYSHOP_PATH . '/adminfunctions.php');
require(FOXYSHOP_PATH . '/adminajax.php');

//Set FoxyShop Settings Array
$foxyshop_settings = get_option("foxyshop_settings");
if (!is_array($foxyshop_settings)) {
	$foxyshop_settings = foxyshop_activation(); //Runs for the first time
} elseif ($foxyshop_settings['foxyshop_version'] != FOXYSHOP_VERSION) {
	$foxyshop_settings = foxyshop_activation(); //Checks for Old Plugin Version and Perform Upgrade
}
$foxyshop_category_sort = get_option('foxyshop_category_sort');
if (!is_array($foxyshop_category_sort)) $foxyshop_category_sort = array();

//Sets the Locale for Currency Internationalization
setlocale(LC_MONETARY, $foxyshop_settings['locale_code']);
$foxyshop_localsettings = localeconv();
if ($foxyshop_localsettings['int_curr_symbol'] == "") setlocale(LC_MONETARY, 'en_US');

//Flushes Rewrite Rules if Structure Has Changed
add_action('init', 'foxyshop_check_rewrite_rules', 99);

//Widgets and Shortcodes support
include(FOXYSHOP_PATH . '/widgetcode.php');
include(FOXYSHOP_PATH . '/shortcodesettings.php');

//Load Admin Scripts and Styles
if (is_admin()) {
	add_action('admin_enqueue_scripts', 'foxyshop_load_admin_scripts');

//Load FoxyShop Scripts and Styles on Public Site
} else {
	add_action('wp_enqueue_scripts', 'foxyshop_load_public_scripts');
	// Needed for backwards compatibility
	add_action('init', 'foxyshop_load_site_scripts', 1);
}

//Setup Wizard
include(FOXYSHOP_PATH . '/setup-page.php');

//Custom Post Type and Taxonomy
include(FOXYSHOP_PATH . '/customposttype.php');

//Custom Field Bulk Editor Plugin Support
include(FOXYSHOP_PATH . '/bulkeditor.php');

//Custom Product Sorting
include(FOXYSHOP_PATH . '/customsorting.php');

//Custom Category Sorting
include(FOXYSHOP_PATH . '/categorysorting.php');

//FoxyCart API Feeds
if ($foxyshop_settings['domain']) {

	//Orders
	include(FOXYSHOP_PATH . '/orders.php');

	//Customers
	include(FOXYSHOP_PATH . '/customers.php');

	//Subscriptions
	if ($foxyshop_settings['enable_subscriptions']) {
		include(FOXYSHOP_PATH . '/subscriptions.php');
	}
}

//Inventory Management
if ($foxyshop_settings['manage_inventory_levels']) {
	include(FOXYSHOP_PATH . '/inventory.php');
}

//Generate Product Feed
if ($foxyshop_settings['google_product_support']) {
	include(FOXYSHOP_PATH . '/googleproductfeed.php');
}

//Tools Page
include(FOXYSHOP_PATH . '/tools-page.php');

//Settings Page
include(FOXYSHOP_PATH . '/settings-page.php');

//Single Sign On
if ($foxyshop_settings['enable_sso']) {
	include(FOXYSHOP_PATH . '/sso.php');
}

//Display Settings Link on Plugin Screen
add_filter('plugin_action_links', 'foxyshop_plugin_action_links', 10, 2);

//Frontend Helper Functions
include(FOXYSHOP_PATH . '/helperfunctions.php');

//Template Redirect (reference files are in /plugins/foxyshop/themefiles)
include(FOXYSHOP_PATH . '/templateredirect.php');

//Plugin Activation Functions
register_activation_hook(__FILE__, 'foxyshop_activation');
register_deactivation_hook( __FILE__, 'foxyshop_deactivation');
