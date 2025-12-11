<?php
/**
 * Plugin Name: WP Stateless Thumbnail Fix CLI
 * Description: Regenerates thumbnails for WordPress attachments stored on GCS/CDN. Handles last 30 days or specific IDs, temp files, and optional Cloudflare purge.
 * Author: Jessica Kafor
 * Version: 1.0
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

class Stateless_Image_Thumbnail_Fix_CLI {

    const CDN_URL = 'https://cdn.leadstart.media'; // Change to your CDN
    const CLOUDFLARE_ZONE_ID = ''; // Optional: Cloudflare Zone ID
    const CLOUDFLARE_API_TOKEN = ''; // Optional: Cloudflare API Token

    public function __construct() {
        WP_CLI::add_command( 'stateless-cli-image-thumbnail-fix', [ $this, 'regenerate_thumbnails' ] );
    }

    /**
     * Regenerate thumbnails for attachments.
     *
     * @param array $args Command arguments. Optionally: attachment IDs.
     * @param array $assoc_args --days=30 for last X days
     */
    public function regenerate_thumbnails( $args, $assoc_args ) {
        global $wpdb;

        $days = isset( $assoc_args['days'] ) ? intval( $assoc_args['days'] ) : 30;
        $specific_ids = ! empty( $args ) ? array_map( 'intval', $args ) : [];

        $query = "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'";
        $where = [];

        if ( $specific_ids ) {
            $where[] = 'ID IN (' . implode( ',', $specific_ids ) . ')';
        } else {
            $date = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
            $where[] = "post_date >= '{$date}'";
        }

        if ( $where ) {
            $query .= ' AND ' . implode( ' AND ', $where );
        }

        $attachments = $wpdb->get_col( $query );

        if ( ! $attachments ) {
            WP_CLI::success( 'No attachments found for regeneration.' );
            return;
        }

        WP_CLI::log( "Processing " . count( $attachments ) . " attachments..." );

        foreach ( $attachments as $attachment_id ) {
            $this->process_attachment( $attachment_id );
        }

        WP_CLI::success( 'All batches processed successfully.' );
    }

    protected function process_attachment( $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );
        $sm_cloud = get_post_meta( $attachment_id, 'sm_cloud', true );

        $url = null;

        // Primary CDN URL (Ephemeral)
        if ( ! empty( $sm_cloud['fileLink'] ) ) {
            $url = $sm_cloud['fileLink'];
        }
        // Fallback: local file + CDN constant
        elseif ( $file_path && file_exists( $file_path ) ) {
            $relative_path = str_replace( wp_get_upload_dir()['basedir'] . '/', '', $file_path );
            $url = rtrim( self::CDN_URL, '/' ) . '/' . $relative_path;
