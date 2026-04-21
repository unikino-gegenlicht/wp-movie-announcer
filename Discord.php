<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpChannels\DiscordWebhook\Discord;


const DISCORD_WH_SUPPRESS_EMBEDS = 1 << 2;

class CustomDiscord extends Discord {

	#[Override]
	public function send(): void {
		if ( empty( $this->webhook ) ) {
			throw new Exception( 'Please set a Discord Webhook.', 400 );
		}

		$json = [];

		if ( $this->username ) {
			$json['username'] = $this->username;
		}

		if ( $this->avatar_url ) {
			$json['avatar_url'] = $this->avatar_url;
		}

		if ( $this->content ) {
			$json['content'] = $this->content;
		}

		if ( ! empty( $this->embeds ) ) {
			$json['embeds'] = $this->embeds;

			// fix bug if send embeds with only "color" key
			if ( count( $this->embeds ) === 1 && count( array_keys( $this->embeds[0] ) ) === 1 && array_key_first( $this->embeds[0] ) == 'color' ) {
				unset( $json['embeds'] );
			}
		}

		$json['flags'] = DISCORD_WH_SUPPRESS_EMBEDS;

		$response = $this->client->post( $this->webhook, [ 'json' => $json ] );

		if ( $response->getStatusCode() !== 204 ) {
			throw new Exception( 'Failed to request Discord Webhook.', $response->getStatusCode() );
		}
	}

}