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

use PhpChannels\DiscordWebhook\Discord;
use Vazaha\Mastodon\Exceptions as MastodonExceptions;
use Vazaha\Mastodon\Factories\ApiClientFactory as MastodonAPIFactory;
use Vazaha\Mastodon\Helpers as MastodonHelper;

const WPMA_OPTION_NAME = "wpma_settings";

const WPMA_MASTODON_MAX_CHAR_COUNT  = 500;
const WPMA_MASTODON_LINK_CHAR_COUNT = 23;


const WPMA_ATPROTO_MAX_CHAR_COUNT = 300;

defined( 'ABSPATH' ) || exit;
require_once 'vendor/autoload.php';

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

/**
 * Activation Hook
 *
 * This function automatically schedules the publishing cycle to the next
 * sunday at 16:00h.
 *
 * @return void
 * @throws Exception
 */
function wpma_activate() {
	if ( ! wp_next_scheduled( 'wpma_publish_screenings' ) ) {
		$tz              = new DateTimeZone( 'Europe/Berlin' );
		$next_sunday     = new DateTimeImmutable( "next Sunday", $tz );
		$initial_post_ts = $next_sunday->setTime( 16, 0, 0 )->getTimestamp() + $next_sunday->getOffset();
		wp_schedule_event( $initial_post_ts, 'weekly', 'wpma_publish_screenings' );
	}
}

/**
 * Deactivation Hook
 *
 * This function removes the next scheduled publishing
 *
 * @return void
 */
function wpma_deactivate() {
	$next_publish = wp_next_scheduled( 'wpma_publish_screenings' );
	wp_unschedule_event( $next_publish, 'wpma_publish_screenings' );
}

function wpma_enqueue_scripts() {
	wp_enqueue_script( 'wpma-ajax', plugin_dir_url( __FILE__ ) . 'js/ajax.js', [ 'jquery' ], md5_file( plugin_dir_path( __FILE__ ) . "js/ajax.js" ), false );
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
				wpma_publish_atproto( $posts );
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

/**
 * This function tests creating a post on the mastodon instance
 *
 * @return void
 * @throws ValueError A required setting has not been set
 * @throws MastodonExceptions\InvalidResponseException Mastodon returned an invalid response
 */
function wpma_test_mastodon(): void {
	try {
		$instance_url = rwmb_meta( "mastodon_instance_url", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
		if ( mb_trim( $instance_url ) === "" ) {
			throw new ValueError( "No mastodon instance URL set.", 1 );
		}
		$access_token = rwmb_meta( "mastodon_access_token", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
		if ( mb_trim( $access_token ) === "" ) {
			throw new ValueError( "No mastodon access token set.", 2 );
		}

		$factory = new MastodonAPIFactory();
		$client  = $factory->build();

		$client->setBaseUri( $instance_url );
		$client->setAccessToken( $access_token );

		$full_image_path = dirname( __FILE__ ) . '/static/demo.jpg';
		$post_image      = $client->methods()->media()->v2( file: new MastodonHelper\UploadFile( $full_image_path ), focus: "(0,0)" );

		$client->methods()->statuses()->create( "This is a test post, to check the configuration of the announcement plugin. This post is only visible to you!", media_ids: [ $post_image->id ], visibility: "direct", language: "de" );
	} catch ( Exception $e ) {
		wp_send_json_error( [ "error" => $e->getMessage() ] );
	}

	wp_send_json_success();
}

/**
 * This function creates a private test post in the configured Bluesky/ATProto
 * instance
 *
 *
 * @return void
 */
function wpma_test_atproto(): void {
	$instance_url = rwmb_meta( "wpma_mastodon_instance_url", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $instance_url ) === "" ) {
		throw new ValueError( "No mastodon instance URL set.", 1 );
	}
	$access_token = rwmb_meta( "mastodon_access_token", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $access_token ) === "" ) {
		throw new ValueError( "No mastodon access token set.", 2 );
	}
}


function wpma_test_discord(): void {
	$webhook_url = rwmb_meta( "discord_webhook_url", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $webhook_url ) === "" ) {
		throw new ValueError( "No discord webhook URL set.", 3 );
	}

	$content = "# Testnachricht" . PHP_EOL;
	$content .= "Aktuelle Kalenderwoche: " . new DateTimeImmutable( "now", new DateTimeZone( "Europe/Berlin" ) )->format( "W" ) . PHP_EOL;
	$content .= "## Filmtitel 1";

	try {
		$msg = Discord::message( $webhook_url );
		$msg->setUsername( "Unikino GEGENLICHT" );
		$msg->setContent( $content );
		$msg->setTitle( "Unikino GEGENLICHT" );
		$msg->setImage( plugin_dir_url( __FILE__ ) . "static/demo.jpg" );
		$msg->setThumbnail( plugin_dir_url( __FILE__ ) . "static/demo.jpg" );
		$msg->setDescription( "test" );
		$msg->setAuthor( wp_get_current_user()->display_name, wp_get_current_user()->user_url, get_avatar_url( wp_get_current_user()->user_email ) );
		$msg->send();
	} catch ( Exception $e ) {
		wp_send_json_error( [ "error" => $e->getMessage() ] );
	}
}

/**
 * Publish the screening schedule on the social medias
 *
 * @return void
 */
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
 * @param WP_Post[]|int[] $posts
 *
 * @return void
 */
function wpma_publish_mastodon( array $posts ): void {
	$instance_url = rwmb_meta( "mastodon_instance_url", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $instance_url ) === "" ) {
		throw new ValueError( "No mastodon instance URL set.", 1 );
	}
	$access_token = rwmb_meta( "mastodon_access_token", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $access_token ) === "" ) {
		throw new ValueError( "No mastodon access token set.", 2 );
	}

	$factory = new MastodonAPIFactory();
	$client  = $factory->build();

	$client->setBaseUri( $instance_url );
	$client->setAccessToken( $access_token );

	$screenings = array();
	foreach ( $posts as $post ) {
		$enforce_anonymized = ggl_get_licensing_type( $post->ID ) !== "full";
		$image_path         = ggl_get_feature_image_path( $post->ID, "mobile", $enforce_anonymized );
		$screenings[]       = [
			"title"          => ggl_get_localized_title( $post->ID, $enforce_anonymized ),
			"start"          => ggl_get_starting_time( $post->ID )->format( "d.m.Y \| H:i \U\h\\r" ),
			"original_title" => ggl_get_title( $post->ID, $enforce_anonymized ),
			"summary"        => str_replace( "\n", "", mb_trim( strip_tags( nl2br( ggl_get_summary( $post->ID, $enforce_anonymized ) ) ) ) ),
			"url"            => get_post_permalink( $post->ID ) . "?utm_source=mastodon.social&utm_medium=social&utm_campaign=social-announcements&utm_content=textlink",
			"reservations"   => ggl_get_event_booking_url( $post->ID ) == "" ? null : ( ggl_get_event_booking_url( $post->ID ) . "?utm_source=mastodon.social&utm_medium=social&utm_campaign=social-announcements&utm_content=textlink" ),
			"image_path"     => $image_path,
		];
	}

	$next_monday = new DateTimeImmutable( "next Monday" );
	$next_sunday = $next_monday->add( new DateInterval( "P6D" ) );
	$opener_text = "Für die kommende Woche ({$next_monday->format('d.m.')}–{$next_sunday->format('d.m.')}) haben wir folgendes Programm für euch im Angebot";
	$opener_text .= PHP_EOL;
	$opener_text .= PHP_EOL;
	foreach ( $screenings as $screening ) {
		$opener_text .= "{$screening['title']}" . PHP_EOL;
		$opener_text .= "{$screening['start']}" . PHP_EOL;
		$opener_text .= PHP_EOL;
	}
	$opener_text .= "Das gesamte Programm findet ihr wie immer unter https://gegenlicht.net?utm_source=mastodon.social&utm_medium=social&utm_campaign=social-announcements&utm_content=textlink";
	$opener_post = $client->methods()->statuses()->create( $opener_text, visibility: "direct", language: "de" );

	$last_post_id = $opener_post->id;
	foreach ( $screenings as $screening ) {
		$post_text = "🎬 {$screening['title']}" . ( $screening['title'] !== $screening['original_title'] ? "(OT: {$screening['original_title']})" : "" ) . PHP_EOL;
		$post_text .= "{$screening['start']}" . PHP_EOL;
		$post_text .= PHP_EOL;

		$base_length = strlen( $post_text );

		if ( isset( $screening['reservations'] ) ) {
			$post_closer   = "🎟️ " . $screening["reservations"] . PHP_EOL;
			$post_closer   .= "ℹ️ " . $screening["url"];
			$closer_length = strlen( $post_closer ) - strlen( $screening["reservations"] ) - strlen( $screening["url"] ) + 2 * WPMA_MASTODON_LINK_CHAR_COUNT;
		} else {
			$post_closer   = "ℹ️ " . $screening["url"];
			$closer_length = strlen( $post_closer ) - strlen( $screening["url"] ) + 1 * WPMA_MASTODON_LINK_CHAR_COUNT;
		}

		$remaining_length = WPMA_MASTODON_MAX_CHAR_COUNT - $base_length - $closer_length;
		$summary          = substr_replace( $screening["summary"], "", $remaining_length );
		$summary          = mb_trim( $summary );
		$summary          .= str_ends_with( $summary, "." ) ? "" : "…";

		$post_text .= $summary . PHP_EOL;
		$post_text .= PHP_EOL;
		$post_text .= $post_closer;

		$img = $client->methods()->media()->v2( new MastodonHelper\UploadFile( $screening["image_path"] ), focus: "(0,0)" );

		$mstdn_post   = $client->methods()->statuses()->create( $post_text, media_ids: [ $img->id ], in_reply_to_id: $last_post_id, visibility: "direct", language: "de" );
		$last_post_id = $mstdn_post->id;
	}
}


function wpma_publish_discord( array $posts ): void {
	$webhook_url = rwmb_meta( "discord_webhook_url", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $webhook_url ) === "" ) {
		throw new ValueError( "No mastodon instance URL set.", 1 );
	}
	$next_monday = new DateTimeImmutable( "next Monday" );
	$next_sunday = $next_monday->add( new DateInterval( "P6D" ) );

	$content = "# Das Programm in der KW {$next_monday->format('W')} ({$next_monday->format('d.m.')}–{$next_sunday->format('d.m.')})" . PHP_EOL;
	$content .= "Auch für die KW {$next_monday->format('W')} haben wir euch wieder einige Vorstellungen mitgebracht";

	$msg = Discord::message( $webhook_url );
	$msg->setUsername( "Unikino GEGENLICHT" );
	$msg->setAvatarUrl( get_site_icon_url() );
	$msg->setContent( $content );
	$msg->send();

	foreach ( $posts as $post ) {
		$enforce_anonymized = ggl_get_licensing_type( $post->ID ) != "full";

		$title               = ggl_get_localized_title( $post, $enforce_anonymized );
		$original_title      = ggl_get_title( $post, $enforce_anonymized );
		$summary             = ggl_get_summary( $post, $enforce_anonymized );
		$image_url           = ggl_get_feature_image_url( $post, force_anonymized: $enforce_anonymized );
		$url                 = get_post_permalink( $post );
		$masked_url          = str_replace( "https://", "", $url );
		$reservations        = ggl_get_event_booking_url( $post );
		$masked_reservations = str_replace( "https://", "", $reservations );
		$proposers           = ggl_get_proposers( $post );
		$proposer_names      = array_map( function ( $item ) {
			return ggl_get_title( $item );
		}, $proposers );

		if ( count( $proposer_names ) == 1 && $proposer_names[0] !== "" ) {
			$author = array_pop( $proposer_names );
		} elseif ( count( $proposer_names ) > 1 ) {
			$last_name = array_pop( $proposer_names );
			$author    = join( ", ", $proposer_names );
			$author    .= " und " . $last_name;
		} else {
			$author = "uns";
		}

		$content = mb_trim( "## {$title} " . ( $title !== $original_title ? "(OT: {$original_title})" : "" ) ) . PHP_EOL;
		$content .= mb_trim( strip_tags( str_replace( "</p>", PHP_EOL . PHP_EOL, $summary ) ) ) . PHP_EOL;
		$content .= PHP_EOL;
		$content .= "Und warum der Film aus der Sicht von {$author} für euch sehenswert ist erfahrt ihr unter [{$masked_url}]({$url}?utm_source=discord.com&utm_medium=social&utm_campaign=social-announcements&utm_content=textlink)" . PHP_EOL;
		$content .= PHP_EOL;
		if ( $reservations !== "" ) {
			$content .= "🎟️ Reservierungen für diese Vorstellung sind unter [{$masked_reservations}]({$reservations}?utm_source=discord.com&utm_medium=social&utm_campaign=social-announcements&utm_content=textlink) möglich." . PHP_EOL;
		}

		$msg = Discord::message( $webhook_url );
		$msg->setContent( mb_trim( $content ) );
		$msg->setUsername( "Unikino GEGENLICHT" );
		$msg->setAvatarUrl( get_site_icon_url() );
		$msg->setUrl( "{$url}?utm_source=discord.com&utm_medium=social&utm_campaign=social-announcements&utm_content=textlink" );
		$msg->setTitle( "{$title} " . ( $title !== $original_title ? "(OT: {$original_title})" : "" ) );
		$msg->setImage($image_url);


		send_discord_msg:
		sleep(2);
		$msg->send();
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