<?php
/**
 * Plugin Name:       Feedback Pins – QA & Client Feedback
 * Plugin URI:        https://github.com/avangardism-alex/feedback-pins
 * Description:       Pin feedback right on your pages. Clients click any element, write a note, and the whole team sees it on a shared board, with screenshots, Slack notifications and a Markdown export. Free, no account, everything stays in your WordPress.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            AVANGARDISM
 * Author URI:        https://avangardism.com/?utm_source=feedback-pins&utm_medium=plugin&utm_campaign=plugin-header
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       feedback-pins
 * Domain Path:       /languages
 *
 * @package FeedbackPins
 */

defined( 'ABSPATH' ) || exit;

define( 'FPINS_VERSION', '1.0.0' );
define( 'FPINS_FILE', __FILE__ );
define( 'FPINS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FPINS_URL', plugin_dir_url( __FILE__ ) );

require_once FPINS_DIR . 'includes/notes.php';
require_once FPINS_DIR . 'includes/screenshots.php';
require_once FPINS_DIR . 'includes/slack.php';
require_once FPINS_DIR . 'includes/rest.php';
require_once FPINS_DIR . 'includes/front.php';
require_once FPINS_DIR . 'includes/admin.php';

/**
 * Loads the translations bundled in `languages/`, for sites that install the
 * plugin from GitHub. Sites installed from WordPress.org get the community
 * translations anyway, which take precedence.
 */
function fpins_load_textdomain() {
	load_plugin_textdomain( 'feedback-pins', false, dirname( plugin_basename( FPINS_FILE ) ) . '/languages' );
}
add_action( 'init', 'fpins_load_textdomain', 0 );
