<?php

namespace PolylangSync\ACF;
use PolylangSync\Core;


class Sync extends Core\Singleton {

	private $core;

	private $sync_acf_fields;

	private $in_sub_field_loop = false;

	/**
	 *	Private constructor
	 */
	protected function __construct() {

		$this->core	= Core\Core::instance();

		add_action( 'init' , array( &$this , 'init' ) );

//		add_action( 'save_post' ,  array( &$this , 'save_post' ) , 20 , 3 );
		add_action( 'pll_save_post' ,  array( &$this , 'pll_save_post' ) , 20 , 3 );

		foreach ( $this->get_supported_fields() as $type ) {
			add_action( "acf/render_field_settings/type={$type}" , array( $this , 'render_acf_settings' ) );
		}
	}

	/**
	 *	Get Supported ACF fields
	 */
	public function get_supported_fields() {
		return apply_filters( 'polylang_acf_sync_supported_fields', array(
			// basic
			'text',
			'textarea',
			'number',
			'email',
			'url',
			'password',

			// Content
			'wysiwyg',
			'oembed',
			'image',
			'file',
			'gallery',

			// Choice
			'select',
			'checkbox',
			'radio',
			'true_false',

			// relational
			'post_object',
			'page_link',
			'relationship',
			'taxonomy', // will be synced by polylang
			'user',

			// jQuery
			'google_map',
			'date_picker',
			'date_time_picker',
			'time_picker',
			'color_picker',

			// relational
			'repeater',
		));
	}
	/**
	 *	@action acf/render_field_settings/type={$type}
	 */
	public function render_acf_settings( $field ) {

		$post = get_post( $field['ID'] );

		if ( $post ) {

			if ( acf_is_sub_field( $field ) ) {
				return;
			}

			$instructions = '';

			if ( $field['type'] === 'taxonomy' ) {
				/*
				Polylang-Sync AN:
					
				Polylang-Sync AUS:
					
				
				*/
				$instructions = __( 'Enabling this field only makes sense if...', 'polylang-sync' );
			}

			// show column: todo: allow sortable
			acf_render_field_setting( $field, array(
				'label'			=> __( 'Synchronize', 'polylang-sync' ),
				'instructions'	=> '',
				'type'			=> 'true_false',
				'name'			=> 'polylang_sync',
				'message'		=> __( 'Synchronize this field between translations', 'polylang-sync' ),
				'width'			=> 50,
			));
		}		
	}



	/**
	 *	@action init
	 */
	public function init() {

		// get top level fields to sync
		$this->sync_acf_fields = array();

		$all_acf_fields = get_posts(array(
			'post_type' => 'acf-field',
			'posts_per_page' => -1,
		));

		foreach( $all_acf_fields as $post ) {
/*
			if ( ! $this->is_repeater_child( $post ) ) {
				continue;
			}
*/

			$field	= get_field_object( $post->post_name );

			if ( isset( $field['polylang_sync'] ) && $field['polylang_sync'] && ! acf_is_sub_field( $field ) ) {
				$this->sync_acf_fields[] = $field;
			}
		}
		
	}


	/**
	 *	@action pll_save_post
	 */
	public function pll_save_post( $source_post_id, $source_post, $translation_group ) {
		$this->update_fields( $this->sync_acf_fields, $source_post_id, $translation_group );
//exit();
	}

	
	private function update_fields( $fields, $source_post_id, $translation_group ) {
		foreach ( $fields as $synced_field ) {
		
			$field_object = get_field_object( $synced_field['key'], $source_post_id );

			switch ( $synced_field['type'] ) {
				case 'image':
				case 'file':
					// we need to get a post object for url forrmated uploads!
					if ( $field_object['return_format'] == 'url' ) {
						$media_id	= get_field( $synced_field['key'], $source_post_id, false );
						$media		= get_post( $media_id );
						$field_object['value'] = $media;
					}
					if ( PLL()->options['media_support'] ) {
						$this->update_upload( $field_object, $translation_group );
					} else {
						$this->update_field_value( $field_object, $translation_group );
					}

					break;

				case 'gallery':
					if ( PLL()->options['media_support'] ) {
						$this->update_gallery( $field_object, $translation_group );
					} else {
						$this->update_field_value( $field_object, $translation_group );
					}
					break;
					
				case 'relationship':
					$this->update_relationship( $field_object, $translation_group );
					break;

				case 'flexible_content':
				case 'repeater':
					$this->update_repeater( $field_object, $translation_group, $source_post_id );
					break;

				case 'taxonomy': // will be synced by polylang
					// if translated, find translate
					break;

				// basic
				case 'text':
				case 'textarea':
				case 'number':
				case 'email':
				case 'url':
				case 'password':
				case 'wysiwyg':
				case 'oembed':
				case 'select':
				case 'checkbox':
				case 'radio':
				case 'true_false':
				case 'user':
				case 'date_picker':
				case 'date_time_picker':
				case 'time_picker':
				case 'color_picker':
				case 'google_map':
				case 'post_object':
				case 'page_link':
				default:
					// just copy over the value
					$this->update_field_value( $field_object, $translation_group );
					break;

			}
		}
	}
	
	private function update_repeater( $field_object, $translation_group, $source_post_id ) {
		$values = [];
		if ( have_rows( $field_object['name'] ) ) {
			while ( have_rows( $field_object['name'] ) ) {
				the_row();
				$values[ get_row_index() ] = get_row( false );
			}
		}

		foreach ( $translation_group as $lang_code => $post_id ) {
			$translated_values = array_merge( $values, [] );
			foreach ( $translated_values as $field_key => $value ) {
				foreach ( $field_object['sub_fields'] as $sub_field ) {
					switch ( $sub_field['type'] ) {
						case 'image':
						case 'file':
							if ( PLL()->options['media_support'] ) {
								$value = $this->get_translated_media( $value, $lang_code, [] );
							}
							break;
						case 'relationship':	
							$post = get_post( $value );
							if ( pll_is_translated_post_type( $post->post_type ) ) {
								$translated_post = get_translated_post( $post_id, $lang_code );
							}
							if ( $translated_post ) {
								$value = $translated_post->ID;
							}
							break;
						case 'gallery':
							if ( PLL()->options['media_support'] ) {
							//	$value = $this->get_translated_media( $value, $lang_code, [] );
							}
							
							break;
						case 'taxonomy':
							break;
					}
				}
				$translated_values[ $field_key ] = $value;
			}
			$this->update_field( $field_object['key'], $translated_values, $post_id );
		}
	}

	private function update_field_value( $field_object, $translation_group ) {
		foreach ( $translation_group as $lang_code => $post_id ) {
			$selector = $field_object['key'];
			$this->update_field( $selector, $field_object['value'], $post_id );
		}
	}


	private function update_relationship( $field_object, $translation_group ) {

		$posts				= $field_object['value'];
		$translated_posts	= array();

		foreach ( $translation_group as $lang_code => $post_id ) {
			if ( $posts ) {
				foreach ( $posts as $i => $post ) {
					if ( pll_is_translated_post_type( $post->post_type ) && $translated_post_id = pll_get_post( $post->ID, $lang_code ) ) {
						$translated_posts[$i] = get_post( $translated_post_id );
						unset( $translated_post_id );
					} else {
						$translated_posts[$i] = $post;
					}
				}
				$field_object['value'] = $translated_posts;
			}
			$this->update_field( $field_object['key'], $field_object['value'], $post_id );

		}
	}
	
	
	private function update_upload( $field_object, $translation_group ) {


		$media_obj = (object) $field_object['value'];
		$source_lang = pll_get_post_language( $media_obj->ID, 'slug' );
		$media_translation_group = array( $source_lang => $media_obj->ID );

		foreach ( $translation_group as $lang_code => $post_id ) {
			$field_object["value"] = $this->get_translated_media( $field_object["value"], $lang_code, $media_translation_group );
			$this->update_field( $field_object['key'], $field_object['value'], $post_id );
		}

		pll_save_post_translations( $media_translation_group );
	}

	private function get_translated_post( $post_id, $lang_code ) {
		if ( $translated_post_id = pll_get_post( $post_id, $lang_code ) ) {
			return get_post( $translated_post_id );
		}
		return false;
	}

	/**
	 *	@return	object	WP_Post
	 */
	private function get_translated_media( $media, $lang_code, &$translation_group ) {

		$media = get_post( is_array($media) ? $media['ID'] : $media->ID );
		

		// found translation?
		if ( $translated_media_id = pll_get_post( $media->ID, $lang_code ) ) {
			if ( $translated_media = get_post( $translated_media_id ) ) {
			
				$translation_group[ $lang_code ] = $translated_media_id;

				return $translated_media;
			}
		}

		$lang = PLL()->model->get_language($lang_code);

		// make new translation
		$post_arr = get_object_vars( $media );
		$post_arr['ID'] = 0;
		$post_arr['comment_count']	= 0;
		$post_arr['post_status']	= $post_arr['post_status'];
		$post_arr['post_parent'] 	= pll_get_post( $media->post_parent, $lang_code );
		$post_arr['post_title'] 	.= sprintf( ' (%s)' , $lang->slug );

		if ( $translated_media_id = wp_insert_post( $post_arr ) ) {
			pll_set_post_language( $translated_media_id, $lang_code );

			$ignore_meta_keys = array( '_edit_lock' , '_edit_last' );

			$meta = get_post_meta( $media->ID );

			foreach ( $meta as $meta_key => $values ) {
				if ( in_array( $meta_key , $ignore_meta_keys ) )
					continue;
				foreach ( $values as $value ) {
					update_post_meta( $translated_media_id , $meta_key , maybe_unserialize( $value ) );
				}
			}
			
			$translation_group[ $lang_code ] = $translated_media_id;

			$translated_media = get_post( $translated_media_id );

			if ( $translated_media ) {
				return $translated_media;
			}
		}

		// fallback
		return $media;
	}

	private function update_gallery( $field_object, $translation_group ) {

		$media_translation_groups = array();

		foreach ( $translation_group as $lang_code => $post_id ) {

			$gallery = false;

			if ( $field_object["value"] ) {

				$gallery = array();

				foreach ( $field_object["value"] as $i => $image ) {

					$media_obj = (object) $image;

					$source_lang = pll_get_post_language( $media_obj->ID, 'slug' );
		
					$media_translation_groups[$i] = array( $source_lang => $media_obj->ID );

					$gallery[$i] = $this->get_translated_media( $media_obj->ID, $lang_code, $media_translation_groups[$i] );

				}

			}

			$field_object["value"] = $gallery;

			$this->update_field( $field_object['key'], $field_object['value'], $post_id );
		}
		
		foreach ( $media_translation_groups as $translation_group ) {
			pll_save_post_translations( $translation_group );
		}
	}

	private function update_field( $selector, $value, $post_id ) {
		$res = update_field( $selector, $value, $post_id );
		return $res;
	}




}