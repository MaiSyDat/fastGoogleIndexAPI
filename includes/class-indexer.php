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
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function submit_url( $url, $action_type = 'URL_UPDATED' ) {
		// Validate action type.
		if ( ! in_array( $action_type, array( 'URL_UPDATED', 'URL_DELETED' ), true ) ) {
			$action_type = 'URL_UPDATED';
		}

		// Get service account credentials.
		$service_account_json = get_option( 'fast_google_indexing_service_account', '' );
		if ( empty( $service_account_json ) ) {
			$this->logger->log( $url, 0, __( 'Service account JSON not configured.', 'fast-google-indexing-api' ), $action_type );
			return new \WP_Error( 'no_credentials', __( 'Google Service Account JSON not configured.', 'fast-google-indexing-api' ) );
		}

		// Parse service account JSON.
		$service_account = json_decode( $service_account_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $service_account['private_key'] ) ) {
			$this->logger->log( $url, 0, __( 'Invalid service account JSON format.', 'fast-google-indexing-api' ), $action_type );
			return new \WP_Error( 'invalid_credentials', __( 'Invalid service account JSON format.', 'fast-google-indexing-api' ) );
		}

		// Get access token.
		$access_token = $this->get_access_token( $service_account );
		if ( is_wp_error( $access_token ) ) {
			$this->logger->log( $url, 0, $access_token->get_error_message(), $action_type );
			return $access_token;
		}

		// Submit URL to Google Indexing API.
		$response = $this->send_indexing_request( $url, $action_type, $access_token );

		if ( is_wp_error( $response ) ) {
			$this->logger->log( $url, 0, $response->get_error_message(), $action_type );
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// Log the response.
		$message = ( 200 === $response_code ) 
			? __( 'Successfully submitted to Google Indexing API.', 'fast-google-indexing-api' )
			: sprintf( __( 'Error: %s', 'fast-google-indexing-api' ), $response_body );

		$this->logger->log( $url, $response_code, $message, $action_type );

		return ( 200 === $response_code );
	}

	/**
	 * Get OAuth 2.0 access token using JWT.
	 *
	 * @param array $service_account Service account data.
	 * @return string|WP_Error Access token or WP_Error on failure.
	 */
	private function get_access_token( $service_account ) {
		// Create JWT for authentication.
		$jwt = $this->create_jwt( $service_account );
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
	 * @param array $service_account Service account data.
	 * @return string|WP_Error JWT token or WP_Error on failure.
	 */
	private function create_jwt( $service_account ) {
		$private_key = $service_account['private_key'];
		$client_email = $service_account['client_email'];

		if ( empty( $private_key ) || empty( $client_email ) ) {
			return new \WP_Error( 'jwt_error', __( 'Missing private key or client email in service account.', 'fast-google-indexing-api' ) );
		}

		// JWT Header.
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		// JWT Claim Set.
		$now = time();
		$claim_set = array(
			'iss'   => $client_email,
			'scope' => 'https://www.googleapis.com/auth/indexing',
			'aud'   => $this->token_endpoint,
			'exp'   => $now + 3600, // Token expires in 1 hour.
			'iat'   => $now,
		);

		// Encode header and claim set.
		$encoded_header = $this->base64_url_encode( wp_json_encode( $header ) );
		$encoded_claim_set = $this->base64_url_encode( wp_json_encode( $claim_set ) );

		// Create signature.
		$signature_input = $encoded_header . '.' . $encoded_claim_set;
		$signature = '';
		
		// Sign with private key.
		$private_key_resource = openssl_pkey_get_private( $private_key );
		if ( false === $private_key_resource ) {
			return new \WP_Error( 'jwt_error', __( 'Invalid private key format.', 'fast-google-indexing-api' ) );
		}

		$signature_success = openssl_sign( $signature_input, $signature, $private_key_resource, OPENSSL_ALGO_SHA256 );
		openssl_free_key( $private_key_resource );

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
	 * @return array|WP_Error Response array or WP_Error on failure.
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

