<?php
/**
 * This file contains all functionality related to publishing posts using the
 * AT Protocol (@Protocol). The main implementation is currently done by Bluesky
 * so this file is mainly targeted to use Bluesky functions and may not be
 * interoperable with other AT Protocol hosts
 */


const WPMA_ATPROTO_MAX_CHAR_COUNT = 300;

use potibm\Bluesky\BlueskyApi;
use potibm\Bluesky\BlueskyPostService;
use potibm\Bluesky\Feed\Post;


function wpma_test_atproto(): void {
	$base_url = rwmb_meta( "at_proto_instance_url", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $base_url ) === "" ) {
		throw new ValueError( "No base url set for AT Proto", 1 );
	}
	$username = rwmb_meta( "at_proto_identifier", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $username ) === "" ) {
		throw new ValueError( "No username for AT Proto set", 1 );
	}
	$access_token = rwmb_meta( "at_proto_access_token", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $access_token ) === "" ) {
		throw new ValueError( "No password for AT Proto set", 2 );
	}

	$api          = new BlueskyApi( $username, $access_token, baseUrl: $base_url );
	$post_service = new BlueskyPostService( $api );

	$post = Post::create( "🧪 Das ist nur ein Test", "de" );
	$post = $post_service->addWebsiteCard( $post, get_home_url(), get_bloginfo( 'name' ), get_bloginfo( 'description' ), WPMA_STATIC_DIR . "/demo-600x400.jpg" );
	try {
		$res = $api->createRecord( $post );
	} catch ( Exception $e ) {
		wp_send_json_error( $e->getMessage() );
	}
	wp_send_json_success( [ "cid" => $res->getCid(), "uri" => $res->getUri() ] );
}

function wpma_publish_at_proto( array $posts ): void {
	$base_url = rwmb_meta( "at_proto_instance_url", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $base_url ) === "" ) {
		throw new ValueError( "No base url set for AT Proto", 1 );
	}
	$username = rwmb_meta( "at_proto_identifier", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $username ) === "" ) {
		throw new ValueError( "No username for AT Proto set", 1 );
	}
	$access_token = rwmb_meta( "at_proto_access_token", [ "object_type" => "setting" ], WPMA_OPTION_NAME );
	if ( mb_trim( $access_token ) === "" ) {
		throw new ValueError( "No password for AT Proto set", 2 );
	}

	$api          = new BlueskyApi( $username, $access_token, baseUrl: $base_url );
	$post_service = new BlueskyPostService( $api );

	$next_monday = new DateTimeImmutable( "next Monday" );
	$next_sunday = $next_monday->add( new DateInterval( "P6D" ) );

	$content    = "Unser Programm in der KW {$next_monday->format('W')} ({$next_monday->format('d.m.')}–{$next_sunday->format('d.m.')})" . PHP_EOL . PHP_EOL;
	$screenings = array();
	foreach ( $posts as $post ) {
		$enforce_anonymized = ggl_get_licensing_type( $post->ID ) !== "full";
		$image_path         = ggl_get_feature_image_path( $post->ID, "medium", $enforce_anonymized );
		$screenings[]       = [
			"title"          => ggl_get_localized_title( $post->ID, $enforce_anonymized ),
			"start"          => ggl_get_starting_time( $post->ID )->format( "d.m.Y \| H:i \U\h\\r" ),
			"original_title" => ggl_get_title( $post->ID, $enforce_anonymized ),
			"summary"        => str_replace( "\n", "", mb_trim( strip_tags( nl2br( ggl_get_summary( $post->ID, $enforce_anonymized ) ) ) ) ),
			"url"            => get_post_permalink( $post->ID ) . "?utm_source=bsky.app&utm_medium=social&utm_campaign=social-announcements&utm_content=textlink",
			"image_path"     => $image_path,
			"id"             => $post->ID,
		];
	}

	foreach ( $screenings as $screening ) {
		$content .= "{$screening['title']}" . PHP_EOL;
		$content .= "{$screening['start']}" . PHP_EOL;
		$content .= PHP_EOL;
	}

	$content = mb_trim( $content );
	try {
		$opener   = Post::create( $content, "de" );
		$last_uri = $api->createRecord( $opener )->getUri();
	} catch ( Exception $e ) {
		wp_send_json_error( $e->getMessage() );
	}

	foreach ( $screenings as $screening ) {
		$wp_post   = get_post( $screening['id'] );
		$post_text = "{$screening['title']} " . ( $screening['title'] !== $screening['original_title'] ? "(OT: {$screening['original_title']})" : "" ) . PHP_EOL;
		$post_text .= "{$screening['start']}" . PHP_EOL;
		$post_text .= PHP_EOL;

		$base_length = strlen( $post_text );

		$remaining_length = WPMA_ATPROTO_MAX_CHAR_COUNT - $base_length - 5;
		$summary          = substr_replace( $screening["summary"], "", $remaining_length );
		$summary          = mb_trim( $summary );
		$summary          .= str_ends_with( $summary, "." ) ? "" : "...";

		$post_text .= $summary;

		$post = Post::create( $post_text, "de" );
		$post = $post_service->addFacetsFromLinks( $post );
		$post = $post_service->addReply( $post, $last_uri );
		$post = $post_service->addWebsiteCard( $post, $screening["url"], $screening["title"], wpma_get_description_str( $wp_post ), $screening["image_path"] );

		$last_uri = $api->createRecord( $post )->getUri();
	}


}