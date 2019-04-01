<?php

namespace WPGraphQL\ACF;

use WPGraphQL\Data\DataSource;
use WPGraphQL\Model\Post;
use WPGraphQL\TypeRegistry;

class Config {

	/**
	 * Initialize WPGraphQL to ACF
	 */
	public function init() {
		/**
		 * Add ACF Fields to GraphQL Types
		 */
		$this->register_acf_types();
		$this->add_acf_fields_to_post_object_types();
		$this->add_acf_fields_to_term_objects();
		$this->add_acf_fields_to_comments();
		$this->add_acf_fields_to_menu_items();
		$this->add_acf_fields_to_media_items();
	}

	protected function register_acf_types() {
		// @todo
	}

	/**
	 * Determines whether a field group should be exposed to the GraphQL Schema. By default, field
	 * groups will not be exposed to GraphQL.
	 *
	 * @param $field_group
	 *
	 * @return bool
	 */
	protected function should_field_group_show_in_graphql( $field_group ) {

		/**
		 * By default, field groups will not be exposed to GraphQL.
		 */
		$show = false;

		/**
		 * If
		 */
		if ( isset( $field_group['show_in_graphql'] ) && true === (bool) $field_group['show_in_graphql'] ) {
			$show = true;
		}

		/**
		 * Determine conditions where the GraphQL Schema should NOT be shown in GraphQL for
		 * root groups, not nested groups with parent.
		 */
		if ( ! isset( $field_group['parent'] ) ) {
			if (
				( empty( $field_group['active'] ) || true !== $field_group['active'] ) ||
				( empty( $field_group['location'] ) || ! is_array( $field_group['location'] ) )
			) {
				$show = false;
			}
		}

		/**
		 * Whether a field group should show in GraphQL.
		 *
		 * @var boolean $show        Whether the field group should show in the GraphQL Schema
		 * @var array   $field_group The ACF Field Group
		 * @var Config  $this        The Config for the ACF Plugin
		 */
		return apply_filters( 'WPGraphQL\ACF\should_field_group_show_in_graphql', $show, $field_group, $this );

	}

	/**
	 * @todo: This may be a good utility to add to WPGraphQL Core? May even have something already?
	 *
	 * @param       $str
	 * @param array $noStrip
	 *
	 * @return mixed|null|string|string[]
	 */
	public static function camelCase( $str, array $noStrip = [] ) {
		// non-alpha and non-numeric characters become spaces
		$str = preg_replace( '/[^a-z0-9' . implode( "", $noStrip ) . ']+/i', ' ', $str );
		$str = trim( $str );
		// uppercase the first character of each word
		$str = ucwords( $str );
		$str = str_replace( " ", "", $str );
		$str = lcfirst( $str );

		return $str;
	}

	/**
	 * Add ACF Fields to Post Object Types.
	 *
	 * This gets the Post Types that are configured to show_in_graphql and iterates
	 * over them to expose ACF Fields to their Type in the GraphQL Schema.
	 */
	protected function add_acf_fields_to_post_object_types() {

		/**
		 * Get a list of post types that have been registered to show in graphql
		 */
		$graphql_post_types = \WPGraphQL::$allowed_post_types;

		/**
		 * If there are no post types exposed to GraphQL, bail
		 */
		if ( empty( $graphql_post_types ) || ! is_array( $graphql_post_types ) ) {
			return;
		}

		/**
		 * Loop over the post types exposed to GraphQL
		 */
		foreach ( $graphql_post_types as $post_type ) {

			/**
			 * Get the field groups associated with the post type
			 */
			$field_groups = acf_get_field_groups( [
				'post_type' => $post_type,
			] );

			/**
			 * If there are no field groups for this post type, bail early
			 */
			if ( empty( $field_groups ) || ! is_array( $field_groups ) ) {
				return;
			}

			/**
			 * Get the post_type_object
			 */
			$post_type_object = get_post_type_object( $post_type );

			/**
			 * Loop over the field groups for this post type
			 */
			foreach ( $field_groups as $field_group ) {
				$this->add_field_group_fields( $field_group, $post_type_object->graphql_single_name );
			}

		}

	}

	protected function get_acf_field_value( $root, $acf_field ) {

		$value = null;
		if ( is_array( $root ) ) {
			if ( isset( $root[ $acf_field['key'] ] ) ) {
				$value = $root[ $acf_field['key'] ];
			}
		} else {
			$field_value = get_field( $acf_field['key'], $root->ID, false );
			$value       = ! empty( $field_value ) ? $field_value : null;
		}

		return $value;

	}

	protected function register_graphql_field( $type_name, $field_name, $config ) {

		$acf_field = isset( $config['acf_field'] ) ? $config['acf_field'] : null;
		$acf_type = isset( $acf_field['type'] ) ? $acf_field['type'] : null;

		if ( empty( $acf_type ) ) {
			return false;
		}

		$field_config = [
			'type' => null,
			'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
				$value = $this->get_acf_field_value( $root, $acf_field );
				return ! empty( $value ) ? $value : null;
			}
		];

		switch ( $acf_type ) {
			case 'button_group':
			case 'color_picker':
			case 'email':
			case 'textarea':
			case 'text':
			case 'message':
			case 'oembed':
			case 'password':
			case 'url':
			case 'wysiwyg':
				$field_config['type'] = 'String';
				break;
			case 'number':
				$field_config['type'] = 'Float';
				break;
			case 'true_false':
				$field_config['type'] = 'Boolean';
				break;
			case 'date_picker':
			case 'time_picker':
			case 'date_time_picker':
				$field_config = [
					'type' => 'String',
					'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
						return isset( $root->ID ) ? get_field( $acf_field['key'], $root->ID, true ) : null;
					}
				];
				break;
			case 'relationship':
				$field_config = [
					'type' => [ 'list_of' => 'PostObjectUnion' ],
					'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
						$relationship = [];
						if ( ! empty( $value ) && is_array( $value ) ) {
							foreach ( $value as $post_id ) {
								$relationship[] = DataSource::resolve_post_object( (int) $post_id, $context );
							}
						}
						return isset( $value ) ? $relationship : null;
					}
				];
				break;
			case 'page_link':
			case 'post_object':
				$field_config = [
					'type' => 'PostObjectUnion',
					'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
						$value = $this->get_acf_field_value( $root, $acf_field );
						if ( $value instanceof \WP_Post ) {
							return new Post( $value );
						}

						return absint( $value ) ? DataSource::resolve_post_object( (int) $value, $context ) : null;

					}
				];
				break;
			case  'link':
				$field_config = [
					'type' => 'String',
					'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
						$value = $this->get_acf_field_value( $root, $acf_field );
						return isset( $value['url'] ) ? $value['url'] : null;
					}
				];
				break;
			case 'image':
			case 'file':
				$field_config = [
					'type' => 'MediaItem',
					'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
						$value = $this->get_acf_field_value( $root, $acf_field );
						return DataSource::resolve_post_object( (int) $value, $context );
					}
				];
				break;
			case 'checkbox':
				$field_config = [
					'type' => [ 'list_of' => 'String' ],
					'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
						$value = $this->get_acf_field_value( $root, $acf_field );
						return is_array( $value ) ? $value : null;
					}
				];
				break;
			case 'gallery':
				$field_config = [
					'type' => ['list_of' => 'MediaItem'],
					'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
						$value = $this->get_acf_field_value( $root, $acf_field );
						$gallery = [];
						if ( ! empty( $value ) && is_array( $value ) ) {
							foreach ( $value as $image ) {
								$gallery[] = DataSource::resolve_post_object( (int) $image, $context );
							}
						}

						return isset( $value ) ? $gallery : null;
					},
				];
				break;
			case 'user':
				$field_config = [
					'type' => 'User',
					'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
						$value = $this->get_acf_field_value( $root, $acf_field );
						return DataSource::resolve_user( (int) $value, $context );
					}
				];
				break;
			case 'taxonomy':
				$field_config = [
					'type' => [ 'list_of' => 'TermObjectUnion' ],
					'resolve' => function( $root, $args, $context, $info ) use ( $acf_field ) {
						$value = $this->get_acf_field_value( $root, $acf_field );
						$terms = [];
						if ( ! empty( $value ) && is_array( $value ) ) {
							foreach ( $value as $term ) {
								$terms[] = DataSource::resolve_term_object( (int) $term, $context );
							}
						}

						return $terms;
					}
				];
				break;

			// Accordions are not represented in the GraphQL Schema
			case 'accordion':
				$field_config = null;
				break;
			case 'group':

				$field_type_name = ucfirst( self::camelCase( $acf_field['name'] ) . 'FieldGroup' );
				if ( TypeRegistry::get_type( $field_type_name ) ) {
					$field_config['type'] = $field_type_name;
					break;
				}

				register_graphql_object_type( $field_type_name, [
					'description' => __( 'Field Group', 'wp-graphql-acf' ),
					'fields'      => [
						'fieldGroupName' => [
							'type'    => 'String',
							'resolve' => function( $source ) use ( $acf_field ) {
								return ! empty( $acf_field['name'] ) ? $acf_field['name'] : null;
							}
						],
					],
				] );

				$this->add_field_group_fields( $acf_field, $field_type_name );

				$field_config['type'] = $field_type_name;
				break;

			case 'google_map':

				$field_type_name = 'ACFGoogleMap';
				if ( $type = TypeRegistry::get_type( $field_type_name ) ) {
					$field_config['type'] = $field_type_name;
					break;
				}

				register_graphql_object_type( $field_type_name, [
					'description' => __( 'Google Map field', 'wp-graphql-acf' ),
					'fields'      => [
						'streetAddress' => [
							'type'        => 'String',
							'description' => __( 'The street address associated with the map', 'wp-graphql-acf' ),
							'resolve'     => function( $root ) {
								return isset( $root['address'] ) ? $root['address'] : null;
							},
						],
						'latitude'      => [
							'type'        => 'Float',
							'description' => __( 'The latitude associated with the map', 'wp-graphql-acf' ),
							'resolve'     => function( $root ) {
								return isset( $root['lat'] ) ? $root['lat'] : null;
							},
						],
						'longitude'     => [
							'type'        => 'Float',
							'description' => __( 'The longitude associated with the map', 'wp-graphql-acf' ),
							'resolve'     => function( $root ) {
								return isset( $root['lng'] ) ? $root['lng'] : null;
							},
						],
					],
				] );
				$field_config['type'] = $field_type_name;
				break;
			case 'repeater':

				$field_type_name = self::camelCase( $acf_field['name'] ) . 'Repeater';
				if ( TypeRegistry::get_type( $field_type_name ) ) {
					$field_config['type'] = $field_type_name;
					break;
				}

				register_graphql_object_type( $field_type_name, [
					'description' => __( 'Field Group', 'wp-graphql-acf' ),
					'fields'      => [
						'fieldGroupName' => [
							'type'    => 'String',
							'resolve' => function( $source ) use ( $acf_field ) {
								return ! empty( $acf_field['name'] ) ? $acf_field['name'] : null;
							}
						],
					],
				] );

				$this->add_field_group_fields( $acf_field, $field_type_name );

				$field_config['type'] = [ 'list_of' => $field_type_name ];
				break;
			case 'flexible_content':
// @todo: coming soon.
//				$field_config = null;
//				break;
//				var_dump( $acf_field );
//
//				$field_type_name = self::camelCase( $acf_field['name'] ) . 'FlexField';
//				if ( TypeRegistry::get_type( $field_type_name ) ) {
//					$field_config['type'] = $field_type_name;
//					break;
//				}
//
//				register_graphql_object_type( $field_type_name, [
//					'description' => __( 'Field Group', 'wp-graphql-acf' ),
//					'fields'      => [
//						'fieldGroupName' => [
//							'type'    => 'String',
//							'resolve' => function( $source ) use ( $acf_field ) {
//								return ! empty( $acf_field['name'] ) ? $acf_field['name'] : null;
//							}
//						],
//					],
//				] );
//
//				$this->add_field_group_fields( $acf_field, $field_type_name );
//
//				$field_config['type'] = [ 'list_of' => $field_type_name ];
				break;
			default:
				break;
		}


		if ( empty( $field_config ) || empty( $field_config['type'] ) ) {
			return null;
		}

		$config = array_merge( $config, $field_config );
		return register_graphql_field( $type_name, $field_name, $config );
	}

	/**
	 * @param array  $field_group The group to add to the Schema
	 * @param string $type_name   The Type name in the GraphQL Schema to add fields to
	 */
	protected function add_field_group_fields( $field_group, $type_name ) {

		/**
		 * Determine if the field group should be exposed
		 * to graphql
		 */
		if ( ! $this->should_field_group_show_in_graphql( $field_group ) ) {
			return;
		}

		/**
		 * Get the fields in the group.
		 */
		$acf_fields = ! empty( $field_group['sub_fields'] ) ? $field_group['sub_fields'] : acf_get_fields( $field_group['ID'] );

		/**
		 * If there are no fields, bail
		 */
		if ( empty( $acf_fields ) || ! is_array( $acf_fields ) ) {
			return;
		}

		/**
		 * Loop over the fields and register them to the Schema
		 */
		foreach ( $acf_fields as $acf_field ) {

			/**
			 * Setup data for register_graphql_field
			 */
			$name            = ! empty( $acf_field['name'] ) ? self::camelCase( $acf_field['name'] ) : null;
			$show_in_graphql = isset( $acf_field['show_in_graphql'] ) && true !== (bool) $acf_field['show_in_graphql'] ? false : true;
			$description     = isset( $acf_field['instructions'] ) ? $acf_field['instructions'] : __( 'ACF Field added to the Schema by WPGraphQL ACF' );

			/**
			 * If the field is missing a name or a type,
			 * we can't add it to the Schema.
			 */
			if (
				empty( $name ) ||
				true !== $show_in_graphql
			) {
				/**
				 * Uncomment line below to determine what fields are not going to be output
				 * in the Schema.
				 */
				// var_dump( $acf_field );
				continue;
			}

			$config = [
				'name'            => $name,
				'description'     => $description,
				'acf_field'       => $acf_field,
				'acf_field_group' => $field_group,
			];

			$this->register_graphql_field( $type_name, $name, $config );

		}

	}

	protected function add_acf_fields_to_term_objects() {

	}

	protected function add_acf_fields_to_comments() {

	}

	protected function add_acf_fields_to_menu_items() {

	}

	protected function add_acf_fields_to_media_items() {

	}

	protected function add_acf_fields_to_post_object_type( $field_group, $post_type ) {

	}
}
