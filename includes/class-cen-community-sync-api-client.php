<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CEN_Community_Sync_API_Client {
	const SOURCE_GROUP_NUMBER_NAME = 'group_number';
	const SOURCE_GROUP_NUMBER_KEY  = 'field_6a7ddc6aed794';
	const SOURCE_LOCAL_BOARD_NAME  = 'local_board';
	const SOURCE_LOCAL_BOARD_KEY   = 'field_6a9ae48e1813b';
	const SOURCE_SALES_STAFF_NAME  = 'sales_staff';
	const SOURCE_SALES_STAFF_KEY   = 'field_6a9ae4b31813e';
	const SOURCE_PHONE_FIELD_ID    = 261;
	const SOURCE_MOBILE_FIELD_ID   = 262;

	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function validate_configuration() {
		if ( empty( $this->settings['source_url'] ) ) {
			return new WP_Error( 'cen_sync_missing_url', 'The source site URL is not configured.' );
		}

		if ( 0 !== stripos( $this->settings['source_url'], 'https://' ) ) {
			return new WP_Error( 'cen_sync_insecure_url', 'The source site URL must use HTTPS.' );
		}

		if ( empty( $this->settings['source_username'] ) || empty( $this->settings['source_app_password'] ) ) {
			return new WP_Error( 'cen_sync_missing_credentials', 'The source username and Application Password are required.' );
		}

		return true;
	}

	public function test_connection() {
		$result = $this->request(
			'/wp-json/wp/v2/users',
			array(
				'context'  => 'edit',
				'per_page' => 1,
				'_fields'  => 'id,email,acf,group_number,field_6a7ddc6aed794,local_board,field_6a9ae48e1813b,sales_staff,field_6a9ae4b31813e',
			),
			true
		);

		if ( is_wp_error( $result ) || empty( $result['users'] ) ) {
			return $result;
		}

		$result = $this->add_xprofile_data( $result );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$source_user = $result['users'][0];
		if ( ! self::has_group_number_field( $source_user ) ) {
			return new WP_Error(
				'cen_sync_missing_group_number',
				'The source REST API does not expose group_number (field_6a7ddc6aed794). Enable Show in REST API for its ACF field group on the source site.'
			);
		}

		if ( ! self::has_source_field( $source_user, self::SOURCE_LOCAL_BOARD_NAME, self::SOURCE_LOCAL_BOARD_KEY ) ) {
			return new WP_Error(
				'cen_sync_missing_local_board',
				'The source REST API does not expose local_board (field_6a9ae48e1813b). Enable Show in REST API for its ACF field group on the source site.'
			);
		}

		if ( ! self::has_source_field( $source_user, self::SOURCE_SALES_STAFF_NAME, self::SOURCE_SALES_STAFF_KEY ) ) {
			return new WP_Error(
				'cen_sync_missing_sales_staff',
				'The source REST API does not expose sales_staff (field_6a9ae4b31813e). Enable Show in REST API for its ACF field group on the source site.'
			);
		}

		if ( ! self::has_xprofile_field( $source_user, self::SOURCE_PHONE_FIELD_ID ) ) {
			return new WP_Error(
				'cen_sync_missing_phone',
				'The source BuddyBoss REST API does not expose Phone - Preferred (xProfile field 261). Confirm the field ID and that the field is visible to the source account.'
			);
		}

		if ( ! self::has_xprofile_field( $source_user, self::SOURCE_MOBILE_FIELD_ID ) ) {
			return new WP_Error(
				'cen_sync_missing_mobile',
				'The source BuddyBoss REST API does not expose Phone - Mobile (xProfile field 262). Confirm the field ID and that the field is visible to the source account.'
			);
		}

		return $result;
	}

	public function get_users_page( $page, $email = '', $per_page = 100 ) {
		$query = array(
			'context'  => 'edit',
			'per_page' => max( 1, min( 100, (int) $per_page ) ),
			'page'     => max( 1, (int) $page ),
			'orderby'  => 'id',
			'order'    => 'asc',
			'_fields'  => 'id,email,acf,group_number,field_6a7ddc6aed794,local_board,field_6a9ae48e1813b,sales_staff,field_6a9ae4b31813e',
		);

		if ( $email !== '' ) {
			$query['search']         = $email;
			$query['search_columns'] = array( 'email' );
		}

		$result = $this->request( '/wp-json/wp/v2/users', $query, true );
		if ( is_wp_error( $result ) || empty( $result['users'] ) ) {
			return $result;
		}

		$has_group_number = false;
		foreach ( $result['users'] as $source_user ) {
			if ( is_array( $source_user ) && self::has_group_number_field( $source_user ) ) {
				$has_group_number = true;
				break;
			}
		}

		if ( empty( $has_group_number ) ) {
			return new WP_Error(
				'cen_sync_missing_group_number',
				'The source REST API returned users but no group_number data. Enable Show in REST API for the ACF field group containing field_6a7ddc6aed794 on the source site.'
			);
		}

		$has_local_board = false;
		foreach ( $result['users'] as $source_user ) {
			if ( is_array( $source_user ) && self::has_source_field( $source_user, self::SOURCE_LOCAL_BOARD_NAME, self::SOURCE_LOCAL_BOARD_KEY ) ) {
				$has_local_board = true;
				break;
			}
		}

		if ( empty( $has_local_board ) ) {
			return new WP_Error(
				'cen_sync_missing_local_board',
				'The source REST API returned users but no local_board data. Enable Show in REST API for the ACF field group containing field_6a9ae48e1813b on the source site.'
			);
		}

		$has_sales_staff = false;
		foreach ( $result['users'] as $source_user ) {
			if ( is_array( $source_user ) && self::has_source_field( $source_user, self::SOURCE_SALES_STAFF_NAME, self::SOURCE_SALES_STAFF_KEY ) ) {
				$has_sales_staff = true;
				break;
			}
		}

		if ( empty( $has_sales_staff ) ) {
			return new WP_Error(
				'cen_sync_missing_sales_staff',
				'The source REST API returned users but no sales_staff data. Enable Show in REST API for the ACF field group containing field_6a9ae4b31813e on the source site.'
			);
		}

		return $this->add_xprofile_data( $result );
	}

	private static function has_group_number_field( array $source_user ) {
		return self::has_source_field( $source_user, self::SOURCE_GROUP_NUMBER_NAME, self::SOURCE_GROUP_NUMBER_KEY );
	}

	private static function has_source_field( array $source_user, $field_name, $field_key ) {
		foreach ( array( $field_name, $field_key ) as $selector ) {
			if (
				isset( $source_user['acf'] )
				&& is_array( $source_user['acf'] )
				&& array_key_exists( $selector, $source_user['acf'] )
			) {
				return true;
			}

			if ( array_key_exists( $selector, $source_user ) ) {
				return true;
			}
		}

		return false;
	}

	private static function has_xprofile_field( array $source_user, $field_id ) {
		return isset( $source_user['_cen_xprofile'] )
			&& is_array( $source_user['_cen_xprofile'] )
			&& array_key_exists( (int) $field_id, $source_user['_cen_xprofile'] );
	}

	private function add_xprofile_data( array $result ) {
		$user_ids = array();
		foreach ( $result['users'] as $source_user ) {
			if ( is_array( $source_user ) && ! empty( $source_user['id'] ) ) {
				$user_ids[] = (int) $source_user['id'];
			}
		}

		if ( empty( $user_ids ) ) {
			return $result;
		}

		$query = array(
			'context'         => 'edit',
			'include'         => $user_ids,
			'per_page'        => count( $user_ids ),
			'populate_extras' => false,
			'_fields'         => 'id,xprofile',
		);

		$xprofile_namespace = 'buddyboss/v1';
		$members            = $this->request( '/wp-json/' . $xprofile_namespace . '/members', $query );
		if ( is_wp_error( $members ) && 404 === (int) $members->get_error_data( 'cen_sync_source_response' ) ) {
			$xprofile_namespace = 'buddypress/v1';
			$members            = $this->request( '/wp-json/' . $xprofile_namespace . '/members', $query );
		}

		if ( is_wp_error( $members ) ) {
			return new WP_Error(
				'cen_sync_xprofile_request_failed',
				'The source BuddyBoss extended profile data could not be read: ' . $members->get_error_message()
			);
		}

		if ( ! is_array( $members ) ) {
			return new WP_Error( 'cen_sync_invalid_xprofile_response', 'The source BuddyBoss members endpoint returned an unexpected response.' );
		}

		$values_by_user = array();
		foreach ( $members as $member ) {
			if ( ! is_array( $member ) || empty( $member['id'] ) || ! isset( $member['xprofile'] ) ) {
				continue;
			}

			$values_by_user[ (int) $member['id'] ] = array();
			foreach ( array( self::SOURCE_PHONE_FIELD_ID, self::SOURCE_MOBILE_FIELD_ID ) as $field_id ) {
				$found = false;
				$value = self::find_xprofile_value( $member['xprofile'], $field_id, $found );
				if ( $found ) {
					$values_by_user[ (int) $member['id'] ][ $field_id ] = $value;
				}
			}
		}

		// Some BuddyBoss installations omit otherwise valid WordPress users from
		// the members collection (for example, users without member-directory
		// activity). Read only those omitted users from the direct profile-data
		// endpoints so their saved phone values are not lost.
		foreach ( $user_ids as $user_id ) {
			if ( array_key_exists( $user_id, $values_by_user ) ) {
				continue;
			}

			$values_by_user[ $user_id ] = array();
			foreach ( array( self::SOURCE_PHONE_FIELD_ID, self::SOURCE_MOBILE_FIELD_ID ) as $field_id ) {
				$field_data = $this->request(
					'/wp-json/' . $xprofile_namespace . '/xprofile/' . $field_id . '/data/' . $user_id,
					array( 'context' => 'edit' )
				);

				if ( is_wp_error( $field_data ) ) {
					if ( 404 === (int) $field_data->get_error_data( 'cen_sync_source_response' ) ) {
						$values_by_user[ $user_id ][ $field_id ] = '';
						continue;
					}

					return new WP_Error(
						'cen_sync_xprofile_data_request_failed',
						sprintf( 'Source user %1$d xProfile field %2$d could not be read: %3$s', $user_id, $field_id, $field_data->get_error_message() )
					);
				}

				$found = false;
				$value = self::find_xprofile_value( $field_data, $field_id, $found );
				if ( ! $found ) {
					return new WP_Error(
						'cen_sync_invalid_xprofile_data_response',
						sprintf( 'The source returned an unexpected response for user %1$d xProfile field %2$d.', $user_id, $field_id )
					);
				}

				$values_by_user[ $user_id ][ $field_id ] = $value;
			}
		}

		foreach ( $result['users'] as &$source_user ) {
			$user_id = isset( $source_user['id'] ) ? (int) $source_user['id'] : 0;
			if ( isset( $values_by_user[ $user_id ] ) ) {
				$source_user['_cen_xprofile'] = $values_by_user[ $user_id ];
			}
		}
		unset( $source_user );

		return $result;
	}

	private static function find_xprofile_value( $node, $field_id, &$found ) {
		if ( ! is_array( $node ) ) {
			return null;
		}

		$node_field_id = isset( $node['field_id'] ) ? (int) $node['field_id'] : ( isset( $node['id'] ) ? (int) $node['id'] : 0 );
		if ( $node_field_id === (int) $field_id && ( array_key_exists( 'value', $node ) || ( isset( $node['data'] ) && is_array( $node['data'] ) && array_key_exists( 'value', $node['data'] ) ) ) ) {
			$found = true;
			$value = array_key_exists( 'value', $node ) ? $node['value'] : $node['data']['value'];
			if ( is_array( $value ) ) {
				if ( array_key_exists( 'raw', $value ) ) {
					return $value['raw'];
				}
				if ( array_key_exists( 'unserialized', $value ) ) {
					return $value['unserialized'];
				}
			}

			return $value;
		}

		foreach ( $node as $key => $child ) {
			if ( is_array( $child ) ) {
				if ( (string) (int) $key === (string) $key && (int) $key === (int) $field_id && ( array_key_exists( 'value', $child ) || ( isset( $child['data'] ) && is_array( $child['data'] ) && array_key_exists( 'value', $child['data'] ) ) ) ) {
					$found = true;
					$value = array_key_exists( 'value', $child ) ? $child['value'] : $child['data']['value'];
					if ( is_array( $value ) && array_key_exists( 'raw', $value ) ) {
						return $value['raw'];
					}
					if ( is_array( $value ) && array_key_exists( 'unserialized', $value ) ) {
						return $value['unserialized'];
					}

					return $value;
				}

				$value = self::find_xprofile_value( $child, $field_id, $found );
				if ( $found ) {
					return $value;
				}
			}
		}

		return null;
	}

	private function request( $path, array $query = array(), $include_pagination = false ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$url = untrailingslashit( $this->settings['source_url'] ) . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( $this->settings['source_username'] . ':' . $this->settings['source_app_password'] ),
					'User-Agent'    => 'CEN-Community-Sync/' . CEN_COMMUNITY_SYNC_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cen_sync_http_error', 'Could not reach the source site: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? wp_strip_all_tags( $data['message'] ) : 'Unexpected response from the source site.';
			return new WP_Error( 'cen_sync_source_response', sprintf( 'Source returned HTTP %1$d: %2$s', $status, $message ), $status );
		}

		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'cen_sync_invalid_json', 'The source site returned invalid JSON.' );
		}

		if ( ! $include_pagination ) {
			return $data;
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cen_sync_invalid_users', 'The source users endpoint returned an unexpected response.' );
		}

		return array(
			'users'       => $data,
			'total'       => (int) wp_remote_retrieve_header( $response, 'x-wp-total' ),
			'total_pages' => max( 1, (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' ) ),
		);
	}
}
