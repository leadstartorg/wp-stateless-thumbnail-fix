<?php
/**
 * Plugin Name: WP Stateless CLI Image Thumbnail Fix
 * Description: Regenerate thumbnails for GCS/Stateless Media images using WP-CLI. Supports CDN/GCS URLs, fallback logic, date range, specific ID, and optional Cloudflare purge.
 * Author: Your Name
 * Version: 1.1
 *
 * Optional Constants (define in wp-config.php):
 *   WP_STATELESS_CDN_DOMAIN - URL to prepend for fallback thumbnails (e.g., https://cdn.example.com)
 *   CF_API_KEY               - Cloudflare API Key (for purge)
 *   CF_EMAIL                 - Cloudflare account email
 *   CF_ZONE_ID               - Cloudflare zone ID
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

use WP_CLI\Utils;

class WP_Stateless_CLI_Thumbnail_Fix {

    public function __invoke( $args, $assoc_args ) {
        global $wpdb;

        $days       = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 30;
        $id         = isset( $assoc_args['id'] ) ? (int) $assoc_args['id'] : null;
        $batch      = isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 10;
        $purge_cf   = isset( $assoc_args['purge-cf'] );
        $cdn_domain = isset( $assoc_args['cdn-domain'] ) ? rtrim( $assoc_args['cdn-domain'], '/' ) : ( defined('WP_STATELESS_CDN_DOMAIN') ? WP_STATELESS_CDN_DOMAIN : '' );

        $offset = 0;
        $total  = 0;

        while ( true ) {
            $query = "SELECT ID FROM $wpdb->posts WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'";
            
            if ( $id ) {
                $query .= $wpdb->prepare(" AND ID = %d", $id);
            } else {
                $since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
                $query .= $wpdb->prepare(" AND post_date >= %s", $since);
            }

            $query .= " ORDER BY ID ASC LIMIT $batch OFFSET $offset";
            $attachments = $wpdb->get_col( $query );

            if ( ! $attachments ) {
                break;
            }

            foreach ( $attachments as $attachment_id ) {
                $this->process_attachment( $attachment_id, $cdn_domain, $purge_cf );
                $total++;
            }

            $offset += $batch;

            if ( $id ) break;
        }

        WP_CLI::success( "Processed $total attachment(s)." );
    }

    private function process_attachment( $attachment_id, $cdn_domain = '', $purge_cf = false ) {
        $file_path = get_attached_file( $attachment_id );
        $sm_cloud  = get_post_meta( $attachment_id, 'sm_cloud', true );

        // Determine fallback URL
        $url = '';
        if ( ! empty( $sm_cloud['fileLink'] ) ) {
            $url = $sm_cloud['fileLink'];
        } elseif ( $cdn_domain && $file_path ) {
            $upload_dir = wp_get_upload_dir();
            $relative   = str_replace( $upload_dir['basedir'], '', $file_path );
            $url        = rtrim( $cdn_domain, '/' ) . $relative;
        } elseif ( ! empty( $sm_cloud['mediaLink'] ) ) {
            $url = $sm_cloud['mediaLink'];
        } else {
            WP_CLI::warning( "Attachment $attachment_id: No URL available" );
            return;
        }

        WP_CLI::log( "Attachment $attachment_id: using URL => $url" );

        // Download temporarily
        $tmp_file = wp_tempnam( $url );
        $response = wp_remote_get( $url, [ 'timeout' => 60 ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) {
            WP_CLI::warning( "Failed to download $url" );
            return;
        }

        file_put_contents( $tmp_file, wp_remote_retrieve_body( $response ) );

        // Replace local file temporarily
        $original_file = $file_path;
        copy( $tmp_file, $original_file );

        // Regenerate thumbnails
        WP_CLI::runcommand( "media regenerate $attachment_id --only-missing --yes" );

        // Cleanup temp file
        unlink( $tmp_file );

        // Optional Cloudflare purge
        if ( $purge_cf && defined('CF_API_KEY') && defined('CF_EMAIL') && defined('CF_ZONE_ID') ) {
            $this->purge_cloudflare_url( $url );
        }
    }

    private function purge_cloudflare_url( $url ) {
        $endpoint = 'https://api.cloudflare.com/client/v4/zones/' . CF_ZONE_ID . '/purge_cache';
        $body = json_encode([ 'files' => [ $url ] ]);

        $response = wp_remote_post( $endpoint, [
            'headers' => [
                'X-Auth-Email' => CF_EMAIL,
                'X-Auth-Key'   => CF_API_KEY,
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
            'timeout' => 60,
        ]);

        if ( is_wp_error( $response ) ) {
            WP_CLI::warning( "Cloudflare purge failed for $url" );
        } else {
            WP_CLI::log( "Cloudflare purged: $url" );
        }
    }
}

// Register WP-CLI command
WP_CLI::add_command( 'stateless-cli-image-thumbnail-fix', new WP_Stateless_CLI_Thumbnail_Fix() );
