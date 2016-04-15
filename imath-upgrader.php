<?php
/**
 * Plugin Name: imath Upgrader
 * Plugin URI: http://imathi.eu/tag/thaim/
 * Description: A utility plugin to run upgrade routines
 * Version: 1.0.0-alpha
 * Author: imath
 * Author URI: http://imathi.eu/
 * License: GPLv2
 * Text Domain: imath-upgrader
 * Domain Path: /languages/
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Main Class
 */
class Imath_Upgrader {
	/**
	 * Main instance of the plugin
	 */
	protected static $instance = null;

	/**
	 * Initialize the plugin
	 */
	private function __construct() {
		$this->setup_globals();
		$this->setup_actions();
	}

	/**
	 * Return an instance of this class.
	 */
	public static function start() {

		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Setup plugin's globals.
	 */
	private function setup_globals() {

		$this->version       = '1.0.0';

		/** Paths ***********************************************/

		$this->file          = __FILE__;
		$this->basename      = plugin_basename( $this->file );

		// Define a global that we can use to construct file paths throughout the component
		$this->plugin_dir    = plugin_dir_path( $this->file );

		// Define the plugin url
		$this->plugin_url    = plugin_dir_url( $this->file );

		// Define a global that we can use to construct url to the javascript scripts needed
		$this->plugin_js     = trailingslashit( $this->plugin_url . 'js' );

		// Define a global that we can use to construct url to the css needed
		$this->plugin_css    = trailingslashit( $this->plugin_url . 'css' );

		$this->items = array();
	}

	private function setup_actions() {
		// Textdomain
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Admin page
		add_action( 'admin_menu', array( $this, 'setup_upgrader_screen' ), 100 );

		// Ajax Action.
		add_action( 'wp_ajax_imath_upgrader', array( $this, 'do_upgrade_task' ) );
	}

	/**
	 * Setup the Upgrader screen if needed.
	 */ 
	public function setup_upgrader_screen() {
		$items       = $this->get_tasks();
		$this->tasks = array();

		if ( empty( $items ) ) {
			return;
		}

		// Validate items & tasks
		foreach ( $items as $key_item => $item ) {
			if ( empty( $item['type'] ) ) {
				continue;
			}

			if ( 'plugin' === $item['type'] ) {
				if ( ! is_plugin_active( $key_item ) ) {
					unset( $items[ $key_item ] );
					continue;
				}
			} elseif ( 'theme' === $item['type'] ) {
				if ( $key_item !== get_stylesheet() ) {
					unset( $items[ $key_item ] );
					continue;
				}
			} else {
				unset( $items[ $key_item ] );
				continue;
			}

			foreach ( $item['tasks'] as $version => $list ) {
				if ( version_compare( $version, $item['db_version'], '>' ) ) {
					if ( empty( $this->tasks[ $key_item ] ) ) {
						$this->tasks[ $key_item ] = $list;
					} else {
						$this->tasks[ $key_item ] = array_merge( $this->tasks[ $key_item ], $list );
					}
				}
			}
		}

		// Stop if everything is up to date
		if ( empty( $this->tasks ) ) {
			return;
		}

		// Some plugins or themes need an upgrade.
		$this->upgrade_page = add_dashboard_page(
			__( 'Database Upgrade',  'imath-upgrader' ),
			__( 'Database Upgrade',  'imath-upgrader' ),
			'manage_options',
			'imath-upgrader',
			array( $this, 'upgrade_screen' )
		);

		// Add a notice and register style and script
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_script' ) );
	}

	/**
	 * Are we on the upgrader screen ?
	 */ 
	public function is_upgrader_screen() {
		if ( empty( $this->upgrade_page ) ) {
			return false;
		}

		$current_screen = get_current_screen();

		if ( empty( $current_screen->id ) ) {
			return false;
		}

		return $this->upgrade_page === $current_screen->id;
	}
	/**
	 * Display a generic notic
	 */
	public function notice() {
		if ( $this->is_upgrader_screen() ) {
			return;
		}

		printf( '<div id="message" class="fade error"><p>%1$s %2$s</p></div>',
			sprintf( _n( '%s item needs to perform an upgrade.', '%s items need to perform an upgrade.', count( $this->tasks ), 'imath-upgrader' ), count( $this->tasks ) ),
			sprintf( __( 'Make sure to backup your database before lauching <a href="%s">the upgrader</a>.', 'imath-upgrader' ), esc_url( add_query_arg( 'page', 'imath-upgrader', admin_url( 'index.php' ) ) ) )
		);
	}

	public function register_script() {
		if ( $this->is_upgrader_screen() ) {
			wp_register_script(
				'imath-upgrader-js',
				$this->plugin_js . 'script.js',
				array( 'jquery', 'json2', 'wp-backbone' ),
				$this->version,
				true
			);

			wp_register_style(
				'imath-upgrader-style',
				$this->plugin_css . 'style.css',
				array( 'dashicons' ),
				$this->version
			);
		}
	}

	public function upgrade_screen() {
		$tasks = array();

		foreach ( $this->tasks as $key_item => $item_tasks ) {
			foreach ( $item_tasks as $item_task ) {
				if ( ! empty( $item_task['count'] ) && is_callable( $item_task['count'] ) ) {
					$item_task['count'] = call_user_func( $item_task['count'] );

					// If nothing needs to be ugraded, remove the task.
					if ( ! empty( $item_task['count'] ) ) {
						$tasks[ $item_task['callback'] ] = $item_task;
						$tasks[ $item_task['callback'] ]['message'] = sprintf( $item_task['message'], $item_task['count'] );
					}
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Database Upgrade', 'imath-upgrader' ); ?></h1>
			<div id="message" class="fade updated imath-upgrader-hide">
				<p><?php esc_html_e( 'Thank you for your patience, your website is upgraded!', 'imath-upgrader' ); ?></p>
			</div>

			<div id="imath-upgrader"></div>

			<?php if ( ! empty( $tasks ) )  :
				// Add The Upgrader UI
				wp_enqueue_style ( 'imath-upgrader-style' );
				wp_enqueue_script ( 'imath-upgrader-js' );
				wp_localize_script( 'imath-upgrader-js', 'Imath_Upgrader', array(
					'tasks' => array_values( $tasks ),
					'nonce' => wp_create_nonce( 'imath-upgrader' ),
				) );
			?>
			<script type="text/html" id="tmpl-progress-window">
				<div id="{{data.id}}">
					<div class="task-description">{{data.message}}</div>
					<div class="imath-upgrader-progress">
						<div class="imath-upgrader-bar"></div>
					</div>
				</div>
			</script>
			<?php endif ;?>
		</div>
		<?php
	}

	public function do_upgrade_task() {
		$error = array(
			'message'   => __( 'The task could not process due to an error', 'imath-upgrader' ),
			'type'      => 'error'
		);

		if ( empty( $_POST['id'] ) || ! isset( $_POST['count'] ) || ! isset( $_POST['done'] ) ) {
			wp_send_json_error( $error );
		}

		// Add the action to the error
		$callback          = sanitize_key( $_POST['id'] );
		$error['callback'] = $callback;

		// Check nonce
		if ( empty( $_POST['_imath_upgrader_nonce'] ) || ! wp_verify_nonce( $_POST['_imath_upgrader_nonce'], 'imath-upgrader' ) ) {
			wp_send_json_error( $error );
		}

		// Check capability
		if ( ! current_user_can( 'manage_options' ) || ! is_callable( $callback ) ) {
			wp_send_json_error( $error );
		}

		$number = 20;
		if ( ! empty( $_POST['number'] ) ) {
			$number = (int) $_POST['number'];
		}

		$did = call_user_func_array( $callback, array( $number ) );

		wp_send_json_success( array( 'done' => $did, 'callback' => $callback ) );
	}

	public function get_tasks( $item = '', $version = '', $db_version = 0 ) {
		/**
		 * Filter here to populate your upgrade tasks
		 *
		 * @param array $value list of tasks to perform
		 *
		 * eg array( $plugin_or_theme_name => array(
		 * 		$type        string Is it a plugin or a theme ?
		 * 		$db_version  string The current database version (before the upgrade)
		 *		$tasks array (
		 * 			$callback  string The Upgrade routine
		 *			$count     int    The total number of items to upgrade
		 *			$message   string The message to display in the progress bar
		 *			$number    int    Number of items to upgrade per ajax request,
		 *      )
		 * ) )
		 *
		 */
		return (array) apply_filters( 'imath_upgrader_tasks', array() );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'imath-upgrader', false, trailingslashit( basename( $this->plugin_dir ) ) . 'languages' );
	}
}

function imath_upgrader() {
	return Imath_Upgrader::start();
}
add_action( 'plugins_loaded', 'imath_upgrader' );
