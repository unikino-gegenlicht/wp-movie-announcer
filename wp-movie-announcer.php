<?php
/**
 * Movie Program Announcer
 *
 * @package wp-movie-announcer
 * @author Jan Eike Suchard <jan@gegenlicht.net>
 * @copyright 2026 Unikino GEGENLICHT
 * @license EUPL-1.2
 *
 * @wordpress-plugin
 * Plugin Name: Program Announcer
 * Plugin URI: https://github.com/unikino-gegenlicht/wp-movie-announcer
 * Description: This plugin automatically announces the upcoming weeks program every Saturday as long as there is at least one screening
 * Requires PHP: 8.4
 * Author: Jan Eike Suchard
 * Author URI: https://links.suchard.cloud/@jan
 * License: EUPL-1.2
 * License URI: https://interoperable-europe.ec.europa.eu/sites/default/files/custom-page/attachment/2020-03/EUPL-1.2%20EN.txt
 * Text Domain: wpma
 * Version: GGL_PLUGIN_VERSION
 * Required Plugins: ggl-post-types, meta-box-aio
 * Update URI: https://github.com/unikino-gegenlicht/wp-studip-announcement-renderer/releases
 */

defined( 'ABSPATH' ) || exit;

const WPMA_STATIC_DIR = __DIR__ . '/static';
define('WPMA_STATIC_URL', plugin_dir_url(__FILE__) . '/static');

require_once 'vendor/autoload.php';
require_once "src/settings.php";
require_once "src/wp-hooks.php";
require_once "src/mastodon.php";
require_once "src/discord.php";
require_once "src/atproto.php";

register_activation_hook( __FILE__, "wpma_activate" );
register_deactivation_hook( __FILE__, "wpma_deactivate" );

/* Below this line the normal hooks are registered. The hooks above are
   activation and deactivation hooks that should not be modified */

add_action( 'rwmb_enqueue_scripts', 'wpma_enqueue_scripts' );
add_action( "mb_settings_pages", "wpma_register_settings_page" );
add_action( "rwmb_meta_boxes", "wpma_setttings_meta_boxes" );
add_action( "wp_ajax_wpma_test", "wpma_test" );
add_action( "wp_ajax_wpma_publish_manually", "wpma_publish_manually" );
add_action( "wpma_publish_screenings", "wpma_publish_screenings" );


function wpma_enqueue_scripts() {
	wp_enqueue_script( 'wpma-ajax', plugin_dir_url( __FILE__ ) . 'src/js/ajax.js', [ 'jquery' ], md5_file( plugin_dir_path( __FILE__ ) . "src/js/ajax.js" ), false );
	wp_localize_script( 'wpma-ajax', 'ajax', [
		'url'          => admin_url( 'admin-ajax.php' ),
		'testNonce'    => wp_create_nonce( 'wpma_test' ),
		'publishNonce' => wp_create_nonce( "wpma_publish_manually" ),
	] );
}

function wpma_publish_manually(): void {
	check_ajax_referer( "wpma_publish_manually" );
	$platform = wp_unslash( $_POST['platform'] );

	$posts = wpma_get_publishable_posts();
	try {
		switch ( $platform ) {
			case "discord":
				wpma_publish_discord( $posts );
				break;
			case "mastodon":
				wpma_publish_mastodon( $posts );
				break;
			case "at_proto":
				wpma_publish_at_proto( $posts );
				break;
			default:
				wp_send_json_error( [ "status" => "unknown platform" ] );
		}
	} catch ( Exception $e ) {
		wp_send_json_error( [ "status" => "exception occurred", "message" => $e->getMessage() ] );
	}
}

function wpma_test() {
	check_ajax_referer( "wpma_test" );
	$platform = wp_unslash( $_POST['platform'] );

	switch ( $platform ) {
		case "discord":
			// todo: implement discord test
			wpma_test_discord();
			break;
		case "mastodon":
			wpma_test_mastodon();
			break;
		case "at_proto":
			wpma_test_atproto();
			break;
		default:
			wp_send_json_error( [ "status" => "unknown platform" ] );
	}
}

function wpma_publish_screenings() {
	$posts = wpma_get_publishable_posts();

	try {
		wpma_publish_mastodon( $posts );
	} catch ( Exception $e ) {
		throw $e;
	}

	try {
		wpma_publish_at_proto( $posts );
	} catch ( Exception $e ) {
		throw $e;
	}

	try {
		wpma_publish_discord( $posts );
	} catch ( Exception $e ) {
		throw $e;
	}
}


/**
 * @return WP_Post[]|int[]
 * @throws DateMalformedStringException
 */
function wpma_get_publishable_posts(): array {
	try {
		$tz = new DateTimeZone( wp_timezone_string() );
	} catch ( DateInvalidTimeZoneException $e ) {
		error_log( "Invalid timezone set in wordpress, falling back to Europe/Berlin" );
		$tz = new DateTimeZone( "Europe/Berlin" );
	}
	$next_week_start = new DateTimeImmutable( "next Sunday", $tz );
	$next_week_end   = $next_week_start->add( new DateInterval( "P6D" ) );

	$query = new WP_Query( [
		'post_type'      => [ "movie", "event" ],
		'posts_per_page' => - 1,
		'orderby'        => 'meta_value_num',
		'order'          => 'ASC',
		'meta_key'       => 'screening_date',
		'meta_query'     => [
			[
				"key"     => "screening_date",
				"value"   => [
					$next_week_start->getTimestamp() + $next_week_start->getOffset(),
					$next_week_end->getTimestamp() + $next_week_end->getOffset()
				],
				"compare" => "BETWEEN"
			]
		]
	] );

	return $query->posts;
}

function wpma_get_description_str( WP_Post $post ): string {
	if ( ! in_array( $post->post_type, [ "movie", "event" ] ) ) {
		return "";
	}

	$audioVersion     = get_post_meta( $post->ID, "audio_type", true );
	$audioLanguage    = get_post_meta( $post->ID, "audio_language", true );
	$subtitleLanguage = get_post_meta( $post->ID, "subtitle_language", true );

	$presentationTag = "";

	if ( $audioVersion == "original" ) {
		$presentationTag = match ( $subtitleLanguage ) {
			"zxx" => "OV",
			"eng" => "OmeU",
			default => "OmU"
		};

		$presentationLong = match ( $subtitleLanguage ) {
			"zxx" => "{$audioLanguage}. Original",
			default => "{$audioLanguage}. Original mit {$subtitleLanguage}. UT"
		};
	} else {
		$presentationTag = match ( $subtitleLanguage ) {
			"zxx" => "SF",
			"eng" => "SFmeU",
			default => "SFmU"
		};

		$presentationLong = match ( $subtitleLanguage ) {
			"zxx" => "{$audioLanguage}. Synchronfassung",
			default => "{$audioLanguage}. Synchronfassung mit {$subtitleLanguage}. UT"
		};
	}

	$ageRating = match ( get_post_meta( $post->ID, "age_rating", true ) ) {
		- 3, - 2, - 1 => "ohne/unbekannt",
		default => (int) rwmb_get_value( "age_rating" ),
	};

	$countries   = get_post_meta( $post->ID, "country" );
	$countryStr  = join( "/", ggl_resolve_country_list( $countries ) );
	$releaseYear = ggl_get_release_date( $post )->format( "Y" );

	return "{$presentationTag} ($presentationLong) | {$countryStr} {$releaseYear} |  FSK: {$ageRating}";


}