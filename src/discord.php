<?php
/**
 * This file contains all functionality related to interactions with Discord
 */

use PhpChannels\DiscordWebhook\Discord;

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
		$content .= ggl_get_starting_time($post)->format("d.m.Y | H:i \U\h\\r") . PHP_EOL . PHP_EOL;
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
		$msg->setDescription( wpma_get_description_str( $post ) );
		$msg->setImage( $image_url );


		send_discord_msg:
		sleep( 2 );
		$msg->send();
	}
}
