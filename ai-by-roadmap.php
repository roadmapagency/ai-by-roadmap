<?php
/**
 * Plugin Name:       AI by Roadmap
 * Description:       Surfaces ACF blocks, image search, and content-generation pipelines to LLMs through the WordPress Abilities API.
 * Version:           0.2.0
 * Requires PHP:      8.1
 * Requires at least: 6.9
 * Requires Plugins:  mcp-adapter, advanced-custom-fields-pro
 * Author:            Roadmap Agency Inc.
 * License:           GPL-2.0-or-later
 * Text Domain:       ai-by-roadmap
 */

declare(strict_types=1);

namespace Roadmap\AiByRoadmap;

if (! defined('ABSPATH')) {
    exit;
}

const VERSION    = '0.2.0';
const PLUGIN_DIR = __DIR__;
const PLUGIN_URL_BASE = __FILE__;

$autoloader_packages = __DIR__ . '/vendor/autoload_packages.php';
$autoloader_fallback = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloader_packages)) {
    require_once $autoloader_packages;
} elseif (file_exists($autoloader_fallback)) {
    require_once $autoloader_fallback;
} else {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>AI by Roadmap:</strong> Composer dependencies are not installed. Run <code>composer install</code> in the plugin directory.</p></div>';
    });
    return;
}

$action_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (file_exists($action_scheduler)) {
    require_once $action_scheduler;
}

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
}, 5);
