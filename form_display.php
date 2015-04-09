<?php

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class GFFormDisplay {

	public static $submission = array();
	private static $init_scripts = array();

	const ON_PAGE_RENDER       = 1;
	const ON_CONDITIONAL_LOGIC = 2;

	public static function process_form( $form_id ) {

		GFCommon::log_debug( "GFFormDisplay::process_form(): Starting to process form (#{$form_id}) submission." );

		//reading form metadata
		$form = GFAPI::get_form( $form_id );

		if ( ! $form['is_active'] || $form['is_trash'] ) {
			return;
		}

		if ( rgar( $form, 'requireLogin' ) ) {
			if ( ! is_user_logged_in() ) {
				return;
			}
			check_admin_referer( 'gform_submit_' . $form_id, '_gform_submit_nonce_' . $form_id );
		}

		//pre process action
		do_action( 'gform_pre_process', $form );
		do_action( "gform_pre_process_{$form['id']}", $form );

		$lead = array();

		$field_values = RGForms::post( 'gform_field_values' );

		$confirmation_message = '';

		$source_page_number = self::get_source_page( $form_id );
		$page_number        = $source_page_number;
		$target_page        = self::get_target_page( $form, $page_number, $field_values );

		GFCommon::log_debug( "GFFormDisplay::process_form(): Source page number: {$source_page_number}. Target page number: {$target_page}." );

		//Loading files that have been uploaded to temp folder
		$files = GFCommon::json_decode( stripslashes( RGForms::post( 'gform_uploaded_files' ) ) );
		if ( ! is_array( $files ) ) {
			$files = array();
		}

		RGFormsModel::$uploaded_files[ $form_id ] = $files;

		$saving_for_later = rgpost( 'gform_save' ) ? true : false;

		$is_valid = true;


		$failed_validation_page = $page_number;

		//don't validate when going to previous page or saving for later
		if ( ! $saving_for_later && ( empty( $target_page ) || $target_page >= $page_number ) ) {
			$is_valid = self::validate( $form, $field_values, $page_number, $failed_validation_page );
		}

		$log_is_valid = $is_valid ? 'Yes' : 'No';
		GFCommon::log_debug( "GFFormDisplay::process_form(): After validation. Is submission valid? {$log_is_valid}." );

		//Upload files to temp folder when saving for later, going to the next page or when submitting the form and it failed validation
		if ( $saving_for_later || $target_page >= $page_number || ( $target_page == 0 && ! $is_valid ) ) {
			if ( ! empty( $_FILES ) ) {
				GFCommon::log_debug( 'GFFormDisplay::process_form(): Uploading files...' );
				//Uploading files to temporary folder
				$files = self::upload_files( $form, $files );

				RGFormsModel::$uploaded_files[ $form_id ] = $files;
			}
		}

		// Load target page if it did not fail validation or if going to the previous page
		if ( ! $saving_for_later && $is_valid ) {
			$page_number = $target_page;
		} else {
			$page_number = $failed_validation_page;
		}

		$confirmation = '';
		if ( ( $is_valid && $page_number == 0 ) || $saving_for_later ) {

			$ajax = isset( $_POST['gform_ajax'] );

			//adds honeypot field if configured
			if ( rgar( $form, 'enableHoneypot' ) ) {
				$form['fields'][] = self::get_honeypot_field( $form );
			}

			$failed_honeypot = rgar( $form, 'enableHoneypot' ) && ! self::validate_honeypot( $form );

			if ( $failed_honeypot ) {

				GFCommon::log_debug( 'GFFormDisplay::process_form(): Failed Honeypot validation. Displaying confirmation and aborting.' );

				//display confirmation but doesn't process the form when honeypot fails
				$confirmation = self::handle_confirmation( $form, $lead, $ajax );
				$is_valid     = false;
			} elseif ( ! $saving_for_later ) {

				GFCommon::log_debug( 'GFFormDisplay::process_form(): Submission is valid. Moving forward.' );

				$form = self::update_confirmation( $form );

				//pre submission action
				do_action( 'gform_pre_submission', $form );
				do_action( "gform_pre_submission_{$form['id']}", $form );

				//pre submission filter
				$form = apply_filters( "gform_pre_submission_filter_{$form['id']}", apply_filters( 'gform_pre_submission_filter', $form ) );

				//handle submission
				$confirmation = self::handle_submission( $form, $lead, $ajax );

				//after submission hook
				do_action( 'gform_after_submission', $lead, $form );
				do_action( "gform_after_submission_{$form['id']}", $lead, $form );

			} elseif ( $saving_for_later ) {
				GFCommon::log_debug( 'GFFormDisplay::process_form(): Saving for later.' );
				$lead = GFFormsModel::get_current_lead();
				$form = self::update_confirmation( $form, $lead, 'form_saved' );

				$confirmation = rgar( $form['confirmation'], 'message' );
				$nl2br        = rgar( $form['confirmation'], 'disableAutoformat' ) ? false : true;
				$confirmation = GFCommon::replace_variables( $confirmation, $form, $lead, false, true, $nl2br );

				$form_unique_id = GFFormsModel::get_form_unique_id( $form_id );
				$ip             = GFFormsModel::get_ip();
				$source_url     = GFFormsModel::get_current_page_url();
				$resume_token   = rgpost( 'gform_resume_token' );
				$resume_token   = GFFormsModel::save_incomplete_submission( $form, $lead, $field_values, $page_number, $files, $form_unique_id, $ip, $source_url, $resume_token );

				$notifications_to_send = GFCommon::get_notifications_to_send( 'form_saved', $form, $lead );

				$log_notification_event = empty( $notifications_to_send ) ? 'No notifications to process' : 'Processing notifications';
				GFCommon::log_debug( "GFFormDisplay::process_form(): {$log_notification_event} for form_saved event." );

				foreach ( $notifications_to_send as $notification ) {
					if ( isset( $notification['isActive'] ) && ! $notification['isActive'] ) {
						GFCommon::log_debug( "GFFormDisplay::process_form(): Notification is inactive, not processing notification (#{$notification['id']} - {$notification['name']})." );
						continue;
					}
					$notification['message'] = self::replace_save_variables( $notification['message'], $form, $resume_token );
					GFCommon::send_notification( $notification, $form, $lead );
				}
				self::set_submission_if_null( $form_id, 'saved_for_later', true );
				self::set_submission_if_null( $form_id, 'resume_token', $resume_token );
				GFCommon::log_debug( 'GFFormDisplay::process_form(): Saved incomplete submission.' );

			}

			if ( is_array( $confirmation ) && isset( $confirmation['redirect'] ) ){
				header( "Location: {$confirmation["redirect"]}" );
				do_action( 'gform_post_submission', $lead, $form );
				do_action( "gform_post_submission_{$form["id"]}", $lead, $form );
				exit;
			}
		}



		if ( ! isset( self::$submission[ $form_id ] ) ) {
			self::$submission[ $form_id ] = array();
		}

		self::set_submission_if_null( $form_id, 'is_valid', $is_valid );
		self::set_submission_if_null( $form_id, 'form', $form );
		self::set_submission_if_null( $form_id, 'lead', $lead );
		self::set_submission_if_null( $form_id, 'confirmation_message', $confirmation );
		self::set_submission_if_null( $form_id, 'page_number', $page_number );
		self::set_submission_if_null( $form_id, 'source_page_number', $source_page_number );

		do_action( 'gform_post_process', $form, $page_number, $source_page_number );
		do_action( "gform_post_process_{$form['id']}", $form, $page_number, $source_page_number );

	}

	private static function set_submission_if_null( $form_id, $key, $val ) {
		if ( ! isset( self::$submission[ $form_id ][ $key ] ) ) {
			self::$submission[ $form_id ][ $key ] = $val;
		}
	}


	private static function upload_files( $form, $files ) {

		$form_upload_path = GFFormsModel::get_upload_path( $form['id'] );

		//Creating temp folder if it does not exist
		$target_path = $form_upload_path . '/tmp/';
		wp_mkdir_p( $target_path );
		GFCommon::recursive_add_index_file( $form_upload_path );

		foreach ( $form['fields'] as $field ) {
			$input_name = "input_{$field->id}";

			//skip fields that are not file upload fields or that don't have a file to be uploaded or that have failed validation
			$input_type = RGFormsModel::get_input_type( $field );
			if ( ! in_array( $input_type, array( 'fileupload', 'post_image' ) ) || $field->multipleFiles ) {
				continue;
			}

			if ( $field->failed_validation || empty( $_FILES[ $input_name ]['name'] ) ) {
				GFCommon::log_debug( "GFFormDisplay::upload_files(): Skipping field: {$field->label}({$field->id} - {$field->type})." );
				continue;
			}

			$file_info = RGFormsModel::get_temp_filename( $form['id'], $input_name );
			GFCommon::log_debug( 'GFFormDisplay::upload_files(): Temp file info: ' . print_r( $file_info, true ) );

			if ( $file_info && move_uploaded_file( $_FILES[ $input_name ]['tmp_name'], $target_path . $file_info['temp_filename'] ) ) {
				GFFormsModel::set_permissions( $target_path . $file_info['temp_filename'] );
				$files[ $input_name ] = $file_info['uploaded_filename'];
				GFCommon::log_debug( "GFFormDisplay::upload_files(): File uploaded successfully: {$file_info['uploaded_filename']}" );
			} else {
				GFCommon::log_error( "GFFormDisplay::upload_files(): File could not be uploaded: tmp_name: {$_FILES[ $input_name ]['tmp_name']} - target location: " . $target_path . $file_info['temp_filename'] );
			}
		}
		return $files;
	}

	public static function get_state( $form, $field_values ) {
		$product_fields = array();
		foreach ( $form['fields'] as $field ) {
			/* @var GF_Field $field */
			if ( GFCommon::is_product_field( $field->type ) || $field->type == 'donation' ) {
				$value = RGFormsModel::get_field_value( $field, $field_values, false );
				$value = $field->get_value_default_if_empty( $value );

				switch ( $field->inputType ) {
					case 'calculation' :
					case 'singleproduct' :
					case 'hiddenproduct' :
						$price = ! is_array( $value ) || empty( $value[ $field->id . '.2' ] ) ? $field->basePrice : $value[ $field->id . '.2' ];
						if ( empty( $price ) ) {
							$price = 0;
						}

						$price = GFCommon::to_number( $price );

						$product_name = ! is_array( $value ) || empty( $value[ $field->id . '.1' ] ) ? $field->label : $value[ $field->id . '.1' ];

						$product_fields[ $field->id . '.1' ] = wp_hash( GFFormsModel::maybe_trim_input( $product_name, $form['id'], $field ) );
						$product_fields[ $field->id . '.2' ] = wp_hash( GFFormsModel::maybe_trim_input( $price, $form['id'], $field ) );
						break;

					case 'singleshipping' :
						$price = ! empty( $value ) ? $value : $field->basePrice;
						$price = ! empty( $price ) ? GFCommon::to_number( $price ) : 0;

						$product_fields[ $field->id ] = wp_hash( GFFormsModel::maybe_trim_input( $price, $form['id'], $field ) );
						break;
					case 'radio' :
					case 'select' :
						$product_fields[ $field->id ] = array();
						foreach ( $field->choices as $choice ) {
							$field_value = ! empty( $choice['value'] ) || $field->enableChoiceValue ? $choice['value'] : $choice['text'];
							if ( $field->enablePrice ) {
								$price = rgempty( 'price', $choice ) ? 0 : GFCommon::to_number( rgar( $choice, 'price' ) );
								$field_value .= '|' . $price;
							}

							$product_fields[ $field->id ][] = wp_hash( GFFormsModel::maybe_trim_input( $field_value, $form['id'], $field ) );
						}
						break;
					case 'checkbox' :
						$index = 1;
						foreach ( $field->choices as $choice ) {
							$field_value = ! empty( $choice['value'] ) || $field->enableChoiceValue ? $choice['value'] : $choice['text'];
							if ( $field->enablePrice ) {
								$price = rgempty( 'price', $choice ) ? 0 : GFCommon::to_number( rgar( $choice, 'price' ) );
								$field_value .= '|' . $price;
							}

							if ( $index % 10 == 0 ) { //hack to skip numbers ending in 0. so that 5.1 doesn't conflict with 5.10
								$index ++;
							}

							$product_fields[ $field->id . '.' . $index ++ ] = wp_hash( GFFormsModel::maybe_trim_input( $field_value, $form['id'], $field ) );
						}
						break;

				}
			}
		}

		$hash     = json_encode( $product_fields );
		$checksum = wp_hash( crc32( $hash ) );

		return base64_encode( json_encode( array( $hash, $checksum ) ) );

	}

	private static function has_pages( $form ) {
		return GFCommon::has_pages( $form );
	}

	private static function has_character_counter( $form ) {

		if ( ! is_array( $form['fields'] ) ) {
			return false;
		}

		foreach ( $form['fields'] as $field ) {
			if ( $field->maxLength && ! $field->inputMask ) {
				return true;
			}
		}

		return false;
	}

	private static function has_placeholder( $form ) {

		if ( ! is_array( $form['fields'] ) ) {
			return false;
		}

		foreach ( $form['fields'] as $field ) {
			if ( $field->placeholder != '' ) {
				return true;
			}
			if ( is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					if ( rgar( $input, 'placeholder' ) != '' ) {
						return true;
					}
				}
			}
		}

		return false;
	}


	private static function has_enhanced_dropdown( $form ) {

		if ( ! is_array( $form['fields'] ) ) {
			return false;
		}

		foreach ( $form['fields'] as $field ) {
			if ( in_array( RGFormsModel::get_input_type( $field ), array( 'select', 'multiselect' ) ) && $field->enableEnhancedUI ) {
				return true;
			}
		}

		return false;
	}

	private static function has_password_strength( $form ) {

		if ( ! is_array( $form['fields'] ) ) {
			return false;
		}

		foreach ( $form['fields'] as $field ) {
			if ( $field->type == 'password' && $field->passwordStrengthEnabled ) {
				return true;
			}
		}

		return false;
	}

	private static function has_other_choice( $form ) {

		if ( ! is_array( $form['fields'] ) ) {
			return false;
		}

		foreach ( $form['fields'] as $field ) {
			if ( $field->type == 'radio' && $field->enableOtherChoice ) {
				return true;
			}
		}

		return false;
	}


	public static function get_target_page( $form, $current_page, $field_values ) {
		$page_number = RGForms::post( "gform_target_page_number_{$form['id']}" );
		$page_number = ! is_numeric( $page_number ) ? 1 : $page_number;

		$direction = $page_number >= $current_page ? 1 : - 1;

		//Finding next page that is not hidden by conditional logic
		while ( RGFormsModel::is_page_hidden( $form, $page_number, $field_values ) ) {
			$page_number += $direction;
		}

		//If all following pages are hidden, submit the form
		if ( $page_number > self::get_max_page_number( $form ) ) {
			$page_number = 0;
		}

		return $page_number;
	}

	public static function get_source_page( $form_id ) {
		$page_number = RGForms::post( "gform_source_page_number_{$form_id}" );

		return ! is_numeric( $page_number ) ? 1 : $page_number;
	}

	public static function set_current_page( $form_id, $page_number ) {
		self::$submission[ $form_id ]['page_number'] = $page_number;
	}

	public static function get_current_page( $form_id ) {
		$page_number = isset( self::$submission[ $form_id ] ) ? intval( self::$submission[ $form_id ]['page_number'] ) : 1;

		return $page_number;
	}

	private static function is_page_active( $form_id, $page_number ) {
		return intval( self::get_current_page( $form_id ) ) == intval( $page_number );
	}

	/**
	 * Determine if the last page for the current form object is being submitted or rendered (depending on the provided $mode).
	 *
	 * @param  array  $form A Gravity Forms form object.
	 * @param  string $mode Mode to check for: 'submit' or 'render'
	 *
	 * @return boolean
	 */
	public static function is_last_page( $form, $mode = 'submit' ) {

		$page_number  = self::get_source_page( $form['id'] );
		$field_values = GFForms::post( 'gform_field_values' );
		$target_page  = self::get_target_page( $form, $page_number, $field_values );

		if ( $mode == 'render' ) {
			$is_valid     = rgars( self::$submission, "{$form['id']}/is_valid" );
			$is_last_page = $is_valid && $target_page == self::get_max_page_number( $form );
		} else {
			$is_last_page = (string) $target_page === '0';
		}

		return $is_last_page;
	}

	private static function get_limit_period_dates( $period ) {
		if ( empty( $period ) ) {
			return array( 'start_date' => null, 'end_date' => null );
		}

		switch ( $period ) {
			case 'day' :
				return array(
					'start_date' => gmdate( 'Y-m-d' ),
					'end_date'   => gmdate( 'Y-m-d 23:59:59' )
				);
				break;

			case 'week' :
				return array(
					'start_date' => gmdate( 'Y-m-d', strtotime( 'Monday this week' ) ),
					'end_date'   => gmdate( 'Y-m-d 23:59:59', strtotime( 'next Sunday' ) )
				);
				break;

			case 'month' :
				$month_start = gmdate( 'Y-m-1' );

				return array(
					'start_date' => $month_start,
					'end_date'   => gmdate( 'Y-m-d H:i:s', strtotime( "{$month_start} +1 month -1 second" ) )
				);
				break;

			case 'year' :
				return array(
					'start_date' => gmdate( 'Y-1-1' ),
					'end_date'   => gmdate( 'Y-12-31 23:59:59' )
				);
				break;
		}
	}

	public static function get_form( $form_id, $display_title = true, $display_description = true, $force_display = false, $field_values = null, $ajax = false, $tabindex = 1 ) {

		// provides the ability to modify the options used to display the form
		$form_args = apply_filters( 'gform_form_args', compact( 'form_id', 'display_title', 'display_description', 'force_display', 'field_values', 'ajax', 'tabindex' ) );
		extract( $form_args );

		//looking up form id by form name
		if ( ! is_numeric( $form_id ) ) {
			$form_id = RGFormsModel::get_form_id( $form_id );
		}

		//reading form metadata
		$form = RGFormsModel::get_form_meta( $form_id, true );

		$action = remove_query_arg( 'gf_token' );

		//disable ajax if form has a reCAPTCHA field (not supported).
		if ( $ajax && self::has_recaptcha_field( $form ) ) {
			$ajax = false;
		}

		if ( isset( $_POST['gform_send_resume_link'] ) ) {
			$save_email_confirmation = self::handle_save_email_confirmation( $form, $ajax );
			if ( is_wp_error( $save_email_confirmation ) ) { // Failed email validation
				$resume_token = rgpost( 'gform_resume_token' );
				$incomplete_submission_info = GFFormsModel::get_incomplete_submission_values( rgpost( 'gform_resume_token' ) );
				if ( $incomplete_submission_info['form_id'] == $form_id ) {
					$submission_details_json                = $incomplete_submission_info['submission'];
					$submission_details                     = json_decode( $submission_details_json, true );
					$partial_entry                          = $submission_details['partial_entry'];
					$form = self::update_confirmation( $form, $partial_entry, 'form_saved' );
					$confirmation_message = rgar( $form['confirmation'], 'message' );
					$nl2br        = rgar( $form['confirmation'], 'disableAutoformat' ) ? false : true;
					$confirmation_message = GFCommon::replace_variables( $confirmation_message, $form, $partial_entry, false, true, $nl2br );
					return self::handle_save_confirmation( $form, $resume_token, $confirmation_message, $ajax );
				}
			} else {
				return $save_email_confirmation;
			}
		}

		$is_postback          = false;
		$is_valid             = true;
		$confirmation_message = '';

		//If form was submitted, read variables set during form submission procedure
		$submission_info = isset( self::$submission[ $form_id ] ) ? self::$submission[ $form_id ] : false;

		if ( rgar( $submission_info, 'saved_for_later' ) == true ) {
			$resume_token         = $submission_info['resume_token'];
			$confirmation_message = rgar( $submission_info, 'confirmation_message' );

			return self::handle_save_confirmation( $form, $resume_token, $confirmation_message, $ajax );
		}

		$partial_entry = $submitted_values = false;
		if ( isset( $_GET['gf_token'] ) ) {
			$incomplete_submission_info = GFFormsModel::get_incomplete_submission_values( $_GET['gf_token'] );
			if ( $incomplete_submission_info['form_id'] == $form_id ) {
				$submission_details_json                = $incomplete_submission_info['submission'];
				$submission_details                     = json_decode( $submission_details_json, true );
				$partial_entry                          = $submission_details['partial_entry'];
				$submitted_values						= $submission_details['submitted_values'];
				$field_values                           = $submission_details['field_values'];
				GFFormsModel::$unique_ids[ $form_id ]     = $submission_details['gform_unique_id'];
				GFFormsModel::$uploaded_files[ $form_id ] = $submission_details['files'];
				self::set_submission_if_null( $form_id, 'resuming_incomplete_submission', true );
				self::set_submission_if_null( $form_id, 'form_id', $form_id );
				self::set_submission_if_null( $form_id, 'page_number', $submission_details['page_number'] );
			}
		}

		if ( ! is_array( $partial_entry ) ) {
			$view_counter_disabled = apply_filters( 'gform_disable_view_counter', false );
			$view_counter_disabled = apply_filters( "gform_disable_view_counter_{$form_id}", $view_counter_disabled );

			if ( $submission_info ) {
				$is_postback          = true;
				$is_valid             = rgar( $submission_info, 'is_valid' ) || rgar( $submission_info, 'is_confirmation' );
				$form                 = $submission_info['form'];
				$lead                 = $submission_info['lead'];
				$confirmation_message = rgget( 'confirmation_message', $submission_info );

				if ( $is_valid && ! RGForms::get( 'is_confirmation', $submission_info ) ) {

					if ( $submission_info['page_number'] == 0 ) {
						//post submission hook
						do_action( 'gform_post_submission', $lead, $form );
						do_action( "gform_post_submission_{$form['id']}", $lead, $form );
					} else {
						//change page hook
						do_action( 'gform_post_paging', $form, $submission_info['source_page_number'], $submission_info['page_number'] );
						do_action( "gform_post_paging_{$form['id']}", $form, $submission_info['source_page_number'], $submission_info['page_number'] );
					}
				}
			} elseif ( ! current_user_can( 'administrator' ) && ! $view_counter_disabled ) {
				RGFormsModel::insert_form_view( $form_id, $_SERVER['REMOTE_ADDR'] );
			}
		}

		if ( rgar( $form, 'enableHoneypot' ) ) {
			$form['fields'][] = self::get_honeypot_field( $form );
		}

		//Fired right before the form rendering process. Allow users to manipulate the form object before it gets displayed in the front end
		$form = apply_filters( "gform_pre_render_{$form_id}", apply_filters( 'gform_pre_render', $form, $ajax, $field_values ), $ajax, $field_values );

		if ( $form == null ) {
			return '<p>' . __( 'Oops! We could not locate your form.', 'gravityforms' ) . '</p>';
		}

		$has_pages = self::has_pages( $form );

		//calling tab index filter
		GFCommon::$tab_index = apply_filters( "gform_tabindex_{$form_id}", apply_filters( 'gform_tabindex', $tabindex, $form ), $form );

		//Don't display inactive forms
		if ( ! $force_display && ! $is_postback ) {

			$form_info = RGFormsModel::get_form( $form_id );
			if ( empty( $form_info ) || ! $form_info->is_active ) {
				return '';
			}

			// If form requires login, check if user is logged in
			if ( rgar( $form, 'requireLogin' ) ) {
				if ( ! is_user_logged_in() ) {
					return empty( $form['requireLoginMessage'] ) ? '<p>' . __( 'Sorry. You must be logged in to view this form.', 'gravityforms' ) . '</p>' : '<p>' . GFCommon::gform_do_shortcode( $form['requireLoginMessage'] ) . '</p>';
				}
			}
		}

		// show the form regardless of the following validations when force display is set to true
		if ( ! $force_display || $is_postback ) {

			$form_schedule_validation = self::validate_form_schedule( $form );

			// if form schedule validation fails AND this is not a postback, display the validation error
			// if form schedule validation fails AND this is a postback, make sure is not a valid submission (enables display of confirmation message)
			if ( ( $form_schedule_validation && ! $is_postback ) || ( $form_schedule_validation && $is_postback && ! $is_valid ) ) {
				return $form_schedule_validation;
			}

			$entry_limit_validation = self::validate_entry_limit( $form );

			// refer to form schedule condition notes above
			if ( ( $entry_limit_validation && ! $is_postback ) || ( $entry_limit_validation && $is_postback && ! $is_valid ) ) {
				return $entry_limit_validation;
			}
		}

		$form_string = '';

		//When called via a template, this will enqueue the proper scripts
		//When called via a shortcode, this will be ignored (too late to enqueue), but the scripts will be enqueued via the enqueue_scripts event
		self::enqueue_form_scripts( $form, $ajax );

		$is_form_editor = GFCommon::is_form_editor();
		$is_entry_detail = GFCommon::is_entry_detail();
		$is_admin = $is_form_editor || $is_entry_detail;

		if ( empty( $confirmation_message ) ) {
			$wrapper_css_class = GFCommon::get_browser_class() . ' gform_wrapper';

			if ( ! $is_valid ) {
				$wrapper_css_class .= ' gform_validation_error';
			}

			//Hiding entire form if conditional logic is on to prevent 'hidden' fields from blinking. Form will be set to visible in the conditional_logic.php after the rules have been applied.
			$style                    = self::has_conditional_logic( $form ) ? "style='display:none'" : '';
			$custom_wrapper_css_class = ! empty( $form['cssClass'] ) ? " {$form['cssClass']}_wrapper" : '';
			$form_string .= "
                <div class='{$wrapper_css_class}{$custom_wrapper_css_class}' id='gform_wrapper_$form_id' " . $style . '>';

			$default_anchor = $has_pages || $ajax ? true : false;
			$use_anchor     = apply_filters( "gform_confirmation_anchor_{$form['id']}", apply_filters( 'gform_confirmation_anchor', $default_anchor ) );
			if ( $use_anchor !== false ) {
				$form_string .= "<a id='gf_$form_id' name='gf_$form_id' class='gform_anchor' ></a>";
				$action .= "#gf_$form_id";
			}
			$target = $ajax ? "target='gform_ajax_frame_{$form_id}'" : '';

			$form_css_class = ! empty( $form['cssClass'] ) ? "class='{$form['cssClass']}'" : '';

			$action = esc_url( $action );
			$form_string .= apply_filters( "gform_form_tag_{$form_id}", apply_filters( 'gform_form_tag', "<form method='post' enctype='multipart/form-data' {$target} id='gform_{$form_id}' {$form_css_class} action='{$action}'>", $form ), $form );

			if ( $display_title || $display_description ) {
				$form_string .= "
                        <div class='gform_heading'>";
				if ( $display_title ) {
					$form_string .= "
                            <h3 class='gform_title'>" . $form['title'] . '</h3>';
				}
				if ( $display_description ) {
					$form_string .= "
                            <span class='gform_description'>" . rgar( $form, 'description' ) . '</span>';
				}
				$form_string .= '
                        </div>';
			}

			$current_page = self::get_current_page( $form_id );

			if ( $has_pages && ! $is_admin ) {

				if ( $form['pagination']['type'] == 'percentage' ) {
					$form_string .= self::get_progress_bar( $form, $form_id, $confirmation_message );
				} else if ( $form['pagination']['type'] == 'steps' ) {
					$form_string .= "
                    <div id='gf_page_steps_{$form_id}' class='gf_page_steps'>";
					$pages = isset( $form['pagination']['pages'] ) ? $form['pagination']['pages'] : array();
					for ( $i = 0, $count = sizeof( $pages ); $i < $count; $i ++ ) {
						$step_number    = $i + 1;
						$active_class   = $step_number == $current_page ? ' gf_step_active' : '';
						$first_class    = $i == 0 ? ' gf_step_first' : '';
						$last_class     = $i + 1 == $count ? ' gf_step_last' : '';
						$complete_class = $step_number < $current_page ? ' gf_step_completed' : '';
						$previous_class = $step_number + 1 == $current_page ? ' gf_step_previous' : '';
						$next_class     = $step_number - 1 == $current_page ? ' gf_step_next' : '';
						$pending_class  = $step_number > $current_page ? ' gf_step_pending' : '';
						$classes        = 'gf_step' . $active_class . $first_class . $last_class . $complete_class . $previous_class . $next_class . $pending_class;

						$classes = GFCommon::trim_all( $classes );

						$form_string .= "
                        <div id='gf_step_{$form_id}_{$step_number}' class='{$classes}'><span class='gf_step_number'>{$step_number}</span>&nbsp;<span class='gf_step_label'>{$pages[ $i ]}</span></div>";
					}

					$form_string .= "
                        <div class='gf_step_clear'></div>
                    </div>";
				}
			}

			if ( $is_postback && ! $is_valid ) {
				$validation_message = "<div class='validation_error'>" . __( 'There was a problem with your submission.', 'gravityforms' ) . ' ' . __( 'Errors have been highlighted below.', 'gravityforms' ) . '</div>';
				$form_string .= apply_filters( "gform_validation_message_{$form['id']}", apply_filters( 'gform_validation_message', $validation_message, $form ), $form );
			}

			$form_string .= "
                        <div class='gform_body'>";

			//add first page if this form has any page fields
			if ( $has_pages ) {
				$style = self::is_page_active( $form_id, 1 ) ? '' : "style='display:none;'";
				$class = ! empty( $form['firstPageCssClass'] ) ? " {$form['firstPageCssClass']}" : '';
				$form_string .= "<div id='gform_page_{$form_id}_1' class='gform_page{$class}' {$style}>
                                    <div class='gform_page_fields'>";
			}

			$description_class = rgar( $form, 'descriptionPlacement' ) == 'above' ? 'description_above' : 'description_below';
			$sublabel_class = rgar( $form, 'subLabelPlacement' ) == 'above' ? 'form_sublabel_above' : 'form_sublabel_below';


			$form_string .= "<ul id='gform_fields_{$form_id}' class='" . GFCommon::get_ul_classes( $form ) . "'>";

			if ( is_array( $form['fields'] ) ) {
				foreach ( $form['fields'] as $field ) {
					/* @var GF_Field $field */
					$field->conditionalLogicFields = self::get_conditional_logic_fields( $form, $field->id );

					if ( is_array( $submitted_values ) ) {
						$field_value = $submitted_values[ $field->id ];
					} else {
						$field_value = GFFormsModel::get_field_value( $field, $field_values );
					}

					$form_string .= self::get_field( $field, $field_value, false, $form, $field_values );
				}
			}
			$form_string .= '
                            </ul>';

			if ( $has_pages ) {
				$previous_button = self::get_form_button( $form['id'], "gform_previous_button_{$form['id']}", $form['lastPageButton'], __( 'Previous', 'gravityforms' ), 'gform_previous_button', __( 'Previous Page', 'gravityforms' ), self::get_current_page( $form_id ) - 1 );
				$previous_button = apply_filters( 'gform_previous_button', $previous_button, $form );
				$previous_button = apply_filters( "gform_previous_button_{$form['id']}", $previous_button, $form );
				$form_string .= '</div>' . self::gform_footer( $form, 'gform_page_footer ' . $form['labelPlacement'], $ajax, $field_values, $previous_button, $display_title, $display_description, $is_postback ) . '
                        </div>'; //closes gform_page
			}

			$form_string .= '</div>'; //closes gform_body

			//suppress form footer for multi-page forms (footer will be included on the last page
			if ( ! $has_pages ) {
				$form_string .= self::gform_footer( $form, 'gform_footer ' . $form['labelPlacement'], $ajax, $field_values, '', $display_title, $display_description, $tabindex );
			}

			$form_string .= '
                        </form>
                        </div>';

			if ( $ajax && $is_postback ) {
				global $wp_scripts;

				$form_string = apply_filters(
					'gform_ajax_iframe_content', '<!DOCTYPE html><html><head>' .
					"<meta charset='UTF-8' /></head><body class='GF_AJAX_POSTBACK'>" . $form_string . '</body></html>'
				);

			}

			if ( $ajax && ! $is_postback ) {
				$spinner_url     = apply_filters( "gform_ajax_spinner_url_{$form_id}", apply_filters( 'gform_ajax_spinner_url', GFCommon::get_base_url() . '/images/spinner.gif', $form ), $form );
				$scroll_position = array( 'default' => '', 'confirmation' => '' );

				if ( $use_anchor !== false ) {
					$scroll_position['default']      = is_numeric( $use_anchor ) ? 'jQuery(document).scrollTop(' . intval( $use_anchor ) . ');' : "jQuery(document).scrollTop(jQuery('#gform_wrapper_{$form_id}').offset().top);";
					$scroll_position['confirmation'] = is_numeric( $use_anchor ) ? 'jQuery(document).scrollTop(' . intval( $use_anchor ) . ');' : "jQuery(document).scrollTop(jQuery('#gforms_confirmation_message_{$form_id}').offset().top);";
				}

				$iframe_style = defined( 'GF_DEBUG' ) && GF_DEBUG ? 'display:block;width:600px;height:300px;border:1px solid #eee;' : 'display:none;width:0px;height:0px;';

				$form_string .= "
                <iframe style='{$iframe_style}' src='about:blank' name='gform_ajax_frame_{$form_id}' id='gform_ajax_frame_{$form_id}'></iframe>
                <script type='text/javascript'>" . apply_filters( 'gform_cdata_open', '' ) . '' .
					'jQuery(document).ready(function($){' .
						"gformInitSpinner( {$form_id}, '{$spinner_url}' );" .
						"jQuery('#gform_ajax_frame_{$form_id}').load( function(){" .
							"var contents = jQuery(this).contents().find('*').html();" .
							"var is_postback = contents.indexOf('GF_AJAX_POSTBACK') >= 0;" .
							'if(!is_postback){return;}' .
							"var form_content = jQuery(this).contents().find('#gform_wrapper_{$form_id}');" .
							"var is_confirmation = jQuery(this).contents().find('#gform_confirmation_wrapper_{$form_id}').length > 0;" .
							"var is_redirect = contents.indexOf('gformRedirect(){') >= 0;" .
							'var is_form = form_content.length > 0 && ! is_redirect && ! is_confirmation;' .
							'if(is_form){' .
								"jQuery('#gform_wrapper_{$form_id}').html(form_content.html());" .
				                "setTimeout( function() { /* delay the scroll by 50 milliseconds to fix a bug in chrome */ {$scroll_position['default']} }, 50 );" .
								"if(window['gformInitDatepicker']) {gformInitDatepicker();}" .
								"if(window['gformInitPriceFields']) {gformInitPriceFields();}" .
								"var current_page = jQuery('#gform_source_page_number_{$form_id}').val();" .
								"gformInitSpinner( {$form_id}, '{$spinner_url}' );" .
								"jQuery(document).trigger('gform_page_loaded', [{$form_id}, current_page]);" .
								"window['gf_submitting_{$form_id}'] = false;" .
							'}' .
							'else if(!is_redirect){' .
								"var confirmation_content = jQuery(this).contents().find('#gforms_confirmation_message_{$form_id}').html();" .
								'if(!confirmation_content){' .
									'confirmation_content = contents;' .
								'}' .
								'setTimeout(function(){' .
									"jQuery('#gform_wrapper_{$form_id}').replaceWith('<' + 'div id=\'gforms_confirmation_message_{$form_id}\' class=\'gform_confirmation_message_{$form_id} gforms_confirmation_message\'' + '>' + confirmation_content + '<' + '/div' + '>');" .
									"{$scroll_position['confirmation']}" .
									"jQuery(document).trigger('gform_confirmation_loaded', [{$form_id}]);" .
									"window['gf_submitting_{$form_id}'] = false;" .
									'}, 50);' .
								'}' .
							'else{' .
								"jQuery('#gform_{$form_id}').append(contents);" .
								"if(window['gformRedirect']) {gformRedirect();}" .
							'}' .
							"jQuery(document).trigger('gform_post_render', [{$form_id}, current_page]);" .
						'} );' .
					'} );' . apply_filters( 'gform_cdata_close', '' ) . '</script>';
			}

			$is_first_load = ! $is_postback;

			if ( ( ! $ajax || $is_first_load ) ) {

				self::register_form_init_scripts( $form, $field_values, $ajax );

				if ( apply_filters( 'gform_init_scripts_footer', false ) ) {
					add_action( 'wp_footer', create_function( '', 'GFFormDisplay::footer_init_scripts(' . $form['id'] . ');' ), 20 );
					add_action( 'gform_preview_footer', create_function( '', 'GFFormDisplay::footer_init_scripts(' . $form['id'] . ');' ) );
				} else {
					$form_string .= self::get_form_init_scripts( $form );
					$form_string .= "<script type='text/javascript'>" . apply_filters( 'gform_cdata_open', '' ) . " jQuery(document).ready(function(){jQuery(document).trigger('gform_post_render', [{$form_id}, {$current_page}]) } ); " . apply_filters( 'gform_cdata_close', '' ) . '</script>';
				}
			}

			return apply_filters( "gform_get_form_filter_{$form['id']}", apply_filters( 'gform_get_form_filter', $form_string, $form ), $form );
		} else {
			$progress_confirmation = '';

			//check admin setting for whether the progress bar should start at zero
			$start_at_zero = rgars( $form, 'pagination/display_progressbar_on_confirmation' );
			$start_at_zero = apply_filters( 'gform_progressbar_start_at_zero', $start_at_zero, $form );

			//show progress bar on confirmation
			if ( $start_at_zero && $has_pages && ! $is_admin && ( $form['confirmation']['type'] == 'message' && $form['pagination']['type'] == 'percentage' ) ) {
				$progress_confirmation = self::get_progress_bar( $form, $form_id, $confirmation_message );
				if ( $ajax ) {
					$progress_confirmation = apply_filters( 'gform_ajax_iframe_content', "<!DOCTYPE html><html><head><meta charset='UTF-8' /></head><body class='GF_AJAX_POSTBACK'>" . $progress_confirmation . '</body></html>' );
				}
			} else {
				//return regular confirmation message
				if ( $ajax ) {
					$progress_confirmation = apply_filters( 'gform_ajax_iframe_content', "<!DOCTYPE html><html><head><meta charset='UTF-8' /></head><body class='GF_AJAX_POSTBACK'>" . $confirmation_message . '</body></html>' );
				} else {
					$progress_confirmation = $confirmation_message;
				}
			}

			return $progress_confirmation;
		}
	}

	public static function footer_init_scripts( $form_id ) {
		global $_init_forms;

		$form         = RGFormsModel::get_form_meta( $form_id );
		$form_string  = self::get_form_init_scripts( $form );
		$current_page = self::get_current_page( $form_id );
		$form_string .= "<script type='text/javascript'>" . apply_filters( 'gform_cdata_open', '' ) . " jQuery(document).ready(function(){jQuery(document).trigger('gform_post_render', [{$form_id}, {$current_page}]) } ); " . apply_filters( 'gform_cdata_close', '' ) . '</script>';

		$form_string = apply_filters( 'gform_footer_init_scripts_filter', $form_string, $form, $current_page );
		$form_string = apply_filters( 'gform_footer_init_scripts_filter_' . $form['id'], $form_string, $form, $current_page );

		if ( ! isset( $_init_forms[ $form_id ] ) ) {
			echo $form_string;
			if ( ! is_array( $_init_forms ) ) {
				$_init_forms = array();
			}

			$_init_forms[ $form_id ] = true;
		}
	}

	public static function add_init_script( $form_id, $script_name, $location, $script ) {
		$key = $script_name . '_' . $location;

		if ( ! isset( self::$init_scripts[ $form_id ] ) ) {
			self::$init_scripts[ $form_id ] = array();
		}

		//add script if it hasn't been added before
		if ( ! array_key_exists( $key, self::$init_scripts[ $form_id ] ) ) {
			self::$init_scripts[ $form_id ][ $key ] = array( 'location' => $location, 'script' => $script );
		}
	}

	private static function get_form_button( $form_id, $button_input_id, $button, $default_text, $class, $alt, $target_page_number, $onclick = '' ) {

		$tabindex = GFCommon::get_tabindex();

		$input_type = 'submit';

		if ( ! empty( $target_page_number ) ) {
			$onclick    = "onclick='jQuery(\"#gform_target_page_number_{$form_id}\").val(\"{$target_page_number}\"); {$onclick} jQuery(\"#gform_{$form_id}\").trigger(\"submit\",[true]); '";
			$input_type = 'button';
		} else {
			// prevent multiple form submissions when button is pressed multiple times
			if ( GFFormsModel::is_html5_enabled() ) {
				$set_submitting = "if( !jQuery(\"#gform_{$form_id}\")[0].checkValidity || jQuery(\"#gform_{$form_id}\")[0].checkValidity()){window[\"gf_submitting_{$form_id}\"]=true;}";
			} else {
				$set_submitting = "window[\"gf_submitting_{$form_id}\"]=true;";
			}

			$onclick_submit = $button['type'] == 'link' ? "jQuery(\"#gform_{$form_id}\").trigger(\"submit\",[true]);" : '';

			$onclick = "onclick='if(window[\"gf_submitting_{$form_id}\"]){return false;}  {$set_submitting} {$onclick} {$onclick_submit}'";
		}

		if ( $button['type'] == 'text' || $button['type'] == 'link' || empty( $button['imageUrl'] ) ) {
			$button_text = ! empty( $button['text'] ) ? $button['text'] : $default_text;
			if ( $button['type'] == 'link' ) {
				$button_input = "<a href='javascript:void(0);' id='{$button_input_id}_link' class='{$class}' alt='{$alt}' title='{$alt}' {$tabindex} {$onclick}>{$button_text}</a>";
			} else {
				$class .= ' button';
				$button_input = "<input type='{$input_type}' id='{$button_input_id}' class='{$class}' value='" . esc_attr( $button_text ) . "' {$tabindex} {$onclick} />";
			}
		} else {
			$imageUrl     = $button['imageUrl'];
			$class .= ' gform_image_button';
			$button_input = "<input type='image' src='{$imageUrl}' id='{$button_input_id}' class='{$class}' alt='{$alt}' {$tabindex} {$onclick} />";
		}

		return $button_input;
	}

	private static function gform_footer( $form, $class, $ajax, $field_values, $previous_button, $display_title, $display_description, $tabindex = 1 ) {
		$form_id      = absint( $form['id'] );
		$footer       = "
        <div class='" . $class . "'>";
		$button_input = self::get_form_button( $form['id'], "gform_submit_button_{$form['id']}", $form['button'], __( 'Submit', 'gravityforms' ), 'gform_button', __( 'Submit', 'gravityforms' ), 0 );
		$button_input = apply_filters( 'gform_submit_button', $button_input, $form );
		$button_input = apply_filters( "gform_submit_button_{$form_id}", $button_input, $form );

		$save_button = rgars( $form, 'save/enabled' ) ? self::get_form_button( $form_id, "gform_save_{$form_id}", $form['save']['button'], rgars( $form, 'save/button/text' ), 'gform_save_link', rgars( $form, 'save/button/text' ), 0, "jQuery(\"#gform_save_{$form_id}\").val(1);" ) : '';

		$footer .= $previous_button . ' ' . $button_input . ' ' . $save_button;

		if ( $ajax ) {
			$footer .= "<input type='hidden' name='gform_ajax' value='" . esc_attr( "form_id={$form_id}&amp;title={$display_title}&amp;description={$display_description}&amp;tabindex={$tabindex}" ) . "' />";
		}

		$current_page     = self::get_current_page( $form_id );
		$next_page        = $current_page + 1;
		$next_page        = $next_page > self::get_max_page_number( $form ) ? 0 : $next_page;
		$field_values_str = is_array( $field_values ) ? http_build_query( $field_values ) : $field_values;
		$files_input      = '';
		if ( GFCommon::has_multifile_fileupload_field( $form ) || ! empty( RGFormsModel::$uploaded_files[ $form_id ] ) ) {
			$files       = ! empty( RGFormsModel::$uploaded_files[ $form_id ] ) ? GFCommon::json_encode( RGFormsModel::$uploaded_files[ $form_id ] ) : '';
			$files_input = "<input type='hidden' name='gform_uploaded_files' id='gform_uploaded_files_{$form_id}' value='" . str_replace( "'", '&#039;', $files ) . "' />";
		}
		$save_inputs = '';
		if ( rgars( $form, 'save/enabled' ) ) {
			$resume_token = isset( $_POST['gform_resume_token'] ) ? $_POST['gform_resume_token'] : rgget( 'gf_token' );
			$save_inputs  = "<input type='hidden' class='gform_hidden' name='gform_save' id='gform_save_{$form_id}' value='' />
                             <input type='hidden' class='gform_hidden' name='gform_resume_token' id='gform_resume_token_{$form_id}' value='{$resume_token}' />";
		}

		if ( rgar( $form, 'requireLogin' ) ) {
			$footer .= wp_nonce_field( 'gform_submit_' . $form_id, '_gform_submit_nonce_' . $form_id, true, false );
		}

		$unique_id = isset( self::$submission[ $form_id ] ) && rgar( self::$submission[ $form_id ], 'resuming_incomplete_submission' ) == true ? rgar( GFFormsModel::$unique_ids, $form_id ) : GFFormsModel::get_form_unique_id( $form_id );
		$footer .= "
            <input type='hidden' class='gform_hidden' name='is_submit_{$form_id}' value='1' />
            <input type='hidden' class='gform_hidden' name='gform_submit' value='{$form_id}' />
            {$save_inputs}
            <input type='hidden' class='gform_hidden' name='gform_unique_id' value='" . esc_attr( $unique_id ) . "' />
            <input type='hidden' class='gform_hidden' name='state_{$form_id}' value='" . self::get_state( $form, $field_values ) . "' />
            <input type='hidden' class='gform_hidden' name='gform_target_page_number_{$form_id}' id='gform_target_page_number_{$form_id}' value='" . esc_attr( $next_page ) . "' />
            <input type='hidden' class='gform_hidden' name='gform_source_page_number_{$form_id}' id='gform_source_page_number_{$form_id}' value='" . esc_attr( $current_page ) . "' />
            <input type='hidden' name='gform_field_values' value='" . esc_attr( $field_values_str ) . "' />
            {$files_input}
        </div>";

		return $footer;
	}

	private static function get_max_page_number( $form ) {
		$page_number = 0;
		foreach ( $form['fields'] as $field ) {
			if ( $field->type == 'page' ) {
				$page_number ++;
			}
		}

		return $page_number == 0 ? 0 : $page_number + 1;
	}


	private static function get_honeypot_field( $form ) {
		$max_id     = self::get_max_field_id( $form );
		$labels     = self::get_honeypot_labels();
		$properties = array( 'type' => 'honeypot', 'label' => $labels[ rand( 0, 3 ) ], 'id' => $max_id + 1, 'cssClass' => 'gform_validation_container', 'description' => __( 'This field is for validation purposes and should be left unchanged.', 'gravityforms' ) );
		$field      = GF_Fields::create( $properties );

		return $field;
	}

	private static function get_max_field_id( $form ) {
		$max = 0;
		foreach ( $form['fields'] as $field ) {
			if ( floatval( $field->id ) > $max ) {
				$max = floatval( $field->id );
			}
		}

		return $max;
	}

	private static function get_honeypot_labels() {
		return array( 'Name', 'Email', 'Phone', 'Comments' );
	}

	/**
	 * Used to determine the required validation result.
	 *
	 * @param GF_Field $field
	 * @param int $form_id
	 *
	 * @return bool
	 */
	public static function is_empty( $field, $form_id = 0 ) {

		if ( empty( $_POST[ 'is_submit_' . $field->formId ] ) ) {
			return true;
		}

		return $field->is_value_submission_empty( $form_id );
	}

	private static function validate_honeypot( $form ) {
		$honeypot_id = self::get_max_field_id( $form );

		return rgempty( "input_{$honeypot_id}" );
	}

	public static function handle_submission( $form, &$lead, $ajax = false ){

		$lead_id = apply_filters( "gform_entry_id_pre_save_lead{$form['id']}", apply_filters( 'gform_entry_id_pre_save_lead', null, $form ), $form );

		if ( ! empty( $lead_id ) ) {
			if ( empty( $lead ) ) {
				$lead = array();
			}
			$lead['id'] = $lead_id;
		}

		//creating entry in DB
		RGFormsModel::save_lead( $form, $lead );

		//reading entry that was just saved
		$lead = RGFormsModel::get_lead( $lead['id'] );

		$lead = GFFormsModel::set_entry_meta( $lead, $form );

		//if Akismet plugin is installed, run lead through Akismet and mark it as Spam when appropriate
		$is_spam = GFCommon::akismet_enabled( $form['id'] ) && GFCommon::is_akismet_spam( $form, $lead );
		$is_spam = apply_filters( 'gform_entry_is_spam', $is_spam, $form, $lead );
		$is_spam = apply_filters( "gform_entry_is_spam_{$form['id']}", $is_spam, $form, $lead );

		if ( GFCommon::spam_enabled( $form['id'] ) ) {
			GFCommon::log_debug( 'GFFormDisplay::handle_submission(): Akismet integration enabled OR gform_entry_is_spam hook in use.' );
			$log_is_spam = $is_spam ? 'Yes' : 'No';
			GFCommon::log_debug( "GFFormDisplay::handle_submission(): Is entry considered spam? {$log_is_spam}." );
		}

		if ( $is_spam ) {

			//marking entry as spam
			RGFormsModel::update_lead_property( $lead['id'], 'status', 'spam', false, true );
			$lead['status'] = 'spam';

		}

		do_action( 'gform_entry_created', $lead, $form );
		$lead = apply_filters( 'gform_entry_post_save', $lead, $form );

		RGFormsModel::set_current_lead( $lead );

		if ( ! $is_spam ) {
			GFCommon::create_post( $form, $lead );
			//send notifications
			GFCommon::send_form_submission_notifications( $form, $lead );
		}

		self::clean_up_files( $form );

		// remove incomplete submission and purge expired
		if ( rgars( $form, 'save/enabled' ) ) {
			GFFormsModel::delete_incomplete_submission( rgpost( 'gform_resume_token' ) );
			GFFormsModel::purge_expired_incomplete_submissions();
		}

		//display confirmation message or redirect to confirmation page
		return self::handle_confirmation( $form, $lead, $ajax );
	}

	public static function clean_up_files( $form ) {
		$unique_form_id = rgpost( 'gform_unique_id' );
		if ( ! ctype_alnum( $unique_form_id ) ) {
			return false;
		}
		$target_path = RGFormsModel::get_upload_path( $form['id'] ) . '/tmp/';
		$filename    = $target_path . $unique_form_id . '_input_*';
		$files       = glob( $filename );
		if ( is_array( $files ) ) {
			array_map( 'unlink', $files );
		}

		// clean up files from abandoned submissions older than 48 hours (30 days if Save and Continue is enabled)
		$files = glob( $target_path . '*' );
		if ( is_array( $files ) ) {
			$seconds_in_day = 24 * 60 * 60;
			$lifespan       = rgars( $form, 'save/enabled' ) ? $expiration_days = apply_filters( 'gform_incomplete_submissions_expiration_days', 30 ) * $seconds_in_day : 2 * $seconds_in_day;
			foreach ( $files as $file ) {
				if ( is_file( $file ) && time() - filemtime( $file ) >= $lifespan ) {
					unlink( $file );
				}
			}
		}
	}

	public static function handle_confirmation( $form, $lead, $ajax = false ) {

		GFCommon::log_debug( 'GFFormDisplay::handle_confirmation(): Sending confirmation.' );

		//run the function to populate the legacy confirmation format to be safe
		$form = self::update_confirmation( $form, $lead );

		if ( $form['confirmation']['type'] == 'message' ) {
			$default_anchor = self::has_pages( $form ) ? 1 : 0;
			$anchor         = apply_filters( "gform_confirmation_anchor_{$form['id']}", apply_filters( 'gform_confirmation_anchor', $default_anchor ) ) ? "<a id='gf_{$form['id']}' name='gf_{$form['id']}' class='gform_anchor' ></a>" : '';
			$nl2br          = rgar( $form['confirmation'], 'disableAutoformat' ) ? false : true;
			$cssClass       = rgar( $form, 'cssClass' );
			$confirmation   = empty( $form['confirmation']['message'] ) ? "{$anchor} " : "{$anchor}<div id='gform_confirmation_wrapper_{$form['id']}' class='gform_confirmation_wrapper {$cssClass}'><div id='gform_confirmation_message_{$form['id']}' class='gform_confirmation_message_{$form['id']} gform_confirmation_message'>" . GFCommon::replace_variables( $form['confirmation']['message'], $form, $lead, false, true, $nl2br ) . '</div></div>';
		} else {
			if ( ! empty( $form['confirmation']['pageId'] ) ) {
				$url = get_permalink( $form['confirmation']['pageId'] );

			} else {
				$url = GFCommon::replace_variables( trim( $form['confirmation']['url'] ), $form, $lead, false, true, true, 'text' );
			}

			$url_info = parse_url( $url );
			$query_string  = rgar( $url_info, 'query' );
			$dynamic_query = GFCommon::replace_variables( trim( $form['confirmation']['queryString'] ), $form, $lead, true, false, false, 'text' );
			$dynamic_query = str_replace( array( "\r", "\n" ), '', $dynamic_query );
			$query_string .= rgempty( 'query', $url_info ) || empty( $dynamic_query ) ? $dynamic_query : '&' . $dynamic_query;

			if ( ! empty( $url_info['fragment'] ) ) {
				$query_string .= '#' . rgar( $url_info, 'fragment' );
			}

			$url = isset( $url_info['scheme'] ) ? $url_info['scheme'] : 'http';
			$url .= '://' . rgar( $url_info, 'host' );
			if ( ! empty( $url_info['port'] ) ) {
				$url .= ':' . rgar( $url_info, 'port' );
			}

			$url .= rgar( $url_info, 'path' );
			if ( ! empty( $query_string ) ) {
				$url .= "?{$query_string}";
			}

			if ( headers_sent() || $ajax ) {
				//Perform client side redirect for AJAX forms, of if headers have already been sent
				$confirmation = self::get_js_redirect_confirmation( $url, $ajax );
			} else {
				$confirmation = array( 'redirect' => $url );
			}
		}

		$confirmation = apply_filters( "gform_confirmation_{$form['id']}", apply_filters( 'gform_confirmation', $confirmation, $form, $lead, $ajax ), $form, $lead, $ajax );

		if ( ! is_array( $confirmation ) ) {
			$confirmation = GFCommon::gform_do_shortcode( $confirmation ); //enabling shortcodes
		} else if ( headers_sent() || $ajax ) {
			//Perform client side redirect for AJAX forms, of if headers have already been sent
			$confirmation = self::get_js_redirect_confirmation( $confirmation['redirect'], $ajax ); //redirecting via client side
		}

		GFCommon::log_debug( 'GFFormDisplay::handle_confirmation(): Confirmation => ' . print_r( $confirmation, true ) );

		return $confirmation;
	}

	private static function get_js_redirect_confirmation( $url, $ajax ) {
		$confirmation = "<script type=\"text/javascript\">" . apply_filters( 'gform_cdata_open', '' ) . " function gformRedirect(){document.location.href='$url';}";
		if ( ! $ajax ) {
			$confirmation .= 'gformRedirect();';
		}

		$confirmation .= apply_filters( 'gform_cdata_close', '' ) . '</script>';

		return $confirmation;
	}

	public static function send_emails( $form, $lead ) {
		_deprecated_function( 'send_emails', '1.7', 'GFCommon::send_form_submission_notifications' );
		GFCommon::send_form_submission_notifications( $form, $lead );
	}

	public static function validate( &$form, $field_values, $page_number = 0, &$failed_validation_page = 0 ) {

		$form = apply_filters( 'gform_pre_validation_' . $form['id'], apply_filters( 'gform_pre_validation', $form ) );

		// validate form schedule
		if ( self::validate_form_schedule( $form ) ) {
			return false;
		}

		// validate entry limit
		if ( self::validate_entry_limit( $form ) ) {
			return false;
		}

		// Prevent tampering with the submitted form
		if ( empty( $_POST[ 'is_submit_' . $form['id'] ] ) ) {
			return false;
		}

		$is_valid = true;
		$all_fields_empty = true;
		foreach ( $form['fields'] as &$field ) {
			/* @var GF_Field $field */

			//If a page number is specified, only validates fields that are on current page
			$field_in_other_page = $page_number > 0 && $field->pageNumber != $page_number;

			//validate fields with 'no duplicate' functionality when they are present on pages before the current page.
			$validate_duplicate_feature = $field->noDuplicates && $page_number > 0 && $field->pageNumber <= $page_number;

			if ( $field_in_other_page && ! $validate_duplicate_feature ) {
				continue;
			}

			//ignore validation if field is hidden or admin only
			if ( RGFormsModel::is_field_hidden( $form, $field, $field_values ) || $field->adminOnly ) {
				continue;
			}

			$value = RGFormsModel::get_field_value( $field );

			$input_type = RGFormsModel::get_input_type( $field );

			if ( ! self::is_empty( $field, $form['id'] ) ){
				$all_fields_empty = false;
			}

			//display error message if field is marked as required and the submitted value is empty
			if ( $field->isRequired && self::is_empty( $field, $form['id'] ) ) {
				$field->failed_validation  = true;
				$field->validation_message = empty( $field->errorMessage ) ? __( 'This field is required.', 'gravityforms' ) : $field->errorMessage;
			} //display error if field does not allow duplicates and the submitted value already exists
			else if ( $field->noDuplicates && RGFormsModel::is_duplicate( $form['id'], $field, $value ) ) {
				$field->failed_validation = true;
				//set page number so the failed field displays if on multi-page form
				$failed_validation_page = $field->pageNumber;

				switch ( $input_type ) {
					case 'date' :
						$default_message = __( 'This date has already been taken. Please select a new date.', 'gravityforms' );
						break;

					default:
						$default_message = is_array( $value ) ? __( 'This field requires a unique entry and the values you entered have been already been used.', 'gravityforms' ) :
							sprintf( __( "This field requires a unique entry and '%s' has already been used", 'gravityforms' ), $value );
						break;
				}

				$field->validation_message = apply_filters( "gform_duplicate_message_{$form['id']}", apply_filters( 'gform_duplicate_message', $default_message, $form, $field, $value ), $form, $field, $value );

			} else {
				if ( self::failed_state_validation( $form['id'], $field, $value ) ) {
					$field->failed_validation  = true;
					$field->validation_message = in_array( $field->inputType, array( 'singleproduct', 'singleshipping', 'hiddenproduct' ) ) ? __( 'Please enter a valid value.', 'gravityforms' ) : __( 'Invalid selection. Please select one of the available choices.', 'gravityforms' );
				} else {
					$field->validate( $value, $form );
				}
			}

			$custom_validation_result = apply_filters( 'gform_field_validation', array( 'is_valid' => $field->failed_validation ? false : true, 'message' => $field->validation_message ), $value, $form, $field );
			$custom_validation_result = apply_filters( "gform_field_validation_{$form['id']}", $custom_validation_result, $value, $form, $field );
			$custom_validation_result = apply_filters( "gform_field_validation_{$form['id']}_{$field->id}", $custom_validation_result, $value, $form, $field );

			$field->failed_validation  = rgar( $custom_validation_result, 'is_valid' ) ? false : true;
			$field->validation_message = rgar( $custom_validation_result, 'message' );

			if ( $field->failed_validation ) {
				$is_valid = false;
			}
		}

		foreach ( $form['fields'] as &$field ) {
			//if all fields are empty, fail validation on all fields
			if ( $all_fields_empty ){
				$field->failed_validation = true;
				$field->validation_message = esc_html__( 'At least one field must be filled out', 'gravityforms' );
				$is_valid = false;
			}
		}

		$validation_result      = apply_filters( "gform_validation_{$form['id']}", apply_filters( 'gform_validation', array( 'is_valid' => $is_valid, 'form' => $form, 'failed_validation_page' => $failed_validation_page ) ) );
		$is_valid               = $validation_result['is_valid'];
		$form                   = $validation_result['form'];
		$failed_validation_page = $validation_result['failed_validation_page'];

		return $is_valid;
	}

	public static function failed_state_validation( $form_id, $field, $value ) {

		global $_gf_state;

		//if field can be populated dynamically, disable state validation
		if ( $field->allowsPrepopulate ) {
			return false;
		} else if ( ! GFCommon::is_product_field( $field->type && $field->type != 'donation' ) ) {
			return false;
		} else if ( ! in_array( $field->inputType, array( 'singleshipping', 'singleproduct', 'hiddenproduct', 'checkbox', 'radio', 'select' ) ) ) {
			return false;
		}

		if ( ! isset( $_gf_state ) ) {
			$state = json_decode( base64_decode( $_POST[ "state_{$form_id}" ] ), true );

			if ( ! $state || sizeof( $state ) != 2 ) {
				return true;
			}

			//making sure state wasn't tampered with by validating checksum
			$checksum = wp_hash( crc32( $state[0] ) );

			if ( $checksum !== $state[1] ) {
				return true;
			}

			$_gf_state = json_decode( $state[0], true );
		}

		if ( ! is_array( $value ) ) {
			$value = array( $field->id => $value );
		}

		foreach ( $value as $key => $input_value ) {
			$state = isset( $_gf_state[ $key ] ) ? $_gf_state[ $key ] : false;

			//converting price to a number for single product fields and single shipping fields
			if ( ( in_array( $field->inputType, array( 'singleproduct', 'hiddenproduct' ) ) && $key == $field->id . '.2' ) || $field->inputType == 'singleshipping' ) {
				$input_value = GFCommon::to_number( $input_value );
			}

			$hash = wp_hash( $input_value );

			if ( strlen( $input_value ) > 0 && $state !== false && ( ( is_array( $state ) && ! in_array( $hash, $state ) ) || ( ! is_array( $state ) && $hash != $state ) ) ) {
				return true;
			}
		}

		return false;
	}

	public static function enqueue_scripts() {
		global $wp_query;
		if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) {
			foreach ( $wp_query->posts as $post ) {
				$forms = self::get_embedded_forms( $post->post_content, $ajax );
				foreach ( $forms as $form ) {
					if ( isset( $form['id'] ) ) {
						self::enqueue_form_scripts( $form, $ajax );
					}
				}
			}
		}
	}

	public static function get_embedded_forms( $post_content, &$ajax ) {
		$forms = array();
		if ( preg_match_all( '/\[gravityform[s]? +.*?((id=.+?)|(name=.+?))\]/is', $post_content, $matches, PREG_SET_ORDER ) ) {
			$ajax = false;
			foreach ( $matches as $match ) {
				//parsing shortcode attributes
				$attr    = shortcode_parse_atts( $match[1] );
				$form_id = rgar( $attr, 'id' );
				if ( ! is_numeric( $form_id ) ) {
					$form_id = RGFormsModel::get_form_id( rgar( $attr, 'name' ) );
				}

				if ( ! empty( $form_id ) ){
					$forms[] = RGFormsModel::get_form_meta( $form_id );
					$ajax    = isset( $attr['ajax'] ) && strtolower( substr( $attr['ajax'], 0, 4 ) ) == 'true';
				}
			}
		}

		return $forms;
	}

	public static function enqueue_form_scripts( $form, $ajax = false ) {

		// adding pre enqueue scripts hook so that scripts can be added first if a need exists
		do_action( "gform_pre_enqueue_scripts_{$form['id']}", do_action( 'gform_pre_enqueue_scripts', $form, $ajax ), $ajax );

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';

		if ( ! get_option( 'rg_gforms_disable_css' ) ) {

			wp_enqueue_style( 'gforms_reset_css', GFCommon::get_base_url() . "/css/formreset{$min}.css", null, GFCommon::$version );

			if ( self::has_datepicker_field( $form ) ) {
				wp_enqueue_style( 'gforms_datepicker_css', GFCommon::get_base_url() . "/css/datepicker{$min}.css", null, GFCommon::$version );
			}

			wp_enqueue_style( 'gforms_formsmain_css', GFCommon::get_base_url() . "/css/formsmain{$min}.css", null, GFCommon::$version );
			wp_enqueue_style( 'gforms_ready_class_css', GFCommon::get_base_url() . "/css/readyclass{$min}.css", null, GFCommon::$version );
			wp_enqueue_style( 'gforms_browsers_css', GFCommon::get_base_url() . "/css/browsers{$min}.css", null, GFCommon::$version );

			if ( is_rtl() ) {
				wp_enqueue_style( 'gforms_rtl_css', GFCommon::get_base_url() . "/css/rtl{$min}.css", null, GFCommon::$version );
			}
		}

		if ( self::has_conditional_logic( $form ) ) {
			wp_enqueue_script( 'gform_conditional_logic' );
		}

		if ( self::has_datepicker_field( $form ) ) {
			wp_enqueue_script( 'gform_datepicker_init' );
		}

		if ( $ajax || self::has_price_field( $form ) || self::has_password_strength( $form ) || GFCommon::has_list_field( $form ) || GFCommon::has_credit_card_field( $form ) || self::has_conditional_logic( $form ) || self::has_currency_format_number_field( $form ) || self::has_calculation_field( $form ) ) {
			wp_enqueue_script( 'gform_gravityforms' );
		}

		if ( GFCommon::has_multifile_fileupload_field( $form ) ) {
			wp_enqueue_script( 'plupload-all' );
			GFCommon::localize_gform_gravityforms_multifile();
		}

		if ( self::has_enhanced_dropdown( $form ) || self::has_pages( $form ) || self::has_fileupload_field( $form ) ) {
			wp_enqueue_script( 'gform_json' );
			wp_enqueue_script( 'gform_gravityforms' );
		}

		if ( self::has_character_counter( $form ) ) {
			wp_enqueue_script( 'gform_textarea_counter' );
		}

		if ( self::has_input_mask( $form ) ) {
			wp_enqueue_script( 'gform_masked_input' );
		}

		if ( self::has_enhanced_dropdown( $form ) && ! wp_script_is( 'chosen' ) ) {
			wp_enqueue_script( 'gform_chosen' );
		}

		if ( self::has_enhanced_dropdown( $form ) ) {
			if ( wp_script_is( 'chosen', 'registered' ) ) {
				wp_enqueue_script( 'chosen' );
			} else {
				wp_enqueue_script( 'gform_chosen' );
			}
		}

		if ( self::has_placeholder( $form ) ) {
			wp_enqueue_script( 'gform_placeholder' );
		}

		do_action( 'gform_enqueue_scripts', $form, $ajax );
		do_action( "gform_enqueue_scripts_{$form['id']}", $form, $ajax );

		// enqueue jQuery every time form is displayed to allow 'gform_post_render' js hook
		// to be available to users even when GF is not using it
		wp_enqueue_script( 'jquery' );

	}

	private static $printed_scripts = array();

	public static function print_form_scripts( $form, $ajax ) {

		if ( ! get_option( 'rg_gforms_disable_css' ) ) {

			if ( ! wp_style_is( 'gforms_css' ) ) {

				$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';

				wp_enqueue_style( 'gforms_reset_css', GFCommon::get_base_url() . "/css/formreset{$min}.css", null, GFCommon::$version );
				wp_print_styles( array( 'gforms_reset_css' ) );

				wp_enqueue_style( 'gforms_formsmain_css', GFCommon::get_base_url() . "/css/formsmain{$min}.css", null, GFCommon::$version );
				wp_print_styles( array( 'gforms_formsmain_css' ) );

				wp_enqueue_style( 'gforms_ready_class_css', GFCommon::get_base_url() . "/css/readyclass{$min}.css", null, GFCommon::$version );
				wp_print_styles( array( 'gforms_ready_class_css' ) );

				wp_enqueue_style( 'gforms_browsers_css', GFCommon::get_base_url() . "/css/browsers{$min}.css", null, GFCommon::$version );
				wp_print_styles( array( 'gforms_browsers_css' ) );

				if ( self::has_datepicker_field( $form ) ) {
					wp_enqueue_style( 'gforms_datepicker_css', GFCommon::get_base_url() . "/css/datepicker{$min}.css", null, GFCommon::$version );
					wp_print_styles( array( 'gforms_datepicker_css' ) );
				}

				if ( is_rtl() ) {
					wp_enqueue_style( 'gforms_rtl_css', GFCommon::get_base_url() . "/css/rtl{$min}.css", null, GFCommon::$version );
					wp_print_styles( array( 'gforms_rtl_css' ) );
				}
			}
		}

		$scripts = array();

		if ( ( $ajax || self::has_enhanced_dropdown( $form ) || self::has_price_field( $form ) || self::has_password_strength( $form ) || self::has_pages( $form ) || self::has_password_strength( $form ) || GFCommon::has_list_field( $form ) || GFCommon::has_credit_card_field( $form ) || self::has_calculation_field( $form ) ) && ! wp_script_is( 'gform_gravityforms' ) ) {
			$scripts[] = 'gform_gravityforms';
		}

		if ( self::has_conditional_logic( $form ) && ! wp_script_is( 'gform_conditional_logic' ) ) {
			$scripts[] = 'gform_conditional_logic';
		}

		if ( self::has_datepicker_field( $form ) && ! wp_script_is( 'gform_datepicker_init' ) ) {
			$scripts[] = 'gform_datepicker_init';
		}

		if ( self::has_pages( $form ) && ! wp_script_is( 'gform_json' ) ) {
			$scripts[] = 'gform_json';
		}

		if ( self::has_character_counter( $form ) && ! wp_script_is( 'gform_textarea_counter' ) ) {
			$scripts[] = 'gform_textarea_counter';
		}

		if ( self::has_input_mask( $form ) && ! wp_script_is( 'gform_masked_input' ) ) {
			$scripts[] = 'gform_masked_input';
		}

		if ( self::has_enhanced_dropdown( $form ) && ! wp_script_is( 'gform_chosen' ) && ! wp_script_is( 'chosen' ) ) {
			if ( wp_script_is( 'chosen', 'registered' ) ) {
				$scripts[] = 'chosen';
			} else {
				$scripts[] = 'gform_chosen';
			}
		}

		if ( ! wp_script_is( 'jquery' ) ) {
			$scripts[] = 'jquery';
		}

		foreach ( $scripts as $script ) {
			wp_enqueue_script( $script );
		}

		wp_print_scripts( $scripts );

		if ( wp_script_is( 'gform_gravityforms' ) ) {
			echo '<script type="text/javascript"> ' . GFCommon::gf_global( false ) . ' </script>';
		}

	}

	public static function has_conditional_logic( $form ) {
		$has_conditional_logic = self::has_conditional_logic_legwork( $form );

		return apply_filters( 'gform_has_conditional_logic', $has_conditional_logic, $form );
	}

	private static function has_conditional_logic_legwork( $form ) {

		if ( empty( $form ) ) {
			return false;
		}

		if ( isset( $form['button']['conditionalLogic'] ) ) {
			return true;
		}

		if ( is_array( rgar( $form, 'fields' ) ) ) {
			foreach ( rgar( $form, 'fields' ) as $field ) {
				if ( ! empty( $field->conditionalLogic ) ) {
					return true;
				} else if ( isset( $field->nextButton ) && ! empty( $field->nextButton['conditionalLogic'] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function get_conditional_logic( $form, $field_values = array() ) {
		$logics            = '';
		$dependents        = '';
		$fields_with_logic = array();
		$default_values    = array();

		foreach ( $form['fields'] as $field ) {

			/* @var GF_Field $field */

			//use section's logic if one exists
			$section       = RGFormsModel::get_section( $form, $field->id );
			$section_logic = ! empty( $section ) ? rgar( $section, 'conditionalLogic' ) : null;

			$field_logic = $field->type != 'page' ? $field->conditionalLogic : null; //page break conditional logic will be handled during the next button click

			$next_button_logic = ! empty( $field->nextButton ) && ! empty( $field->nextButton['conditionalLogic'] ) ? $field->nextButton['conditionalLogic'] : null;

			if ( ! empty( $field_logic ) || ! empty( $next_button_logic ) ) {

				$field_section_logic = array( 'field' => $field_logic, 'nextButton' => $next_button_logic, 'section' => $section_logic );

				$logics .= $field->id . ': ' . GFCommon::json_encode( $field_section_logic ) . ',';

				$fields_with_logic[] = $field->id;

				$peers    = $field->type == 'section' ? GFCommon::get_section_fields( $form, $field->id ) : array( $field );
				$peer_ids = array();

				foreach ( $peers as $peer ) {
					$peer_ids[] = $peer->id;
				}

				$dependents .= $field->id . ': ' . GFCommon::json_encode( $peer_ids ) . ',';
			}

			//-- Saving default values so that they can be restored when toggling conditional logic ---
			$field_val  = '';
			$input_type = RGFormsModel::get_input_type( $field );

			//get parameter value if pre-populate is enabled
			if ( $field->allowsPrepopulate ) {
				if ( is_array( $field->inputs ) ) {
					$field_val = array();
					foreach ( $field->inputs as $input ) {
						$field_val[ "input_{$input['id']}" ] = RGFormsModel::get_parameter_value( rgar( $input, 'name' ), $field_values, $field );
					}
				} else if ( $input_type == 'time' ) { // maintained for backwards compatibility. The Time field now has an inputs array.
					$parameter_val = RGFormsModel::get_parameter_value( $field->inputName, $field_values, $field );
					if ( ! empty( $parameter_val ) && preg_match( '/^(\d*):(\d*) ?(.*)$/', $parameter_val, $matches ) ) {
						$field_val   = array();
						$field_val[] = esc_attr( $matches[1] ); //hour
						$field_val[] = esc_attr( $matches[2] ); //minute
						$field_val[] = rgar( $matches, 3 ); //am or pm
					}
				} else if ( $input_type == 'list' ) {
					$parameter_val = RGFormsModel::get_parameter_value( $field->inputName, $field_values, $field );
					$field_val     = is_array( $parameter_val ) ? $parameter_val : explode( ',', str_replace( '|', ',', $parameter_val ) );
				} else {
					$field_val = RGFormsModel::get_parameter_value( $field->inputName, $field_values, $field );
				}
			}

			//use default value if pre-populated value is empty
			$field_val = $field->get_value_default_if_empty( $field_val );

			if ( is_array( $field->choices ) && $input_type != 'list' ) {

				//radio buttons start at 0 and checkboxes start at 1
				$choice_index = $input_type == 'radio' ? 0 : 1;
				$is_pricing_field = GFCommon::is_pricing_field( $field->type );

				foreach ( $field->choices as $choice ) {

					if ( $input_type == 'checkbox' && ( $choice_index % 10 ) == 0 ){
						$choice_index++;
					}

					if ( rgar( $choice, 'isSelected' ) && $input_type == 'select' ) {
						$price = GFCommon::to_number( rgar( $choice, 'price' ) ) == false ? 0 : GFCommon::to_number( rgar( $choice, 'price' ) );
						$val = $is_pricing_field && $field->type != 'quantity' ? $choice['value'] . '|' . $price: $choice['value'];
						$default_values[ $field->id ] = $val;
					} else if ( rgar( $choice, 'isSelected' ) ) {
						if ( ! isset( $default_values[ $field->id ] ) ) {
							$default_values[ $field->id ] = array();
						}

						$default_values[ $field->id ][] = "choice_{$form['id']}_{$field->id}_{$choice_index}";
					}
					$choice_index ++;
				}
			} else if ( ! empty( $field_val ) ) {

				if ( GFFormsModel::get_input_type( $field ) == 'date' ) {
					//format date
					$format    = empty( $field->dateFormat ) ? 'mdy' : esc_attr( $field->dateFormat );
					$date_info = GFcommon::parse_date( $field_val, $format );
					switch ( $format ) {
						case 'mdy':
							$field_val = $date_info['month'] . '/' . $date_info['day'] . '/' . $date_info['year'];
							break;
						case 'dmy':
							$field_val = $date_info['day'] . '/' . $date_info['month'] . '/' . $date_info['year'];
							break;
						case 'ymd':
							$field_val = $date_info['year'] . '/' . $date_info['month'] . '/' . $date_info['day'];
							break;
					}
				}
				$default_values[ $field->id ] = $field_val;
			}
		}

		$button_conditional_script = '';

		//adding form button conditional logic if enabled
		if ( isset( $form['button']['conditionalLogic'] ) ) {
			$logics .= '0: ' . GFCommon::json_encode( array( 'field' => $form['button']['conditionalLogic'], 'section' => null ) ) . ',';
			$dependents .= '0: ' . GFCommon::json_encode( array( 0 ) ) . ',';
			$fields_with_logic[] = 0;

			$button_conditional_script = "jQuery('#gform_{$form['id']}').submit(" .
				"function(event, isButtonPress){" .
				"    var visibleButton = jQuery('.gform_next_button:visible, .gform_button:visible, .gform_image_button:visible');" .
				"    return visibleButton.length > 0 || isButtonPress == true;" .
				"}" .
				");";
		}

		if ( ! empty( $logics ) ) {
			$logics = substr( $logics, 0, strlen( $logics ) - 1 );
		} //removing last comma;

		if ( ! empty( $dependents ) ) {
			$dependents = substr( $dependents, 0, strlen( $dependents ) - 1 );
		} //removing last comma;

		$animation = rgar( $form, 'enableAnimation' ) ? '1' : '0';
		global $wp_locale;
		$number_format = $wp_locale->number_format['decimal_point'] == ',' ? 'decimal_comma' : 'decimal_dot';

		$str = "if(window['jQuery']){" .

			"if(!window['gf_form_conditional_logic'])" .
			"window['gf_form_conditional_logic'] = new Array();" .
			"window['gf_form_conditional_logic'][{$form['id']}] = {'logic' : {" . $logics . " }, 'dependents' : {" . $dependents . " }, 'animation' : " . $animation . " , 'defaults' : " . json_encode( $default_values ) . ' }; ' .
			"if(!window['gf_number_format'])" .
			"window['gf_number_format'] = '" . $number_format . "';" .

			"jQuery(document).ready(function(){" .
			"gf_apply_rules({$form['id']}, " . json_encode( $fields_with_logic ) . ', true);' .
			"jQuery('#gform_wrapper_{$form['id']}').show();" .
			"jQuery(document).trigger('gform_post_conditional_logic', [{$form['id']}, null, true]);" .
			$button_conditional_script .

			"} );" .

			"} ";

		return $str;
	}


	/**
	 * Enqueue and retrieve all inline scripts that should be executed when the form is rendered.
	 * Use add_init_script() function to enqueue scripts.
	 *
	 * @param array $form
	 * @param array $field_values
	 * @param bool  $is_ajax
	 */
	private static function register_form_init_scripts( $form, $field_values = array(), $is_ajax = false ) {

		// adding conditional logic script if conditional logic is configured for this form.
		// get_conditional_logic also adds the chosen script for the enhanced dropdown option.
		// if this form does not have conditional logic, add chosen script separately
		if ( self::has_conditional_logic( $form ) ) {
			self::add_init_script( $form['id'], 'conditional_logic', self::ON_PAGE_RENDER, self::get_conditional_logic( $form, $field_values ) );
		}

		//adding currency config if there are any product fields in the form
		if ( self::has_price_field( $form ) ) {
			self::add_init_script( $form['id'], 'pricing', self::ON_PAGE_RENDER, self::get_pricing_init_script( $form ) );
		}

		if ( self::has_password_strength( $form ) ) {
			$password_script = self::get_password_strength_init_script( $form );
			self::add_init_script( $form['id'], 'password', self::ON_PAGE_RENDER, $password_script );
		}

		if ( self::has_enhanced_dropdown( $form ) ) {
			$chosen_script = self::get_chosen_init_script( $form );
			self::add_init_script( $form['id'], 'chosen', self::ON_PAGE_RENDER, $chosen_script );
			self::add_init_script( $form['id'], 'chosen', self::ON_CONDITIONAL_LOGIC, $chosen_script );
		}

		if ( self::has_character_counter( $form ) ) {
			self::add_init_script( $form['id'], 'character_counter', self::ON_PAGE_RENDER, self::get_counter_init_script( $form ) );
		}

		if ( self::has_input_mask( $form ) ) {
			self::add_init_script( $form['id'], 'input_mask', self::ON_PAGE_RENDER, self::get_input_mask_init_script( $form ) );
		}

		if ( self::has_calculation_field( $form ) ) {
			self::add_init_script( $form['id'], 'calculation', self::ON_PAGE_RENDER, self::get_calculations_init_script( $form ) );
		}

		if ( self::has_currency_format_number_field( $form ) ) {
			self::add_init_script( $form['id'], 'currency_format', self::ON_PAGE_RENDER, self::get_currency_format_init_script( $form ) );
		}

		if ( self::has_currency_copy_values_option( $form ) ) {
			self::add_init_script( $form['id'], 'copy_values', self::ON_PAGE_RENDER, self::get_copy_values_init_script( $form ) );
		}

		if ( self::has_placeholder( $form ) ) {
			self::add_init_script( $form['id'], 'placeholders', self::ON_PAGE_RENDER, self::get_placeholders_init_script( $form ) );
		}

		if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				/* @var GF_Field $field */
				if ( is_subclass_of( $field, 'GF_Field' ) ) {
					$field->register_form_init_scripts( $form );
				}
			}
		}

		do_action( 'gform_register_init_scripts', $form, $field_values, $is_ajax );
		do_action( 'gform_register_init_scripts_' . $form['id'], $form, $field_values, $is_ajax );

	}

	public static function get_form_init_scripts( $form ) {

		$script_string = '';

		// temporary solution for output gf_global obj until wp min version raised to 3.3
		if ( wp_script_is( 'gform_gravityforms' ) ) {
			$gf_global_script = "if(typeof gf_global == 'undefined') " . GFCommon::gf_global( false );
		}

		/* rendering initialization scripts */
		$init_scripts = rgar( self::$init_scripts, $form['id'] );

		if ( ! empty( $init_scripts ) ) {
			$script_string =
				"<script type='text/javascript'>" . apply_filters( 'gform_cdata_open', '' ) . ' ';

			$script_string .= isset( $gf_global_script ) ? $gf_global_script : '';

			$script_string .=
				"jQuery(document).bind('gform_post_render', function(event, formId, currentPage){" .
				"if(formId == {$form['id']}) {";

			foreach ( $init_scripts as $init_script ) {
				if ( $init_script['location'] == self::ON_PAGE_RENDER ) {
					$script_string .= $init_script['script'];
				}
			}

			$script_string .=
				"} " . //keep the space. needed to prevent plugins from replacing }} with ]}
				"} );" .

				"jQuery(document).bind('gform_post_conditional_logic', function(event, formId, fields, isInit){";
			foreach ( $init_scripts as $init_script ) {
				if ( $init_script['location'] == self::ON_CONDITIONAL_LOGIC ) {
					$script_string .= $init_script['script'];
				}
			}

			$script_string .=

				"} );" . apply_filters( 'gform_cdata_close', '' ) . '</script>';
		}

		return $script_string;
	}

	public static function get_chosen_init_script( $form ) {
		$chosen_fields = array();
		foreach ( $form['fields'] as $field ) {
			$input_type = GFFormsModel::get_input_type( $field );
			if ( $field->enableEnhancedUI && in_array( $input_type, array( 'select', 'multiselect' ) ) ) {
				$chosen_fields[] = "#input_{$form['id']}_{$field->id}";
			}
		}

		return "gformInitChosenFields('" . implode( ',', $chosen_fields ) . "','" . esc_attr( apply_filters( "gform_dropdown_no_results_text_{$form['id']}", apply_filters( 'gform_dropdown_no_results_text', __( 'No results matched', 'gravityforms' ), $form['id'] ), $form['id'] ) ) . "');";
	}

	public static function get_currency_format_init_script( $form ) {
		$currency_fields = array();
		foreach ( $form['fields'] as $field ) {
			if ( $field->numberFormat == 'currency' ) {
				$currency_fields[] = "#input_{$form['id']}_{$field->id}";
			}
		}

		return "gformInitCurrencyFormatFields('" . implode( ',', $currency_fields ) . "');";
	}

	public static function get_copy_values_init_script( $form ) {
		$script = "jQuery('.copy_values_activated').on('click', function(){
                        var inputId = this.id.replace('_copy_values_activated', '');
                        jQuery('#' + inputId).toggle(!this.checked);
                    });";

		return $script;
	}

	public static function get_placeholders_init_script( $form ) {

		$script = "if(typeof Placeholders != 'undefined'){
                        Placeholders.enable();
                    }";

		return $script;
	}

	public static function get_counter_init_script( $form ) {

		$script = '';
		foreach ( $form['fields'] as $field ) {
			$max_length = $field->maxLength;
			$field_id   = "input_{$form['id']}_{$field->id}";
			if ( ! empty( $max_length ) && ! $field->adminOnly ) {
				$field_script =
					"jQuery('#{$field_id}').textareaCount(" .
					"    {" .
					"    'maxCharacterSize': {$max_length}," .
					"    'originalStyle': 'ginput_counter'," .
					"    'displayFormat' : '#input " . __( 'of', 'gravityforms' ) . ' #max ' . __( 'max characters', 'gravityforms' ) . "'" .
					"    } );";

				$script .= apply_filters( "gform_counter_script_{$form['id']}", apply_filters( 'gform_counter_script', $field_script, $form['id'], $field_id, $max_length ), $form['id'], $field_id, $max_length );
			}
		}

		return $script;
	}

	public static function get_pricing_init_script( $form ) {

		if ( ! class_exists( 'RGCurrency' ) ) {
			require_once( 'currency.php' );
		}

		return "if(window[\"gformInitPriceFields\"]) jQuery(document).ready(function(){gformInitPriceFields();} );";
	}

	public static function get_password_strength_init_script( $form ) {

		$field_script = "if(!window['gf_text']){window['gf_text'] = new Array();} window['gf_text']['password_blank'] = '" . __( 'Strength indicator', 'gravityforms' ) . "'; window['gf_text']['password_mismatch'] = '" . __( 'Mismatch', 'gravityforms' ) . "';window['gf_text']['password_bad'] = '" . __( 'Bad', 'gravityforms' ) . "'; window['gf_text']['password_short'] = '" . __( 'Short', 'gravityforms' ) . "'; window['gf_text']['password_good'] = '" . __( 'Good', 'gravityforms' ) . "'; window['gf_text']['password_strong'] = '" . __( 'Strong', 'gravityforms' ) . "';";

		foreach ( $form['fields'] as $field ) {
			if ( $field->type == 'password' && $field->passwordStrengthEnabled ) {
				$field_id = "input_{$form['id']}_{$field->id}";
				$field_script .= "gformShowPasswordStrength(\"$field_id\");";
			}
		}

		return $field_script;
	}

	public static function get_input_mask_init_script( $form ) {

		$script_str = '';

		foreach ( $form['fields'] as $field ) {

			if ( ! $field->inputMask || ! $field->inputMaskValue ) {
				continue;
			}

			$mask   = $field->inputMaskValue;
			$script = "jQuery('#input_{$form['id']}_{$field->id}').mask('" . esc_js( $mask ) . "').bind('keypress', function(e){if(e.which == 13){jQuery(this).blur();} } );";

			$script_str .= apply_filters( "gform_input_mask_script_{$form['id']}", apply_filters( 'gform_input_mask_script', $script, $form['id'], $field->id, $mask ), $form['id'], $field->id, $mask );
		}

		return $script_str;
	}

	public static function get_calculations_init_script( $form ) {
		require_once( GFCommon::get_base_path() . '/currency.php' );

		$formula_fields = array();

		foreach ( $form['fields'] as $field ) {

			if ( ! $field->enableCalculation || ! $field->calculationFormula ) {
				continue;
			}

			$formula_fields[] = array( 'field_id' => $field->id, 'formula' => $field->calculationFormula, 'rounding' => $field->calculationRounding );

		}

		if ( empty( $formula_fields ) ) {
			return '';
		}

		$script = self::get_number_formats_script( $form );
		$script .= 'new GFCalc(' . $form['id'] . ', ' . GFCommon::json_encode( $formula_fields ) . ');';

		return $script;
	}

	/**
	 * Generates a map of fields IDs and their corresponding number formats used by the GFCalc JS object for correctly
	 * converting field values to clean numbers.
	 *
	 * - Number fields have a 'numberFormat' setting (w/ UI).
	 * - Single-input product fields (i.e. 'singleproduct', 'calculation', 'price' and 'hiddenproduct') should default to
	 *   the number format of the configured currency.
	 * - All other product fields will default to 'decimal_dot' for the number format.
	 * - All other fields will have no format (false) and inherit the format of the formula field when the formula is
	 *   calculated.
	 *
	 * @param mixed $form
	 * @return string
	 */
	public static function get_number_formats_script( $form ) {

		$number_formats = array();
		$currency       = RGCurrency::get_currency( GFCommon::get_currency() );

		foreach ( $form['fields'] as $field ) {

			// default format is false, fields with no format will inherit the format of the formula field when calculated
			$format = false;

			switch ( GFFormsModel::get_input_type( $field ) ) {
				case 'number':
					$format = $field->numberFormat ? $field->numberFormat : 'decimal_dot';
					break;
				case 'singleproduct':
				case 'calculation':
				case 'price':
				case 'hiddenproduct':
				case 'singleshipping':
					$format = $currency['decimal_separator'] == ',' ? 'decimal_comma' : 'decimal_dot';
					break;
				default:

					// we check above for all single-input product types, for all other products, assume decimal format
					if ( in_array( $field->type, array( 'product', 'option', 'shipping' ) ) ) {
						$format = 'decimal_dot';
					}
			}

			$number_formats[ $field->id ] = $format;

		}

		return 'gf_global["number_formats"][' . $form['id'] . '] = ' . json_encode( $number_formats ) . ';';
	}

	private static function has_datepicker_field( $form ) {
		if ( is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {

				if ( RGFormsModel::get_input_type( $field ) == 'date' && $field->dateType == 'datepicker' ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function has_price_field( $form ) {
		$price_fields = GFCommon::get_fields_by_type( $form, array( 'product', 'donation' ) );

		return ! empty( $price_fields );
	}

	private static function has_fileupload_field( $form ) {
		if ( is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				$input_type = RGFormsModel::get_input_type( $field );
				if ( in_array( $input_type, array( 'fileupload', 'post_image' ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function has_currency_format_number_field( $form ) {
		if ( is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				$input_type = RGFormsModel::get_input_type( $field );
				if ( $input_type == 'number' && $field->numberFormat == 'currency' ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function has_currency_copy_values_option( $form ) {
		if ( is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				if ( $field->enableCopyValuesOption == true ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function has_recaptcha_field( $form ) {
		if ( is_array( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				if ( ( $field->type == 'captcha' || $field->inputType == 'captcha' ) && ! in_array( $field->captchaType, array( 'simple_captcha', 'math' ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function has_input_mask( $form, $field = false ) {

		if ( $field ) {
			if ( self::has_field_input_mask( $field ) ) {
				return true;
			}
		} else {

			if ( ! is_array( $form['fields'] ) ) {
				return false;
			}

			foreach ( $form['fields'] as $field ) {
				if ( self::has_field_input_mask( $field ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function has_field_input_mask( $field ) {

		$type = GFFormsModel::get_input_type( $field );
		if ( $type == 'phone' && $field->phoneFormat == 'standard' ) {
			return true;
		}

		if ( $field->inputMask && $field->inputMaskValue && ! $field->enablePasswordInput ) {
			return true;
		}

		return false;
	}

	public static function has_calculation_field( $form ) {

		if ( ! is_array( $form['fields'] ) ) {
			return false;
		}

		foreach ( $form['fields'] as $field ) {
			/* @var $field GF_Field */
			if ( $field->has_calculation() ) {
				return true;
			}
		}

		return false;
	}

	//Getting all fields that have a rule based on the specified field id
	public static function get_conditional_logic_fields( $form, $fieldId ) {
		$fields = array();

		//adding submit button field if enabled
		if ( isset( $form['button']['conditionalLogic'] ) ) {
			$fields[] = 0;
		}

		foreach ( $form['fields'] as $field ) {

			if ( $field->type != 'page' && ! empty( $field->conditionalLogic ) ) {
				foreach ( $field->conditionalLogic['rules'] as $rule ) {
					if ( $rule['fieldId'] == $fieldId ) {
						$fields[] = floatval( $field->id );

						//if field is a section, add all fields in the section that have conditional logic (to support nesting)
						if ( $field->type == 'section' ) {
							$section_fields = GFCommon::get_section_fields( $form, $field->id );
							foreach ( $section_fields as $section_field ) {
								if ( ! empty( $section_field['conditionalLogic'] ) ) {
									$fields[] = floatval( $section_field['id'] );
								}
							}
						}
						break;
					}
				}
			}
			//adding fields with next button logic
			if ( ! empty( $field->nextButton['conditionalLogic'] ) ) {
				foreach ( $field->nextButton['conditionalLogic']['rules'] as $rule ) {
					if ( $rule['fieldId'] == $fieldId && ! in_array( $fieldId, $fields ) ) {
						$fields[] = floatval( $field->id );
						break;
					}
				}
			}
		}

		return $fields;
	}

	public static function get_field( $field, $value = '', $force_frontend_label = false, $form = null, $field_values = null ) {
		$is_form_editor = GFCommon::is_form_editor();
		$is_entry_detail = GFCommon::is_entry_detail();
		$is_admin = $is_form_editor || $is_entry_detail;

		$custom_class = $is_admin ? '' : $field->cssClass;

		if ( $field->type == 'page' ) {
			if ( $is_entry_detail ) {
				return; //ignore page breaks in the entry detail page
			} else if ( ! $is_form_editor ) {
				$save_button = rgars( $form, 'save/enabled' ) ? self::get_form_button( $form['id'], "gform_save_{$form['id']}", $form['save']['button'], rgars( $form, 'save/button/text' ), 'gform_save_link', rgars( $form, 'save/button/text' ), 0, "jQuery(\"#gform_save_{$form['id']}\").val(1);" ) : '';

				$next_button     = self::get_form_button( $form['id'], "gform_next_button_{$form['id']}_{$field->id}", $field->nextButton, __( 'Next', 'gravityforms' ), 'gform_next_button', __( 'Next Page', 'gravityforms' ), $field->pageNumber );
				$next_button     = apply_filters( 'gform_next_button', $next_button, $form );
				$next_button     = apply_filters( "gform_next_button_{$form['id']}", $next_button, $form );
				$previous_button = $field->pageNumber == 2 ? '' : self::get_form_button( $form['id'], "gform_previous_button_{$form['id']}_{$field->id}", $field->previousButton, __( 'Previous', 'gravityforms' ), 'gform_previous_button', __( 'Previous Page', 'gravityforms' ), $field->pageNumber - 2 );
				if ( ! empty( $previous_button ) ) {
					$previous_button = apply_filters( 'gform_previous_button', $previous_button, $form );
					$previous_button = apply_filters( "gform_previous_button_{$form['id']}", $previous_button, $form );
				}
				$style        = self::is_page_active( $form['id'], $field->pageNumber ) ? '' : "style='display:none;'";
				$custom_class = ! empty( $custom_class ) ? " {$custom_class}" : '';
				$html         = "</ul>
                    </div>
                    <div class='gform_page_footer'>
                        {$previous_button} {$next_button} {$save_button}
                    </div>
                </div>
                <div id='gform_page_{$form['id']}_{$field->pageNumber}' class='gform_page{$custom_class}' {$style}>
                    <div class='gform_page_fields'>
                        <ul id='gform_fields_{$form['id']}_{$field->pageNumber}' class='" . GFCommon::get_ul_classes( $form ) . "'>";

				return $html;
			}
		}

		if ( ! $is_admin && $field->adminOnly ) {
			if ( $field->allowsPrepopulate ) {
				$field->inputType = 'adminonly_hidden';
			} else {
				return;
			}
		}

		$id = $field->id;

		$input_type = GFFormsModel::get_input_type( $field );

		$error_class      = $field->failed_validation ? 'gfield_error' : '';
		$admin_only_class = $field->adminOnly ? 'field_admin_only' : '';
		$selectable_class = $is_admin ? 'selectable' : '';
		$hidden_class     = in_array( $input_type, array( 'hidden', 'hiddenproduct' ) ) ? 'gform_hidden' : '';

		$section_class              = $field->type == 'section' ? 'gsection' : '';
		$page_class                 = $field->type == 'page' ? 'gpage' : '';
		$html_block_class           = $field->type == 'html' ? 'gfield_html' : '';
		$html_formatted_class       = $field->type == 'html' && ! $is_admin && ! $field->disableMargins ? 'gfield_html_formatted' : '';
		$html_no_follows_desc_class = $field->type == 'html' && ! $is_admin && ! self::prev_field_has_description( $form, $field->id ) ? 'gfield_no_follows_desc' : '';

		$calculation_class = $input_type == 'calculation' || ( $input_type == 'number' && $field->has_calculation() )  ? 'gfield_calculation' : '';

		$product_suffix           = "_{$form['id']}_" . $field->productField;
		$option_class             = $field->type == 'option' ? "gfield_price gfield_price{$product_suffix} gfield_option{$product_suffix}" : '';
		$quantity_class           = $field->type == 'quantity' ? "gfield_price gfield_price{$product_suffix} gfield_quantity{$product_suffix}" : '';
		$shipping_class           = $field->type == 'shipping' ? "gfield_price gfield_shipping gfield_shipping_{$form['id']}" : '';
		$product_class            = $field->type == 'product' ? "gfield_price gfield_price_{$form['id']}_{$field->id} gfield_product_{$form['id']}_{$field->id}" : '';
		$hidden_product_class     = $input_type == 'hiddenproduct' ? 'gfield_hidden_product' : '';
		$donation_class           = $field->type == 'donation' ? "gfield_price gfield_price_{$form['id']}_{$field->id} gfield_donation_{$form['id']}_{$field->id}" : '';
		$required_class           = $field->isRequired ? 'gfield_contains_required' : '';
		$creditcard_warning_class = $input_type == 'creditcard' && ! GFCommon::is_ssl() ? 'gfield_creditcard_warning' : '';

		$form_sublabel_setting				 = rgempty( 'subLabelPlacement', $form ) ? 'below' : $form['subLabelPlacement'];
		$sublabel_setting					 = ! isset( $field->subLabelPlacement ) || empty( $field->subLabelPlacement ) ? $form_sublabel_setting : $field->subLabelPlacement;
		$sublabel_class = "field_sublabel_{$sublabel_setting}";

		$form_description_setting			= rgempty( 'descriptionPlacement', $form ) ? 'below' : $form['descriptionPlacement'];
		$description_setting				= ! isset( $field->descriptionPlacement ) || empty( $field->descriptionPlacement ) ? $form_description_setting : $field->descriptionPlacement;
		$description_class = "field_description_{$description_setting}";

		$field_setting_label_placement       = $field->labelPlacement;
		$label_placement                     = empty( $field_setting_label_placement ) ? '' : $field_setting_label_placement;

		$css_class = "$selectable_class gfield $error_class $section_class $admin_only_class $custom_class $hidden_class $html_block_class $html_formatted_class $html_no_follows_desc_class $option_class $quantity_class $product_class $donation_class $shipping_class $page_class $required_class $hidden_product_class $creditcard_warning_class $calculation_class $sublabel_class $description_class $label_placement";
		$css_class = preg_replace( '/\s+/', ' ', $css_class ); //removing extra spaces

		$css_class = apply_filters( "gform_field_css_class_{$form['id']}", apply_filters( 'gform_field_css_class', trim( $css_class ), $field, $form ), $field, $form );

		$style = '';

		$field_id = $is_admin || empty( $form ) ? "field_$id" : 'field_' . $form['id'] . "_$id";

		$field_content   = self::get_field_content( $field, $value, $force_frontend_label, $form == null ? 0 : $form['id'], $form );
		$field_container = "<li id='$field_id' class='{$css_class}' $style>{FIELD_CONTENT}</li>";

		$field_container = apply_filters( 'gform_field_container', $field_container, $field, $form, $css_class, $style, $field_content );
		$field_container = apply_filters( "gform_field_container_{$form['id']}", $field_container, $field, $form, $css_class, $style, $field_content );
		$field_container = apply_filters( "gform_field_container_{$form['id']}_{$field->id}", $field_container, $field, $form, $css_class, $style, $field_content );

		$field_markup = str_replace( '{FIELD_CONTENT}', $field_content, $field_container );

		return $field_markup;
	}

	private static function prev_field_has_description( $form, $field_id ) {
		if ( ! is_array( $form['fields'] ) ) {
			return false;
		}

		$prev = null;
		foreach ( $form['fields'] as $field ) {
			if ( $field->id == $field_id ) {
				return $prev != null && ! empty( $prev['description'] );
			}
			$prev = $field;
		}

		return false;
	}

	/**
	 * @param GF_Field  	$field
	 * @param string 		$value
	 * @param bool   		$force_frontend_label
	 * @param int   		$form_id
	 * @param null|array   	$form
	 *
	 * @return string
	 */
	public static function get_field_content( $field, $value = '', $force_frontend_label = false, $form_id = 0, $form = null ) {

		$field_label = $field->get_field_label( $form, $value );
		$admin_buttons = $field->get_admin_buttons();

		$input_type = GFFormsModel::get_input_type( $field );

		$is_form_editor = GFCommon::is_form_editor();
		$is_entry_detail = GFCommon::is_entry_detail();
		$is_admin = $is_form_editor || $is_entry_detail;

		if ( $input_type == 'adminonly_hidden' ) {
			$field_content = ! $is_admin ? '{FIELD}' : sprintf( "%s<label class='gfield_label' >%s</label>{FIELD}", $admin_buttons, esc_html( $field_label ) );
		} else {
			$field_content = $field->get_field_content( $value, $force_frontend_label, $form );
		}

		if ( $input_type == 'creditcard' && ! GFCommon::is_ssl() && ! $is_admin ) {
			$field_content = "<div class='gfield_creditcard_warning_message'><span>" . __( 'This page is unsecured. Do not enter a real credit card number! Use this field only for testing purposes. ', 'gravityforms' ) . '</span></div>' . $field_content;
		}

		$value = $field->get_value_default_if_empty( $value );

		$field_content = str_replace( '{FIELD}', GFCommon::get_field_input( $field, $value, 0, $form_id, $form ), $field_content );

		$field_content = apply_filters( 'gform_field_content', $field_content, $field, $value, 0, $form_id );

		return $field_content;
	}




	private static function get_progress_bar( $form, $form_id, $confirmation_message ) {

		$progress_complete = false;
		$progress_bar      = '';
		$page_count        = self::get_max_page_number( $form );
		$current_page      = self::get_current_page( $form_id );
		$page_name         = rgar( rgar( $form['pagination'], 'pages' ), $current_page - 1 );
		$page_name         = ! empty( $page_name ) ? ' - ' . $page_name : '';
		$style             = $form['pagination']['style'];
		$color             = $style == 'custom' ? " color:{$form['pagination']['color']};" : '';
		$bgcolor           = $style == 'custom' ? " background-color:{$form['pagination']['backgroundColor']};" : '';

		if ( ! empty( $confirmation_message ) ) {
			$progress_complete = true;
		}
		//check admin setting for whether the progress bar should start at zero
		$start_at_zero = rgars( $form, 'pagination/display_progressbar_on_confirmation' );
		//check for filter
		$start_at_zero          = apply_filters( 'gform_progressbar_start_at_zero', $start_at_zero, $form );
		$progressbar_page_count = $start_at_zero ? $current_page - 1 : $current_page;
		$percent                = ! $progress_complete ? floor( ( ( $progressbar_page_count ) / $page_count ) * 100 ) . '%' : '100%';
		$percent_number         = ! $progress_complete ? floor( ( ( $progressbar_page_count ) / $page_count ) * 100 ) . '' : '100';

		if ( $progress_complete ) {
			$wrapper_css_class = GFCommon::get_browser_class() . ' gform_wrapper';

			//add on surrounding wrapper class when confirmation page
			$progress_bar = "<div class='{$wrapper_css_class}' id='gform_wrapper_$form_id' >";
			$page_name    = ! empty( $form['pagination']['progressbar_completion_text'] ) ? $form['pagination']['progressbar_completion_text'] : '';
		}


		$progress_bar .= "
        <div id='gf_progressbar_wrapper_{$form_id}' class='gf_progressbar_wrapper'>
            <h3 class='gf_progressbar_title'>";
		$progress_bar .= ! $progress_complete ? __( 'Step', 'gravityforms' ) . " {$current_page} " . __( 'of', 'gravityforms' ) . " {$page_count}{$page_name}" : "{$page_name}";
		$progress_bar .= "
        </h3>
            <div class='gf_progressbar'>
                <div class='gf_progressbar_percentage percentbar_{$style} percentbar_{$percent_number}' style='width:{$percent};{$color}{$bgcolor}'><span>{$percent}</span></div>
            </div></div>";
		//close div for surrounding wrapper class when confirmation page
		$progress_bar .= $progress_complete ? $confirmation_message . '</div>' : '';

		return $progress_bar;
	}

	/**
	 * Validates the form's entry limit settings. Returns the entry limit message if entry limit exceeded.
	 *
	 * @param array $form current GF form object
	 *
	 * @return string If entry limit exceeded returns entry limit setting.
	 */
	public static function validate_entry_limit( $form ) {

		//If form has a limit of entries, check current entry count
		if ( rgar( $form, 'limitEntries' ) ) {
			$period      = rgar( $form, 'limitEntriesPeriod' );
			$range       = self::get_limit_period_dates( $period );
			$entry_count = RGFormsModel::get_lead_count( $form['id'], '', null, null, $range['start_date'], $range['end_date'], 'active' );

			if ( $entry_count >= $form['limitEntriesCount'] ) {
				return empty( $form['limitEntriesMessage'] ) ? "<div class='gf_submission_limit_message'><p>" . __( 'Sorry. This form is no longer accepting new submissions.', 'gravityforms' ) . '</p></div>' : '<p>' . GFCommon::gform_do_shortcode( $form['limitEntriesMessage'] ) . '</p>';
			}
		}

	}

	public static function validate_form_schedule( $form ) {

		//If form has a schedule, make sure it is within the configured start and end dates
		if ( rgar( $form, 'scheduleForm' ) ) {
			$local_time_start = sprintf( '%s %02d:%02d %s', $form['scheduleStart'], $form['scheduleStartHour'], $form['scheduleStartMinute'], $form['scheduleStartAmpm'] );
			$local_time_end   = sprintf( '%s %02d:%02d %s', $form['scheduleEnd'], $form['scheduleEndHour'], $form['scheduleEndMinute'], $form['scheduleEndAmpm'] );
			$timestamp_start  = strtotime( $local_time_start . ' +0000' );
			$timestamp_end    = strtotime( $local_time_end . ' +0000' );
			$now              = current_time( 'timestamp' );

			if ( ! empty( $form['scheduleStart'] ) && $now < $timestamp_start ) {
				return empty( $form['schedulePendingMessage'] ) ? '<p>' . __( 'This form is not yet available.', 'gravityforms' ) . '</p>' : '<p>' . GFCommon::gform_do_shortcode( $form['schedulePendingMessage'] ) . '</p>';
			} elseif ( ! empty( $form['scheduleEnd'] ) && $now > $timestamp_end ) {
				return empty( $form['scheduleMessage'] ) ? '<p>' . __( 'Sorry. This form is no longer available.', 'gravityforms' ) . '</p>' : '<p>' . GFCommon::gform_do_shortcode( $form['scheduleMessage'] ) . '</p>';
			}
		}

	}

	public static function update_confirmation( $form, $lead = null, $event = '' ) {
		if ( ! is_array( rgar( $form, 'confirmations' ) ) ) {
			return $form;
		}

		if ( ! empty( $event ) ) {
			$confirmations = wp_filter_object_list( $form['confirmations'], array( 'event' => $event ) );
		} else {
			$confirmations = $form['confirmations'];
		}

		// if there is only one confirmation, don't bother with the conditional logic, just return it
		// this is here mostly to avoid the semi-costly GFFormsModel::create_lead() function unless we really need it
		if ( is_array( $form['confirmations'] ) && count( $confirmations ) <= 1 ) {
			$form['confirmation'] = reset( $confirmations );

			return $form;
		}

		if ( empty( $lead ) ) {
			$lead = GFFormsModel::create_lead( $form );
		}

		foreach ( $confirmations as $confirmation ) {

			if ( rgar( $confirmation, 'event' ) != $event ) {
				continue;
			}

			if ( rgar( $confirmation, 'isDefault' ) ) {
				continue;
			}

			if ( isset( $confirmation['isActive'] ) && ! $confirmation['isActive'] ) {
				continue;
			}

			$logic = rgar( $confirmation, 'conditionalLogic' );
			if ( GFCommon::evaluate_conditional_logic( $logic, $form, $lead ) ) {
				$form['confirmation'] = $confirmation;

				return $form;
			}
		}

		$filtered_list = wp_filter_object_list( $form['confirmations'], array( 'isDefault' => true ) );

		$form['confirmation'] = reset( $filtered_list );

		return $form;
	}

	public static function process_send_resume_link() {

		$form_id      = rgpost( 'gform_send_resume_link' );
		$form_id      = absint( $form_id );
		$email        = rgpost( 'gform_resume_email' );
		$resume_token = rgpost( 'gform_resume_token' );

		if ( empty( $form_id ) || empty( $email ) || empty( $resume_token ) || ! GFCommon::is_valid_email( $email ) ) {
			return;
		}

		$form = GFFormsModel::get_form_meta( $form_id );

		if ( empty( $form ) ) {
			return;
		}

		if ( rgar( $form, 'requireLogin' ) ) {
			if ( ! is_user_logged_in() ) {
				wp_die();
			}
			check_admin_referer( 'gform_send_resume_link', '_gform_send_resume_link_nonce' );
		}

		$incomplete_submission = GFFormsModel::get_incomplete_submission_values( $resume_token );

		$submission = json_decode( $incomplete_submission['submission'], true );

		$partial_entry = $submission['partial_entry'];

		$notifications_to_send = GFCommon::get_notifications_to_send( 'form_save_email_requested', $form, $partial_entry );

		$log_notification_event = empty( $notifications_to_send ) ? 'No notifications to process' : 'Processing notifications';
		GFCommon::log_debug( "GFFormDisplay::process_send_resume_link(): {$log_notification_event} for form_save_email_requested event." );

		foreach ( $notifications_to_send as $notification ) {
			if ( isset( $notification['isActive'] ) && ! $notification['isActive'] ) {
				GFCommon::log_debug( "GFFormDisplay::process_send_resume_link(): Notification is inactive, not processing notification (#{$notification['id']} - {$notification['name']})." );
				continue;
			}
			if ( $notification['toType'] == 'hidden' ) {
				$notification['to'] = $email;
			}
			$notification['message'] = self::replace_save_variables( $notification['message'], $form, $resume_token, $email );
			GFCommon::send_notification( $notification, $form, $partial_entry );
		}

		GFFormsModel::add_email_to_incomplete_sumbmission( $resume_token, $email );
	}

	public static function replace_save_variables( $text, $form, $resume_token, $email = null ) {

		$form_id = intval( $form['id'] );

		$resume_url  = apply_filters( 'gform_save_and_continue_resume_url', add_query_arg( array( 'gf_token' => $resume_token ), GFFormsModel::get_current_page_url() ), $form, $resume_token, $email );
		$resume_url  = esc_url( $resume_url );
		$resume_link = "<a href=\"{$resume_url}\" class='resume_form_link'>{$resume_url}</a>";
		$text        = str_replace( '{save_link}', $resume_link, $text );
		$text        = str_replace( '{save_token}', $resume_token, $text );

		$text = str_replace( '{save_url}', $resume_url, $text );

		$email_esc = esc_attr( $email );
		$text      = str_replace( '{save_email}', $email_esc, $text );

		$resume_submit_button_text       = __( 'Send Email', 'gravityforms' );
		$resume_email_validation_message = __( 'Please enter a valid email address.', 'gravityforms' );

		// The {save_email_input} accepts shortcode-style options button_text and validation_message. E.g.,
		// {save_email_input: button_text="Send the link to my email address" validation_message="The link couldn't be sent because the email address is not valid."}
		preg_match_all( '/\{save_email_input:(.*?)\}/', $text, $matches, PREG_SET_ORDER );

		if ( is_array( $matches ) && isset( $matches[0] ) && isset( $matches[0][1] ) ) {
			$options_string = isset( $matches[0][1] ) ? $matches[0][1] : '';
			$options        = shortcode_parse_atts( $options_string );
			if ( isset( $options['button_text'] ) ) {
				$resume_submit_button_text = $options['button_text'];
			}
			if ( isset( $options['validation_message'] ) ) {
				$resume_email_validation_message = $options['validation_message'];
			}
			$full_tag = $matches[0][0];
			$text     = str_replace( $full_tag, '{save_email_input}', $text );
		}

		$action = esc_url( remove_query_arg( 'gf_token' ) );

		$html_input_type = RGFormsModel::is_html5_enabled() ? 'email' : 'text';

		$resume_token = esc_attr( $resume_token );

		$validation_message = ! is_null( $email ) && ! GFCommon::is_valid_email( $email ) ? sprintf( '<div class="validation_message">%s</div>', $resume_email_validation_message ) : '';

		$nonce_input = '';

		if ( rgar( $form, 'requireLogin' ) ) {
			$nonce_input = wp_nonce_field( 'gform_send_resume_link', '_gform_send_resume_link_nonce', true, false );
		}

		$resume_form = "<div class='form_saved_message_emailform'>
							<form action='{$action}' method='POST'>
								<input type='{$html_input_type}' name='gform_resume_email' value='{$email_esc}'/>
								<input type='hidden' name='gform_resume_token' value='{$resume_token}' />
								<input type='hidden' name='gform_send_resume_link' value='{$form_id}' />
	                            <input type='submit' name='gform_send_resume_link_button' value='{$resume_submit_button_text}' />
	                            {$validation_message}
	                            {$nonce_input}
							</form>
	                    </div>";

		$text = str_replace( '{save_email_input}', $resume_form, $text );

		return $text;
	}

	public static function handle_save_email_confirmation( $form, $ajax ) {
		$resume_email       = $_POST['gform_resume_email'];
		if ( ! GFCommon::is_valid_email( $resume_email ) ) {
			GFCommon::log_debug( 'GFFormDisplay::handle_save_email_confirmation(): Invalid email address: ' . $resume_email );
			return new WP_Error( 'invalid_email' );
		}
		$resume_token       = $_POST['gform_resume_token'];
		$submission_details = GFFormsModel::get_incomplete_submission_values( $resume_token );
		$submission_json    = $submission_details['submission'];
		$submission         = json_decode( $submission_json, true );
		$entry              = $submission['partial_entry'];
		$form               = self::update_confirmation( $form, $entry, 'form_save_email_sent' );

		$confirmation            = '<div class="form_saved_message_sent"><span>' . rgar( $form['confirmation'], 'message' ) . '</span></div>';
		$nl2br                   = rgar( $form['confirmation'], 'disableAutoformat' ) ? false : true;
		$save_email_confirmation = self::replace_save_variables( $confirmation, $form, $resume_token, $resume_email );

		$save_email_confirmation = GFCommon::replace_variables( $save_email_confirmation, $form, $entry, false, true, $nl2br );

		if ( $ajax ) {
			$save_email_confirmation = "<!DOCTYPE html><html><head><meta charset='UTF-8' /></head><body class='GF_AJAX_POSTBACK'>" . $save_email_confirmation . '</body></html>';
		}

		GFCommon::log_debug( 'GFFormDisplay::handle_save_email_confirmation(): Confirmation => ' . print_r( $save_email_confirmation, true ) );

		return $save_email_confirmation;
	}

	public static function handle_save_confirmation( $form, $resume_token, $confirmation_message, $ajax ) {
		$resume_email = isset( $_POST['gform_resume_email'] ) ? $_POST['gform_resume_email'] : null;
		$confirmation_message = self::replace_save_variables( $confirmation_message, $form, $resume_token, $resume_email );
		$confirmation_message       = "<div class='form_saved_message'><span>" . $confirmation_message . '</span></div>';
		if ( $ajax ) {
			$confirmation_message = "<!DOCTYPE html><html><head><meta charset='UTF-8' /></head><body class='GF_AJAX_POSTBACK'>" . $confirmation_message . '</body></html>';
		}

		GFCommon::log_debug( 'GFFormDisplay::handle_save_confirmation(): Confirmation => ' . print_r( $confirmation_message, true ) );

		return $confirmation_message;
	}

}