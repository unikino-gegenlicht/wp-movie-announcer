<?php
/**
 * This file contains all functionality related to publishing posts using the
 * AT Protocol (@Protocol). The main implementation is currently done by Bluesky
 * so this file is mainly targeted to use Bluesky functions and may not be
 * interoperable with other AT Protocol hosts
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

function wpma_publish_atproto(array $posts): void {

}