<?php
/*
Plugin Name: Domainobot
Plugin URI: http://domainobot.com
Description: A simple whois utility plugin that keeps track of your domains and notifies you when renewal is due
Author: Skyline Design Ltd
Version: 1.0
Author URI: http://skylinedesign.co.ke/martians
License: GPL2
*/

//	include lookup classes
include( 'whois.query.php' );

class Domainobot {

	function __construct() {
		$this->get_global_settings();
		$this->setup_actions();
	}

	// Get global settings and cached dates
	function get_global_settings () {
		$this->options = get_option( 'domainobot_options' );
	}

	function setup_actions() {
		// domain activation hook
		register_activation_hook( __FILE__, array( $this, 'domainobot_activation') );

		//	hook up dashboard widget
		add_action( 'wp_dashboard_setup', array( $this, 'domainobot_add_dashboard_widget' ) );

		// 	hook for displaying current domain on the right, if needed
		if ( $this->options['domains']['show_current'] == 1 ) {
			add_action( 'admin_notices', array( $this, 'domainobot_current_domain' ) );
		}

		// daily update cron
		add_action( 'domainobot_whois_update', array( $this, 'domainobot_update_whois_daily') );

		// clean up after deactivation
		register_deactivation_hook( __FILE__, 'domainobot_deactivation' );

		// clean up after deletion
		register_uninstall_hook( __FILE__, 'domainobot_deletion' );

		//	hook up the css
		add_action( 'admin_print_styles', array( $this, 'domainobot_css' ) );

		//	hook up options page
		add_action( 'admin_menu', array( $this, 'domainobot_options' ) );
	}


	// display current domain at the top right
	public function domainobot_current_domain() {

		$cache = $this->options['domains']['current_expiry'];

		// $domain = 'put_your_test_domain_here_and_uncomment';
		if ( $cache == '' || $cache == '1st January 1970' ) {
			$domain = str_replace( "www.", "", $_SERVER['HTTP_HOST'] );
			$domain_status = new DomainStatus( $domain );
			$cache = $this->options['domains']['current_expiry'] = $domain_status->expiry_date;
			update_option( 'domainobot_options', $this->options );
			if ( $cache == '1st January 1970' ) {
				$cache = 'unknown';
			}
		}

		echo '<p id="domainobot-bar" class="' . $domain_status->highlight_class . '">Domain renewal: ' . esc_html( $cache ) . '</p>';
	}


	/**	Cron jobs */
	//	domain activation hook
	public function domainobot_activation() {
		wp_schedule_event( current_time( 'timestamp' ), 'daily', 'domainobot_whois_update' );
		// default option values
		$this->options = array(
							'countdown' => 30,
							'domains' => array(
								'show_current' => 1,
								'current_expiry' => '',
								'listed' => array(),
								'expiry_dates' => array(),
								'classes' => array()
							)
						);
		// create database field then store
		update_option( 'domainobot_options', $this->options );
	}

	// daily update cron
	public function domainobot_update_whois_daily() {
		// run every 24hrs
		$domain_status = new DomainStatus();
		$this->options['domains']['current_expiry'] = $domain_status->expiry_date;
		update_option( 'domainobot_options', $this->options );
	}


	/**	Cleaning up */
	//	clean up after deactivation clear cron
	public function domainobot_deactivation() {
		wp_clear_scheduled_hook( 'domainobot_whois_update' );
		// delete_option( 'domainobot_options' );
	}

	//	clean up after deletion (clear db values)
	public function domainobot_deletion() {
		delete_option( 'domainobot_options' );
	}

	//	css file
	public function domainobot_css() {
		wp_register_style( 'domainobot-style', plugins_url( 'assets/domainobot.css', __FILE__ ) );
		wp_enqueue_style( 'domainobot-style' );
	}

	//	options page
	function domainobot_options_page() { ?>
		<div class="wrap">
			<?php screen_icon(); ?>
			<h2>Domainobot Settings</h2>
			<?php
			if ( isset( $_POST['Submit'] ) ) {
				$domains_listed_saved = $_POST["domainobot_listed"];
				$this->cache_domains( $domains_listed_saved );
				$this->options['domains']['show_current'] = intval( $_POST["domainobot_show_current"] );
				$this->options['countdown'] = intval( $_POST["domainobot_countdown"] );
				update_option( 'domainobot_options', $this->options ); ?>
				<div class="updated">
					<p><strong><?php _e( 'Options saved.', 'mt_trans_domain' ); ?></strong></p>
				</div>
			<?php } $domains_list = implode( "\n", $this->options['domains']['listed'] ); ?>

			<form method="post" name="options" action="">
			<br />
			<table width="100%" class="form-table">
				<tr>
					<th scope="row">Domains</th>
					<td>
						<p>List of domains you'd like to monitor on the Dashboard. Place each on a new line.</p>
						<p><textarea id="domainobot_listed" name="domainobot_listed" rows="5" cols="50"><?php echo esc_textarea( $domains_list ); ?></textarea></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Current domain</th>
					<td>
						<p>
							<input type="checkbox" id="domainobot_show_current" name="domainobot_show_current" value="1" <?php checked( true, $this->options['domains']['show_current'] ); ?> />
							<label for="domainobot_show_current">Show current domain (top right)</label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Days left</th>
					<td>
						<p>Highlight domains that have the following number of days left before they expire.</p>
						<p><input type="number" id="domainobot_countdown" name="domainobot_countdown" value="<?php echo esc_html( $this->options['countdown'] ); ?>" min="0" max="90" /></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<input type="submit" name="Submit" value="Update" class="button-primary" />
			</p>
			</form>
		</div>

	<?php }

	//	add options
	function domainobot_options() {
		add_options_page( 'Domainobot Settings', 'Domainobot', 'administrator', __FILE__, array( $this, 'domainobot_options_page' ) );
	}

	//	dashboard widget output
	function domainobot_dashboard_widget() {
		// retrieve option
		$domainobot_listed = $this->options['domains']['listed'];
		if ( $domainobot_listed != NULL ) {

			echo '<table id="domainobot-table" width="100%" class="form-table" cellpadding="1px">';

			for ( $i = 0; $i < count( $domainobot_listed ); $i++ ) {
				
				$domains = $this->options['domains'];
				
				if ( $domains['classes'][$i] == 'soon' ) {
					$days_left = $this->countdown( $domains['expiry_dates'][$i] );
					$hover_info = $days_left . ' days';
				} elseif ( $domains['classes'][$i] == 'expired' ) {
					$hover_info = 'expired';
				} else {
					$hover_info = '';
				}
				
				if ( $domains['expiry_dates'][$i] == '1st January 1970' ) {
					$domains['expiry_dates'][$i] = "unknown";
				}

				echo	'<tr class="'. $domains['classes'][$i] .'">
							<th scope="row"><a href="'. esc_url( $domains['listed'][$i] ) .'">' . esc_html( $domains['listed'][$i] ) . '</a></th>
							<td>' . esc_html( $domains['expiry_dates'][$i] ) . ' <span>' . $hover_info . '</span></td>
						</tr>';
			}

			echo '</table>';

		} else {
			echo 'Add your list of domains on the <a href="' . menu_page_url( 'domainobot/domainobot.php', false ) . '">settings page</a>.';
		}

	}

	//	add dashboard widget
	function domainobot_add_dashboard_widget() {
		wp_add_dashboard_widget( 'domainobot_dashboard_widget', 'Renewal Tracker <small>- Domainobot &trade;</small>', array( $this, 'domainobot_dashboard_widget') );
	}

	// validate domains
	function validate_domain( $domain ) {
		if ( ! empty( $domain ) && $domain != '' ) {

			$domain = strtolower( trim( $domain ));
			$domain = preg_replace( '/^http:\/\//i', '', $domain );
			$domain = preg_replace( '/^www\./i', '', $domain );
			$domain = explode( '/', $domain );
			$domain = trim( $domain[0] );

			if ( preg_match( '/^([A-Z0-9][A-Z0-9_-]*(?:\.[A-Z0-9][A-Z0-9_-]*)+):?(\d+)?\/?/i', $domain ) ) {
			     return $domain;
			} else {
				return FALSE;
			}
		}
	}
	
	// cache dates, valid domains, and css classes
	function cache_domains( $domains_listed ) {
		$domain_array = explode( "\n", $domains_listed );
		if ( $domain_array != NULL ) {
			$domains = array();
			foreach ( $domain_array as $domain ) {
				if ( $this->validate_domain( $domain ) ) {
					$domains[] = $domain;
				}
			}
			
			$this->options['domains']['listed'] = $domains;
			
			for ( $i = 0; $i < count( $domains ); $i++ ) {
				$domain_status = new DomainStatus( $domains[$i] );
				
				$this->options['domains']['expiry_dates'][$i] = $domain_status->expiry_date;

				if ( $domain_status->highlight_class == 'expired' ) {
					$this->options['domains']['classes'][$i] = 'expired';
				} elseif ( $domain_status->highlight_class == 'soon' ) {
					$this->options['domains']['classes'][$i] = 'soon';
				} else {
					$this->options['domains']['classes'][$i] = '';
				}
			}
		}
	}
	
	// calculate domain countdowns
	function countdown( $expiry_date ) {
		$unix_expiry_date = strtotime( $expiry_date );
		$days_left = intval( ( $unix_expiry_date - time() ) / ( 60 * 60 * 24 ) );
		return $days_left;
	}
	
}

$domainobot = new Domainobot();
