<?php
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