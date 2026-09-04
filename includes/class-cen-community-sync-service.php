<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matches users by email and syncs selected source fields to the destination.
 */
final class CEN_Community_Sync_Service {
	const SOURCE_GROUP_NUMBER_FIELD      = 'group_number';
	const SOURCE_GROUP_NUMBER_KEY        = 'field_6a7ddc6aed794';
	const DESTINATION_GROUP_NUMBER_FIELD = 'field_6a9ae4801813a';
	const DESTINATION_GROUP_NUMBER_META  = 'group_number';
	const SOURCE_LOCAL_BOARD_FIELD       = 'local_board';
	const SOURCE_LOCAL_BOARD_KEY         = 'field_6a9ae48e1813b';
	const DESTINATION_LOCAL_BOARD_FIELD  = 'field_6a7de8f093610';
	const DESTINATION_LOCAL_BOARD_META   = 'local_board';
	const SOURCE_SALES_STAFF_FIELD       = 'sales_staff';
	const SOURCE_SALES_STAFF_KEY         = 'field_6a9ae4b31813e';
	const DESTINATION_SALES_STAFF_FIELD  = 'field_6a7c9bcc1bfaa';
	const DESTINATION_SALES_STAFF_META   = 'sales_staff';
	const SOURCE_PHONE_FIELD_ID          = 261;
	const DESTINATION_PHONE_FIELD        = 'field_6a9ae49c1813c';
	const DESTINATION_PHONE_META         = 'phone';
	const SOURCE_MOBILE_FIELD_ID         = 262;
	const DESTINATION_MOBILE_FIELD       = 'field_6a9ae4ae1813d';
	const DESTINATION_MOBILE_META        = 'mobile';

	private $reporter;

	public function __construct( ?callable $reporter = null ) {
		$this->reporter = $reporter ? $reporter : static function () {};
	}

	public static function empty_stats() {
		return array(
			'fetched'   => 0,
			'examined'  => 0,
			'found'     => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'not_found' => 0,
			'invalid'   => 0,
			'failed'    => 0,
		);
	}

	public function check_batch( array $source_users, $requested_email = '' ) {
		$stats           = self::empty_stats();
		$requested_email = strtolower( sanitize_email( $requested_email ) );
		$stats['fetched'] = count( $source_users );

		foreach ( $source_users as $source_user ) {
			$source_email = isset( $source_user['email'] ) ? strtolower( sanitize_email( $source_user['email'] ) ) : '';

			if ( $requested_email !== '' && $source_email !== $requested_email ) {
				continue;
			}

			++$stats['examined'];

			if ( ! is_email( $source_email ) ) {
				++$stats['invalid'];
				$source_id = isset( $source_user['id'] ) ? (int) $source_user['id'] : 'unknown';
				$this->report( 'invalid', sprintf( 'Source user %s has a missing or invalid email.', $source_id ), array( 'source_user_id' => $source_id ) );
				continue;
			}

			$destination_user = get_user_by( 'email', $source_email );
			if ( $destination_user ) {
				++$stats['found'];
				$this->sync_group_number( $source_user, $destination_user, $stats );
				$this->sync_local_board( $source_user, $destination_user, $stats );
				$this->sync_sales_staff( $source_user, $destination_user, $stats );
				$this->sync_xprofile_text_field(
					$source_user,
					$destination_user,
					$stats,
					self::SOURCE_PHONE_FIELD_ID,
					self::DESTINATION_PHONE_META,
					self::DESTINATION_PHONE_FIELD,
					'preferred phone'
				);
				$this->sync_xprofile_text_field(
					$source_user,
					$destination_user,
					$stats,
					self::SOURCE_MOBILE_FIELD_ID,
					self::DESTINATION_MOBILE_META,
					self::DESTINATION_MOBILE_FIELD,
					'mobile phone'
				);
			} else {
				++$stats['not_found'];
				$this->report( 'not_found', sprintf( '%s was not found on the destination.', $source_email ), array( 'email' => $source_email ) );
			}
		}

		return $stats;
	}

	private function sync_group_number( array $source_user, $destination_user, array &$stats ) {
		$source_id = isset( $source_user['id'] ) ? (int) $source_user['id'] : 'unknown';
		$value     = $this->get_source_group_number( $source_user );

		if ( is_wp_error( $value ) ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'Source user %1$s group_number could not be read: %2$s The destination was not changed.', $source_id, $value->get_error_message() ),
				array( 'source_user_id' => $source_id )
			);
			return;
		}

		$destination_user_id = (int) $destination_user->ID;
		$current_raw_value   = get_user_meta( $destination_user_id, self::DESTINATION_GROUP_NUMBER_META, true );
		$current_value       = $this->format_group_number( $current_raw_value );

		if ( is_wp_error( $current_value ) ) {
			$current_value = '';
		}

		if ( $current_value === $value ) {
			++$stats['unchanged'];
			return;
		}

		$this->update_acf_user_field(
			$destination_user_id,
			self::DESTINATION_GROUP_NUMBER_META,
			self::DESTINATION_GROUP_NUMBER_FIELD,
			$value
		);

		$stored_value = $this->format_group_number( get_user_meta( $destination_user_id, self::DESTINATION_GROUP_NUMBER_META, true ) );
		if ( is_wp_error( $stored_value ) || $stored_value !== $value ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'The group number update could not be verified for destination user %d.', $destination_user_id ),
				array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
			);
			return;
		}

		++$stats['updated'];
		$this->report(
			'updated',
			sprintf( 'Updated group number for destination user %d.', $destination_user_id ),
			array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
		);
	}

	private function get_source_group_number( array $source_user ) {
		$value = $this->get_source_field( $source_user, self::SOURCE_GROUP_NUMBER_FIELD, self::SOURCE_GROUP_NUMBER_KEY );
		return is_wp_error( $value ) ? $value : $this->format_group_number( $value );
	}

	private function sync_local_board( array $source_user, $destination_user, array &$stats ) {
		$source_id = isset( $source_user['id'] ) ? (int) $source_user['id'] : 'unknown';
		$value     = $this->get_source_field( $source_user, self::SOURCE_LOCAL_BOARD_FIELD, self::SOURCE_LOCAL_BOARD_KEY );
		$value     = is_wp_error( $value ) ? $value : $this->normalize_multi_values( $value );

		if ( is_wp_error( $value ) ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'Source user %1$s local_board could not be read: %2$s The destination was not changed.', $source_id, $value->get_error_message() ),
				array( 'source_user_id' => $source_id )
			);
			return;
		}

		$source_values       = $value;
		$destination_value   = implode( ', ', $source_values );
		$destination_user_id = (int) $destination_user->ID;
		$current_raw_value   = get_user_meta( $destination_user_id, self::DESTINATION_LOCAL_BOARD_META, true );
		$current_value       = $this->format_group_number( $current_raw_value );

		if ( ! is_array( $current_raw_value ) && ! is_wp_error( $current_value ) && $current_value === $destination_value ) {
			++$stats['unchanged'];
			return;
		}

		$allowed_values = $this->get_local_board_choices();
		$invalid_values = array_values( array_diff( $source_values, $allowed_values ) );
		if ( ! empty( $invalid_values ) ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'Source user %1$s local_board contains destination choice(s) that do not exist: %2$s. The destination was not changed.', $source_id, implode( ', ', $invalid_values ) ),
				array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
			);
			return;
		}

		$this->update_acf_user_field(
			$destination_user_id,
			self::DESTINATION_LOCAL_BOARD_META,
			self::DESTINATION_LOCAL_BOARD_FIELD,
			$destination_value
		);

		$stored_value = $this->format_group_number( get_user_meta( $destination_user_id, self::DESTINATION_LOCAL_BOARD_META, true ) );
		if ( is_wp_error( $stored_value ) || $stored_value !== $destination_value ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'The local board update could not be verified for destination user %d.', $destination_user_id ),
				array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
			);
			return;
		}

		++$stats['updated'];
		$this->report(
			'updated',
			sprintf( 'Updated local board for destination user %d.', $destination_user_id ),
			array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
		);
	}

	private function sync_sales_staff( array $source_user, $destination_user, array &$stats ) {
		$source_id = isset( $source_user['id'] ) ? (int) $source_user['id'] : 'unknown';
		$value     = $this->get_source_field( $source_user, self::SOURCE_SALES_STAFF_FIELD, self::SOURCE_SALES_STAFF_KEY );
		$value     = is_wp_error( $value ) ? $value : $this->normalize_single_value( $value );

		if ( is_wp_error( $value ) ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'Source user %1$s sales_staff could not be read: %2$s The destination was not changed.', $source_id, $value->get_error_message() ),
				array( 'source_user_id' => $source_id )
			);
			return;
		}

		$destination_user_id = (int) $destination_user->ID;
		$current_value       = $this->normalize_single_value( get_user_meta( $destination_user_id, self::DESTINATION_SALES_STAFF_META, true ) );

		if ( ! is_wp_error( $current_value ) && $current_value === $value ) {
			++$stats['unchanged'];
			return;
		}

		$allowed_values = $this->get_sales_staff_choices();
		if ( '' !== $value && ! in_array( $value, $allowed_values, true ) ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'Source user %1$s sales_staff contains a destination choice that does not exist: %2$s. The destination was not changed.', $source_id, $value ),
				array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
			);
			return;
		}

		$this->update_acf_user_field(
			$destination_user_id,
			self::DESTINATION_SALES_STAFF_META,
			self::DESTINATION_SALES_STAFF_FIELD,
			$value
		);

		$stored_value = $this->normalize_single_value( get_user_meta( $destination_user_id, self::DESTINATION_SALES_STAFF_META, true ) );
		if ( is_wp_error( $stored_value ) || $stored_value !== $value ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'The sales staff update could not be verified for destination user %d.', $destination_user_id ),
				array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
			);
			return;
		}

		++$stats['updated'];
		$this->report(
			'updated',
			sprintf( 'Updated sales staff for destination user %d.', $destination_user_id ),
			array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
		);
	}

	private function sync_xprofile_text_field( array $source_user, $destination_user, array &$stats, $source_field_id, $destination_meta, $destination_field, $label ) {
		$source_id = isset( $source_user['id'] ) ? (int) $source_user['id'] : 'unknown';
		$value     = $this->get_source_xprofile_field( $source_user, $source_field_id );
		$value     = is_wp_error( $value ) ? $value : $this->normalize_single_value( $value );

		if ( is_wp_error( $value ) ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'Source user %1$s %2$s could not be read: %3$s The destination was not changed.', $source_id, $label, $value->get_error_message() ),
				array( 'source_user_id' => $source_id )
			);
			return;
		}

		$destination_user_id = (int) $destination_user->ID;
		$current_value       = $this->normalize_single_value( get_user_meta( $destination_user_id, $destination_meta, true ) );

		if ( ! is_wp_error( $current_value ) && $current_value === $value ) {
			++$stats['unchanged'];
			return;
		}

		$this->update_acf_user_field( $destination_user_id, $destination_meta, $destination_field, $value );

		$stored_value = $this->normalize_single_value( get_user_meta( $destination_user_id, $destination_meta, true ) );
		if ( is_wp_error( $stored_value ) || $stored_value !== $value ) {
			++$stats['failed'];
			$this->report(
				'error',
				sprintf( 'The %1$s update could not be verified for destination user %2$d.', $label, $destination_user_id ),
				array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
			);
			return;
		}

		++$stats['updated'];
		$this->report(
			'updated',
			sprintf( 'Updated %1$s for destination user %2$d.', $label, $destination_user_id ),
			array( 'destination_user_id' => $destination_user_id, 'source_user_id' => $source_id )
		);
	}

	private function update_acf_user_field( $user_id, $meta_key, $field_key, $value ) {
		if ( function_exists( 'update_field' ) ) {
			update_field( $field_key, $value, 'user_' . $user_id );
		}

		if ( get_user_meta( $user_id, $meta_key, true ) !== $value ) {
			update_user_meta( $user_id, $meta_key, $value );
		}

		if ( get_user_meta( $user_id, '_' . $meta_key, true ) !== $field_key ) {
			update_user_meta( $user_id, '_' . $meta_key, $field_key );
		}
	}

	private function get_local_board_choices() {
		return $this->get_destination_choices(
			self::DESTINATION_LOCAL_BOARD_FIELD,
			array(
				'Atlanta',
				'Boston RT2',
				'Boston RT3',
				'Boston RT4',
				'Boston RT5',
				'Boston RT6',
				'Charlotte',
				'Chicago I',
				'Chicago II',
				'Chief Security Executive',
				'Cincinnati',
				'Cleveland',
				'Columbus I',
				'Columbus II',
				'Detroit I',
				'Detroit II',
				'Greenville',
				'Innkeepers (Owners)',
				'Innkeepers (General Managers)',
				'SCMEP - Columbia',
				'Steel Warehouse',
			)
		);
	}

	private function get_sales_staff_choices() {
		return $this->get_destination_choices(
			self::DESTINATION_SALES_STAFF_FIELD,
			array(
				'Rob Grabill',
				'Chuck Smith',
				'Kevin Minton',
				'Frances Marvel',
				'Sherri Bohlke',
				'JoEllen Belcher',
				'Wayne Cooper',
				'Eric Schoonover',
				'Brittany Hochradel',
			)
		);
	}

	private function get_destination_choices( $field_key, array $fallback ) {
		$field = false;
		if ( function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $field_key );
		}
		if ( ! is_array( $field ) && function_exists( 'get_field_object' ) ) {
			$field = get_field_object( $field_key, false, false, false );
		}

		if ( is_array( $field ) && isset( $field['choices'] ) && is_array( $field['choices'] ) ) {
			return array_map( 'strval', array_keys( $field['choices'] ) );
		}

		return $fallback;
	}

	private function get_source_field( array $source_user, $field_name, $field_key ) {
		foreach ( array( $field_name, $field_key ) as $selector ) {
			if (
				isset( $source_user['acf'] )
				&& is_array( $source_user['acf'] )
				&& array_key_exists( $selector, $source_user['acf'] )
			) {
				return $source_user['acf'][ $selector ];
			}

			if ( array_key_exists( $selector, $source_user ) ) {
				return $source_user[ $selector ];
			}
		}

		return new WP_Error(
			'cen_sync_missing_source_field',
			sprintf( 'Neither %1$s nor %2$s was present in the source REST response.', $field_name, $field_key )
		);
	}

	private function get_source_xprofile_field( array $source_user, $field_id ) {
		if (
			isset( $source_user['_cen_xprofile'] )
			&& is_array( $source_user['_cen_xprofile'] )
		) {
			// The BuddyBoss members endpoint omits individual xProfile fields that
			// have no saved data. A returned member with no entry for this field is
			// therefore an exposed empty value, not a failed source read.
			return array_key_exists( (int) $field_id, $source_user['_cen_xprofile'] )
				? $source_user['_cen_xprofile'][ (int) $field_id ]
				: '';
		}

		return new WP_Error(
			'cen_sync_missing_source_xprofile_field',
			sprintf( 'xProfile field %d was not present in the source BuddyBoss REST response.', (int) $field_id )
		);
	}

	private function format_group_number( $value ) {
		$formatted = $this->normalize_multi_values( $value );
		return is_wp_error( $formatted ) ? $formatted : implode( ', ', $formatted );
	}

	private function normalize_single_value( $value ) {
		if ( null === $value || false === $value || '' === $value || array() === $value ) {
			return '';
		}

		if ( is_array( $value ) ) {
			if ( array_key_exists( 'value', $value ) ) {
				$value = $value['value'];
			} elseif ( 1 === count( $value ) ) {
				$value = reset( $value );
			} else {
				return new WP_Error( 'cen_sync_invalid_single_value', 'The field contains multiple values but the destination accepts only one.' );
			}
		}

		if ( ! is_scalar( $value ) ) {
			return new WP_Error( 'cen_sync_invalid_single_value', 'The field value has an unsupported format.' );
		}

		return trim( sanitize_text_field( (string) $value ) );
	}

	private function normalize_multi_values( $value ) {
		if ( null === $value || false === $value || '' === $value || array() === $value ) {
			return array();
		}

		if ( is_string( $value ) || is_numeric( $value ) ) {
			$items = explode( ',', (string) $value );
		} elseif ( is_array( $value ) ) {
			$items = array_key_exists( 'value', $value ) ? array( $value ) : $value;
		} else {
			return new WP_Error( 'cen_sync_invalid_multi_value', 'The field value has an unsupported format.' );
		}

		$formatted = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				if ( array_key_exists( 'value', $item ) && is_scalar( $item['value'] ) ) {
					$item = $item['value'];
				} elseif ( array_key_exists( 'label', $item ) && is_scalar( $item['label'] ) ) {
					$item = $item['label'];
				} else {
					return new WP_Error( 'cen_sync_invalid_multi_value', 'A selected value has an unsupported format.' );
				}
			}

			if ( ! is_scalar( $item ) ) {
				return new WP_Error( 'cen_sync_invalid_multi_value', 'A selection contains an unsupported value.' );
			}

			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' !== $item && ! in_array( $item, $formatted, true ) ) {
				$formatted[] = $item;
			}
		}

		return $formatted;
	}

	public function run( CEN_Community_Sync_API_Client $client, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'email'      => '',
				'batch_size' => 100,
			)
		);

		$stats           = self::empty_stats();
		$requested_email = strtolower( sanitize_email( $options['email'] ) );
		if ( $options['email'] !== '' && ! is_email( $requested_email ) ) {
			return new WP_Error( 'cen_sync_invalid_requested_email', 'The --email value is not a valid email address.' );
		}

		$page        = 1;
		$total_pages = 1;

		do {
			$result = $client->get_users_page( $page, $requested_email, $options['batch_size'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$total_pages = $result['total_pages'];
			$this->report( 'batch', sprintf( 'Checking source batch %1$d of %2$d (%3$d record(s)).', $page, $total_pages, count( $result['users'] ) ), array( 'page' => $page ) );
			$batch_stats = $this->check_batch( $result['users'], $requested_email );

			foreach ( $batch_stats as $key => $value ) {
				$stats[ $key ] += $value;
			}

			++$page;
		} while ( $page <= $total_pages );

		if ( $requested_email !== '' && 0 === $stats['examined'] ) {
			++$stats['not_found'];
			$this->report( 'not_found', sprintf( '%s was not found on the source site.', $requested_email ), array( 'email' => $requested_email ) );
		}

		return $stats;
	}

	private function report( $level, $message, array $context = array() ) {
		call_user_func( $this->reporter, $level, $message, $context );
	}
}
