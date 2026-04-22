<?php

const WPMA_OPTION_NAME = "wpma_settings";


/**
 * Register a new settings page in the backend
 *
 * @param array $pages The current pages
 *
 * @return array The new pages
 */
function wpma_register_settings_page( array $pages ): array {
	$pages[] = array(
		"id"          => "wpma_settings_page",
		"page_title"  => __( 'Program Announcement Settings', 'wpma' ),
		"menu_title"  => __( 'Program Announcements', 'wpma' ),
		"capability"  => "manage_options",
		"option_name" => WPMA_OPTION_NAME,
		"icon_url"    => "dashicons-megaphone",
		"customizer"  => false,
		"position"    => 900,
		"style"       => "no-boxes",
		"tabs"        => [
			"mastodon" => [
				"label" => __( "Mastodon", "wpma" ),
				"icon"  => plugin_dir_url( __FILE__ ) . "static/mastodon.svg",
			],
			"at_proto" => [
				"label" => __( "AT Proto / Bluesky", "wpma" ),
				"icon"  => plugin_dir_url( __FILE__ ) . "static/bluesky.svg",
			],
			"discord"  => [
				"label" => __( "Discord", "wpma" ),
				"icon"  => plugin_dir_url( __FILE__ ) . "static/discord.svg",
			]
		]
	);

	return $pages;
}

/**
 * Add settings meta boxes
 *
 * @param array $meta_boxes All meta boxes that have already been declared
 *
 * @return array The previous meta boxes with the settings meta boxes for this
 *  plugin
 */
function wpma_setttings_meta_boxes( array $meta_boxes ): array {

	/**
	 * This adds the Mastodon related settings to the settings page
	 */
	$meta_boxes[] = array(
		"id"             => "wpma_settings_mastodon",
		"title"          => __( "Mastodon", "wpma" ),
		"context"        => "normal",
		"settings_pages" => "wpma_settings_page",
		"tab"            => "mastodon",
		"fields"         => [
			[
				"id"   => "mastodon_instance_url",
				"type" => "url",
				"name" => __( "Mastodon Instance URL", "wpma" ),
				"desc" => __( "URL that points to the mastodon instance that the post is going to be published on", "wpma" ),
			],
			[
				"id"   => "mastodon_access_token",
				"type" => "text",
				"name" => __( "Access Token", "wpma" ),
				"desc" => __( "Access token that is generated for the account that the post is going to be published on", "wpma" )
			],
			[
				"id"      => "mastodon_post_visibility",
				"type"    => "select",
				"name"    => __( "Visibility", "wpma" ),
				"std"     => "public",
				"options" => [
					"public"   => __( "Public", "wpma" ),
					"unlisted" => __( "Unlisted", "wpma" ),
					"private"  => __( "Followers Only", "wpma" ),
					"direct"   => __( "Only Mentioned", "wpma" )
				]
			],
			[
				"id"   => "test_mastodon",
				"type" => "button",
				"std"  => __( "Test Announcements with this Service", "wpma" ),
			],
			[
				"id"   => "publish_mastodon",
				"type" => "button",
				"std"  => __( "Publish Upcoming Announcements with this Service", "wpma" ),
			]
		]
	);

	/**
	 * This adds the Bluesky related settings to the page
	 */
	$meta_boxes[] = array(
		"id"             => "wpma_settings_atproto",
		"title"          => __( "Bluesky / AT Proto", "wpma" ),
		"context"        => "normal",
		"settings_pages" => "wpma_settings_page",
		"tab"            => "at_proto",
		"fields"         => [
			[
				"id"   => "at_proto_instance_url",
				"type" => "url",
				"std"  => "https://bsky.social/",
				"name" => __( "AT Proto Instance URL", "wpma" ),
				"desc" => __( "The host on which the AT Proto instance the plugin publishes to lives on", "wpma" ),
			],
			[
				"id"   => "at_proto_identifier",
				"type" => "text",
				"name" => __( "AT Proto Identifier", "wpma" ),
				"desc" => __( "The username that is going to be used to publish the announcements", "wpma" ),
			],
			[
				"id"   => "at_proto_access_token",
				"type" => "text",
				"name" => __( "Access Token / App Passwort", "wpma" ),
				"desc" => __( "Access token or app password that is generated for the account that the post is going to be published on", "wpma" )
			],
			[
				"id"   => "test_at_proto",
				"type" => "button",
				"std"  => __( "Test Announcements with this Service", "wpma" ),
			]
		]
	);

	/**
	 * This adds the Discord related settings to the page
	 */
	$meta_boxes[] = array(
		"id"             => "wpma_settings_discord",
		"title"          => __( "Discord", "wpma" ),
		"context"        => "normal",
		"settings_pages" => "wpma_settings_page",
		"tab"            => "discord",
		"fields"         => [
			[
				"id"   => "discord_webhook_url",
				"type" => "text",
				"name" => __( "Webhook URL", "wpma" ),
				"desc" => __( "The Webhook URL that is used to publish the announcements", "wpma" ),
			],
			[
				"id"   => "test_discord",
				"type" => "button",
				"std"  => __( "Test Announcements with this Service", "wpma" ),
			],
			[
				"id"   => "publish_discord",
				"type" => "button",
				"std"  => __( "Publish Upcoming Announcements with this Service", "wpma" ),
			]
		]
	);

	return $meta_boxes;
}
