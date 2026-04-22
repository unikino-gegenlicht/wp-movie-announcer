<?php
/**
 * This file contains the function related to publishing posts on mastodon and
 * testing mastodon publishing
 */

use Vazaha\Mastodon\Exceptions\InvalidResponseException;
use Vazaha\Mastodon\Factories\ApiClientFactory as MastodonAPIFactory;
use Vazaha\Mastodon\Helpers as MastodonHelper;

const WPMA_MASTODON_MAX_CHAR_COUNT  = 500;
const WPMA_MASTODON_LINK_CHAR_COUNT = 23;

/**
 * This function tests creating a post on the mastodon instance
 *
 * @return void
 * @throws ValueError A required setting has not been set
 * @throws InvalidResponseException Mastodon returned an invalid response
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
 * Publish the provided posts on mastodon as announcement
 *
 * @param WP_Post[]|int[] $posts
 *
 * @return void
 * @throws DateMalformedStringException
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
		$post_text = "🎬 {$screening['title']} " . ( $screening['title'] !== $screening['original_title'] ? "(OT: {$screening['original_title']})" : "" ) . PHP_EOL;
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