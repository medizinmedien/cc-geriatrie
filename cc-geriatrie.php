<?php
/*
Plugin Name:       Custom Code für geriatrie-online.at
Plugin URI:        https://github.com/medizinmedien/cc-geriatrie
Description:       Theme-unabh&auml;ngige Funktionalität f&uuml;r geriatrie-online.at wie z.B. individualisierter Mail-Absender, Privatsph&auml;re-Nachbesserungen, dynamische Menüpunkte für den Mitgliederbereich (bbPress, Foren) etc.
Version:           0.1
Author:            Frank St&uuml;rzebecher
*/

if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Additional menu entries for bbPress users after login.
 */
function ccgeri_members_nav( $items, $menu, $args ) {
	global $current_user;

	$user = $current_user->data->user_login;
	$user_profile_url = esc_url( home_url() . "/foren/nutzer/$user" );
	$profile_index = ccgeri_array_index_of( 'Mein Profil', $items );
	$logout_index = ccgeri_array_index_of( 'Abmelden', $items );
	$members_page_index = ccgeri_array_index_of( 'Mitgliederbereich', $items );

	if ( is_user_logged_in() ) {
		if ( $profile_index ) {
			// Replace custom menu URL
			$items[$profile_index]->url = $user_profile_url;
		}
		if ( $logout_index ) {
			// Inject Log out link
			$items[$logout_index]->url = wp_logout_url( home_url() . '/logout' );
		}
	} else {
		foreach ( array( $profile_index, $logout_index ) as $idx ) {
			if ( $idx )
				unset( $items[$idx] );
		}
		if ( $members_page_index )
			$items[$members_page_index]->url = '/wp-login.php';
	}

	return $items;
}
add_filter( 'wp_get_nav_menu_items', 'ccgeri_members_nav', 10, 3 );

/**
 * Get index number of an menu item from a menu item's title.
 *
 * @return integer Index of array with menu item objects | FALSE if not found.
 */
function ccgeri_array_index_of( $menu_title, $array ) {
	if ( strlen( $menu_title ) && is_array( $array ) && count( $array ) ) {
		for( $i = count( $array ) - 1; $i >= 0; $i-- ) {
			if ( is_object( $array[$i] ) && property_exists( $array[$i], 'title' ) && $array[$i]->title == $menu_title ) {
				// error_log($i . ' => ' . $array[$i]->title); // DEBUG
				return $i;
			}
		}
	}
	return false;
}

/**
 * Redirect non-admins to the members area of the site.
 */
function ccgeri_login_redirect( $redirect_to, $request, $user  ) {
	if ( is_array( $user->roles ) && in_array( 'administrator', $user->roles ) ) {
		return admin_url();
	} else {
		return home_url() . '/mitglieder/';
	}
}
add_filter( 'login_redirect', 'ccgeri_login_redirect', 10, 3 );


// Needed as long as page /mitglieder does not redirect to login page if user is not logged in.
function ccgeri_switch_mitgliederbereich_content( $content ) {
	if (is_user_logged_in() )
		return str_replace( '[bbp-login]', '', $content );
	else
		return str_replace( '[bbp-forum-index]', '', $content );
}
add_filter( 'the_content', 'ccgeri_switch_mitgliederbereich_content', 5 );


/**
 * Replace Login logo.
 */
function ccgeri_login_logo() {
	echo '<style type="text/css">
	#login h1 { height: 130px; }
	#login h1 a { background-image: url(/wp-content/uploads/2014/12/headway-imported-image.png) !important; width:auto; height:auto; background-size:auto; line-height:120px; }
	</style>';
}
add_action('login_head', 'ccgeri_login_logo');

/**
 * Replace Logo URL.
 */
function ccgeri_login_logo_url( $url ) {
	return home_url();
}
add_filter( 'login_headerurl', 'ccgeri_login_logo_url' );

/**
 * Replace Logo title.
 */
function ccgeri_login_logo_title( $title ) {
	return "Zur Startseite";
}
add_filter( 'login_headertitle', 'ccgeri_login_logo_title');

/**
 * Change mail_address and mail_from address in outgoing emails sent by wp_mail().
 */
function ccgeri_mail_from( $wp_mail ) {
	return "keine_antwort@geriatrie-online.at";
}
add_filter( 'wp_mail_from', 'ccgeri_mail_from' );

/**
 * Replace mail_from's display name.
 */
function ccgeri_mail_from_name( $wordpress ) {
        return "ÖGGG";
}
add_filter( 'wp_mail_from_name', 'ccgeri_mail_from_name' );


/**
 * Let Fail2ban play together with WordPress.
 */
add_action( 'wp_login_failed', 'ccgeri_set_statuscode_for_fail2ban' );
function ccgeri_set_statuscode_for_fail2ban( $username ) {

	// Make an exception for Medizin-Medien-Verlag
	if( $_SERVER['REMOTE_ADDR'] != '217.19.38.198' ) {
		// Fail2ban looks for it in nginx/access.log and nginx/ssl_access.log:
		status_header( 403 );
	}

	ccgeri_human_readable_log_entry( $username ); // /var/log/nginx/php_errors.log
	ccgeri_ui_warnAfter_beforeBlockSeconds( 3, 600 );
}

function ccgeri_human_readable_log_entry( $username ) {
	error_log( '-- ' . $_SERVER['REMOTE_ADDR'] . ' -- WP-Login failed for user "'
		. $username . '" via ' . ( $_SERVER['SERVER_PORT'] == 443 ? 'https://' : 'http://')
		. $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
}

function ccgeri_ui_warnAfter_beforeBlockSeconds( $allowed_retries, $timeframe ) {
	$option_name = 'ccgeri_failed_login_' . esc_attr( $_SERVER['REMOTE_ADDR'] );
	$userfail = get_option( $option_name );

	if( ! $userfail ) {
		// First access. We create a new option for this user.
		$userfail = array(
			'blocked_until' => time() + $timeframe,
			'count' => 1
		);
		add_option( $option_name, $userfail );
	}
	else {
		// Within time frame of 10 min?:
		if( $userfail['blocked_until'] > time() ) {
			// Is this the 4th failed try already ("3" means third re-try):
			if( $userfail['count'] == $allowed_retries ) {
				add_filter( 'login_errors', 'ccgeri_display_last_warning' );
				delete_option( $option_name );
				return;
			}
			$userfail['count'] += 1;
		}
		else {
			// New time frame and new counting.
			$userfail['blocked_until'] = time() + $timeframe;
			$userfail['count'] = 1;
		}
		update_option( $option_name, $userfail );
	}
}

function ccgeri_display_last_warning( $errors ) {
	if( ! isset( $errors ) || ! $errors )
		$errors = '';
	$errors .= ( strlen( $errors ) > 0 ) ? '<br/><br/>' : '';
	$errors .= 'Sie haben noch einen letzten Versuch. Dann wird Ihre IP-Adresse aus Sicherheitsgründen für 60 min gesperrt.';
	return $errors;
}
// TODO: Create a cronjob to clean up options table from time to time.
