<?php
/**
 * Indexer class for Google Indexing API integration.
 *
 * @package FastGoogleIndexing
 */

namespace FastGoogleIndexing;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Indexer class using Singleton pattern.
 */
class Indexer {

	/**
	 * The single instance of the class.
	 *
	 * @var Indexer|null
	 */
	private static $instance = null;

	/**
	 * Google OAuth 2.0 token endpoint.
	 *
	 * @var string
	 */
	private $token_endpoint = 'https://oauth2.googleapis.com/token';

	/**
	 * Google Indexing API endpoint.
	 *
	 * @var string
	 */
	private $indexing_endpoint = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

	/**
	 * Google URL Inspection API endpoint.
	 *
	 * @var string
	 */
	private $inspection_endpoint = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Get the singleton instance.
	 *
	 * @return Indexer
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = Logger::get_instance();
	}

	/**
	 * Submit URL to Google Indexing API.
	 *
	 * @param string $url        The URL to submit.
	 * @param string $action_type Action type: 'URL_UPDATED' or 'URL_DELETED'.
	 * @param string $source     Source of submission (default: 'manual').
	 * @param int    $post_id    Optional post ID to track submission timestamp.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function submit_url( $url, $action_type = 'URL_UPDATED', $source = 'manual', $post_id = 0 ) {
		// Validate action type.
		if ( ! in_array( $action_type, array( 'URL_UPDATED', 'URL_DELETED' ), true ) ) {
			$action_type = 'URL_UPDATED';
		}

		static $cached_service_account = null;
		$service_account_json = get_option( 'fast_google_indexing_service_account', '' );
		
		if ( empty( $service_account_json ) ) {
			$this->logger->log( $url, 0, __( 'Service account JSON not configured.', 'fast-google-indexing-api' ), $action_type, $source );
			return new \WP_Error( 'no_credentials', __( 'Google Service Account JSON not configured.', 'fast-google-indexing-api' ) );
		}

		if ( null === $cached_service_account || $cached_service_account['json'] !== $service_account_json ) {
			$service_account = json_decode( $service_account_json, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $service_account['private_key'] ) ) {
				$this->logger->log( $url, 0, __( 'Invalid service account JSON format.', 'fast-google-indexing-api' ), $action_type, $source );
				return new \WP_Error( 'invalid_credentials', __( 'Invalid service account JSON format.', 'fast-google-indexing-api' ) );
			}
			$cached_service_account = array(
				'json'  => $service_account_json,
				'data'  => $service_account,
			);
		} else {
			$service_account = $cached_service_account['data'];
		}

		// Get access token.
		$access_token = $this->get_access_token( $service_account, 'indexing' );
		if ( is_wp_error( $access_token ) ) {
			$this->logger->log( $url, 0, $access_token->get_error_message(), $action_type, $source );
			return $access_token;
		}

		// Submit URL to Google Indexing API.
		$response = $this->send_indexing_request( $url, $action_type, $access_token );

		if ( is_wp_error( $response ) ) {
			$this->logger->log( $url, 0, $response->get_error_message(), $action_type, $source );
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// Log the response.
		$message = ( 200 === $response_code )
			? __( 'Successfully submitted to Google Indexing API.', 'fast-google-indexing-api' )
			: sprintf( __( 'Error: %s', 'fast-google-indexing-api' ), $response_body );

		$this->logger->log( $url, $response_code, $message, $action_type, $source );

		if ( 200 === $response_code ) {
			// When submit succeeds, mark as "submitted" (URL_IN_INDEX status).
			// This means the URL has been successfully submitted to Google, not that Google has confirmed indexing.
			// Use "Check Status" to verify if Google has actually indexed the URL.
			$current_time = time();
			if ( $post_id > 0 ) {
				update_post_meta( $post_id, '_fgi_google_status', 'URL_IN_INDEX' );
				update_post_meta( $post_id, '_fgi_last_checked', $current_time );
				update_post_meta( $post_id, '_fgi_last_submitted_at', $current_time );
			} else {
				// Fallback: try to find post ID from URL.
				$found_post_id = url_to_postid( $url );
				if ( $found_post_id > 0 ) {
					update_post_meta( $found_post_id, '_fgi_google_status', 'URL_IN_INDEX' );
					update_post_meta( $found_post_id, '_fgi_last_checked', $current_time );
					update_post_meta( $found_post_id, '_fgi_last_submitted_at', $current_time );
				}
			}
		}

		return ( 200 === $response_code );
	}

	/**
	 * Inspect URL using Google URL Inspection API.
	 *
	 * @param string $url URL to inspect.
	 * @param int    $post_id Optional post ID to update meta.
	 * @return array|\WP_Error Inspection result or WP_Error on failure.
	 */
	public function inspect_url( $url, $post_id = 0 ) {
		// Get service account credentials (reuse cached parsing from submit_url if available).
		static $cached_service_account = null;
		$service_account_json = get_option( 'fast_google_indexing_service_account', '' );
		
		if ( empty( $service_account_json ) ) {
			return new \WP_Error( 'no_credentials', __( 'Service account JSON not configured.', 'fast-google-indexing-api' ) );
		}

		if ( null === $cached_service_account || $cached_service_account['json'] !== $service_account_json ) {
			$service_account = json_decode( $service_account_json, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $service_account['private_key'] ) ) {
				return new \WP_Error( 'invalid_credentials', __( 'Invalid service account JSON format.', 'fast-google-indexing-api' ) );
			}
			$cached_service_account = array(
				'json'  => $service_account_json,
				'data'  => $service_account,
			);
		} else {
			$service_account = $cached_service_account['data'];
		}

		// Get site URL for the request.
		$site_url = $this->get_site_url();
		if ( empty( $site_url ) ) {
			return new \WP_Error( 'no_site_url', __( 'Site URL not configured. Please set it in settings.', 'fast-google-indexing-api' ) );
		}

		// Get access token with webmasters scope.
		$access_token = $this->get_access_token( $service_account, 'webmasters' );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		// Prepare request body.
		$body = array(
			'inspectionUrl' => $url,
			'siteUrl'       => $site_url,
		);

		// Send inspection request.
		$response = wp_remote_post(
			$this->inspection_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			return new \WP_Error( 'inspection_error', sprintf( __( 'Inspection failed: %s', 'fast-google-indexing-api' ), $response_body ) );
		}

		$data = json_decode( $response_body, true );
		if ( ! $data ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from Google API.', 'fast-google-indexing-api' ) );
		}

		// Extract index status.
		$index_status = '';
		if ( isset( $data['inspectionResult']['indexStatusResult'] ) ) {
			$index_status_result = $data['inspectionResult']['indexStatusResult'];
			
			// Check coverageState first (most reliable indicator).
			// Possible values: SUBMITTED_AND_INDEXED, INDEXED, NOT_INDEXED, EXCLUDED
			if ( isset( $index_status_result['coverageState'] ) ) {
				$coverage_state = $index_status_result['coverageState'];
				// SUBMITTED_AND_INDEXED or INDEXED means the URL is indexed.
				if ( 'SUBMITTED_AND_INDEXED' === $coverage_state || 'INDEXED' === $coverage_state ) {
					$index_status = 'URL_IN_INDEX';
				} elseif ( 'NOT_INDEXED' === $coverage_state || 'EXCLUDED' === $coverage_state ) {
					$index_status = 'URL_NOT_IN_INDEX';
				}
			}
			
			// Fallback to verdict if coverageState is not available or not conclusive.
			// Possible values: PASS, FAIL, PARTIAL, NEUTRAL
			if ( empty( $index_status ) && isset( $index_status_result['verdict'] ) ) {
				$verdict = $index_status_result['verdict'];
				// PASS means the URL is indexed.
				if ( 'PASS' === $verdict ) {
					$index_status = 'URL_IN_INDEX';
				} elseif ( 'FAIL' === $verdict || 'PARTIAL' === $verdict || 'NEUTRAL' === $verdict ) {
					$index_status = 'URL_NOT_IN_INDEX';
				}
			}
			
			// Additional check: if indexStatusResult exists but no status determined,
			// check if there's any indication of indexing in the response.
			if ( empty( $index_status ) ) {
				// Check for lastCrawlTime - if exists and not empty, URL has been crawled.
				if ( isset( $index_status_result['lastCrawlTime'] ) && ! empty( $index_status_result['lastCrawlTime'] ) ) {
					// URL has been crawled, likely indexed (especially if user confirms it's indexed on Google).
					$index_status = 'URL_IN_INDEX';
				} elseif ( isset( $index_status_result['indexingState'] ) ) {
					// Check indexingState if available.
					$indexing_state = $index_status_result['indexingState'];
					if ( 'INDEXING_ALLOWED' === $indexing_state || 'INDEXING_ALLOWED_BY_ROBOTS' === $indexing_state ) {
						// If indexing is allowed but no coverageState, might be pending.
						// Leave empty to show as unknown/pending.
					}
				}
			}
			
			// Final fallback: if we still don't have status, check the entire inspectionResult structure.
			// Sometimes Google returns data in different locations.
			if ( empty( $index_status ) && isset( $data['inspectionResult'] ) ) {
				$inspection_result = $data['inspectionResult'];
				// Check if there's a direct indexStatus field.
				if ( isset( $inspection_result['indexStatus'] ) ) {
					$direct_status = $inspection_result['indexStatus'];
					if ( 'INDEXED' === $direct_status || 'SUBMITTED_AND_INDEXED' === $direct_status ) {
						$index_status = 'URL_IN_INDEX';
					} elseif ( 'NOT_INDEXED' === $direct_status ) {
						$index_status = 'URL_NOT_IN_INDEX';
					}
				}
			}
		}
		
		// Update post meta if post ID is provided.
		if ( $post_id > 0 ) {
			update_post_meta( $post_id, '_fgi_google_status', $index_status );
			update_post_meta( $post_id, '_fgi_last_checked', time() );
		}

		return array(
			'status'    => $index_status,
			'data'      => $data,
			'timestamp' => time(),
		);
	}

	/**
	 * Get site URL for Google Search Console.
	 *
	 * @return string Site URL or empty string.
	 */
	private function get_site_url() {
		// Cache the site URL to avoid repeated option calls.
		static $cached_url = null;
		
		if ( null !== $cached_url ) {
			return $cached_url;
		}

		// First, try to get from settings.
		$site_url = get_option( 'fast_google_indexing_site_url', '' );
		if ( ! empty( $site_url ) ) {
			// Ensure trailing slash for settings URL too.
			$cached_url = untrailingslashit( $site_url ) . '/';
			return $cached_url;
		}

		// Auto-detect from home_url().
		$home_url = home_url();
		$parsed   = wp_parse_url( $home_url );
		if ( isset( $parsed['scheme'] ) && isset( $parsed['host'] ) ) {
			$detected_url = $parsed['scheme'] . '://' . $parsed['host'];
			// Force append trailing slash to match Google Search Console standards.
			$cached_url = untrailingslashit( $detected_url ) . '/';
			return $cached_url;
		}

		$cached_url = '';
		return $cached_url;
	}

	/**
	 * Get OAuth 2.0 access token using JWT.
	 *
	 * @param array  $service_account Service account data.
	 * @param string $scope_type      Scope type: 'indexing' or 'webmasters'.
	 * @return string|\WP_Error Access token or WP_Error on failure.
	 */
	private function get_access_token( $service_account, $scope_type = 'indexing' ) {
		// Create JWT for authentication.
		$jwt = $this->create_jwt( $service_account, $scope_type );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		// Request access token.
		$response = wp_remote_post(
			$this->token_endpoint,
			array(
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			return new \WP_Error( 'token_error', sprintf( __( 'Failed to get access token: %s', 'fast-google-indexing-api' ), $response_body ) );
		}

		$data = json_decode( $response_body, true );
		if ( ! isset( $data['access_token'] ) ) {
			return new \WP_Error( 'token_error', __( 'Invalid token response.', 'fast-google-indexing-api' ) );
		}

		return $data['access_token'];
	}

	/**
	 * Create JWT (JSON Web Token) for Google OAuth 2.0.
	 *
	 * @param array  $service_account Service account data.
	 * @param string $scope_type      Scope type: 'indexing' or 'webmasters'.
	 * @return string|\WP_Error JWT token or WP_Error on failure.
	 */
	private function create_jwt( $service_account, $scope_type = 'indexing' ) {
		$private_key  = $service_account['private_key'];
		$client_email = $service_account['client_email'];

		if ( empty( $private_key ) || empty( $client_email ) ) {
			return new \WP_Error( 'jwt_error', __( 'Missing private key or client email in service account.', 'fast-google-indexing-api' ) );
		}

		// Define scopes based on type.
		$scopes = array();
		if ( 'indexing' === $scope_type ) {
			$scopes[] = 'https://www.googleapis.com/auth/indexing';
		}
		if ( 'webmasters' === $scope_type || 'both' === $scope_type ) {
			$scopes[] = 'https://www.googleapis.com/auth/webmasters.readonly';
		}
		// If both scopes are needed (for combined operations).
		if ( 'both' === $scope_type ) {
			$scopes[] = 'https://www.googleapis.com/auth/indexing';
		}

		$scope_string = implode( ' ', $scopes );

		// JWT Header.
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		// JWT Claim Set.
		$now       = time();
		$claim_set = array(
			'iss'   => $client_email,
			'scope' => $scope_string,
			'aud'   => $this->token_endpoint,
			'exp'   => $now + 3600, // Token expires in 1 hour.
			'iat'   => $now,
		);

		// Encode header and claim set.
		$encoded_header    = $this->base64_url_encode( wp_json_encode( $header ) );
		$encoded_claim_set = $this->base64_url_encode( wp_json_encode( $claim_set ) );

		// Create signature.
		$signature_input = $encoded_header . '.' . $encoded_claim_set;
		$signature       = '';

		// Sign with private key.
		$private_key_resource = openssl_pkey_get_private( $private_key );
		if ( false === $private_key_resource ) {
			return new \WP_Error( 'jwt_error', __( 'Invalid private key format.', 'fast-google-indexing-api' ) );
		}

		$signature_success = openssl_sign( $signature_input, $signature, $private_key_resource, OPENSSL_ALGO_SHA256 );
		// Only free key resource on PHP versions before 8.0 (deprecated in PHP 8.0+).
		if ( PHP_VERSION_ID < 80000 ) {
			openssl_free_key( $private_key_resource );
		}

		if ( ! $signature_success ) {
			return new \WP_Error( 'jwt_error', __( 'Failed to sign JWT.', 'fast-google-indexing-api' ) );
		}

		$encoded_signature = $this->base64_url_encode( $signature );

		// Return complete JWT.
		return $signature_input . '.' . $encoded_signature;
	}

	/**
	 * Base64 URL encode (RFC 4648).
	 *
	 * @param string $data Data to encode.
	 * @return string Encoded string.
	 */
	private function base64_url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Send indexing request to Google API.
	 *
	 * @param string $url         URL to submit.
	 * @param string $action_type Action type.
	 * @param string $access_token OAuth access token.
	 * @return array|\WP_Error Response array or WP_Error on failure.
	 */
	private function send_indexing_request( $url, $action_type, $access_token ) {
		$body = array(
			'url'  => $url,
			'type' => $action_type,
		);

		$response = wp_remote_post(
			$this->indexing_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		return $response;
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
