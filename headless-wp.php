<?php
/*
Plugin Name: Headless WP
Description: This plugin was created to optimize Wordpress for use with SPA frameworks like react, vue.js, angular, svelte etc.
Plugin URI: https://github.com/rowphant/WP-Headless
Version: 0.0.2
Requires at least: 6.0
License: MIT
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.0
Author: Robert Metzner
*/

// Verhindert den direkten Zugriff auf die Datei
if (!defined('ABSPATH')) {
    exit;
}

// Update Checker
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/rowphant/Headless-WP/',
	__FILE__,
	'headless-wp'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('master');

// Einbinden der Klassen
require_once plugin_dir_path(__FILE__) . 'includes/class-api-user-registration.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-user-confirmation.php';

// Initialisierung der Klassen
function run_hwp() {
    $hwp_user_register = new HWP_User_Register();
    $hwp_user_confirmation = new HWP_User_Confirmation();
}

add_action('plugins_loaded', 'run_hwp');