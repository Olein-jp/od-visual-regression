<?php
/**
 * 公開コンテンツの選択用REST API。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPressの投稿REST APIに依存せず撮影候補を取得する。
 */
final class ODVR_Content_Controller extends WP_REST_Controller {
	/**
	 * APIの名前空間を設定する。
	 */
	public function __construct() {
		$this->namespace = 'odvr/v1';
		$this->rest_base = 'content';
	}

	/**
	 * 投稿タイプ一覧と投稿検索を登録する。
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/post-types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_post_types' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			)
		);
	}

	/**
	 * Cookie認証、REST nonce、管理権限をすべて検査する。
	 *
	 * @param WP_REST_Request $request RESTリクエスト.
	 * @return true|WP_Error 許可またはエラー。
	 */
	public function get_items_permissions_check( $request ) {
		$cookie_user = wp_validate_auth_cookie( '', 'logged_in' );
		if ( ! $cookie_user || get_current_user_id() !== (int) $cookie_user ) {
			return new WP_Error( 'odvr_cookie_required', __( 'ログインCookieが必要です。', 'od-visual-regression' ), array( 'status' => 401 ) );
		}

		$nonce = $request->get_param( '_wpnonce' );
		if ( null === $nonce ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
		}
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'REST nonceが無効です。', 'od-visual-regression' ), array( 'status' => 403 ) );
		}
		if ( ! ODVR_Capabilities::can_manage() ) {
			return new WP_Error( 'odvr_forbidden', __( 'コンテンツを選択する権限がありません。', 'od-visual-regression' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * 検索条件と取得上限を公開する。
	 *
	 * @return array リクエストパラメーターのスキーマ。
	 */
	public function get_collection_params() {
		return array(
			'search'    => array(
				'type'              => 'string',
				'default'           => '',
				'maxLength'         => 200,
				'pattern'           => '^[^\\x00-\\x1f\\x7f]*$',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'post_type' => array(
				'type'              => 'string',
				'enum'              => array_keys( $this->public_post_types() ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'page'      => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'maximum'           => 1000000,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'per_page'  => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * フロントで閲覧できるpublic投稿タイプのみを取得する。
	 *
	 * @return WP_Post_Type[] 名前をキーとする投稿タイプ。
	 */
	private function public_post_types() {
		return array_filter(
			get_post_types( array( 'public' => true ), 'objects' ),
			'is_post_type_viewable'
		);
	}

	/**
	 * 選択可能な投稿タイプ一覧を返す。
	 *
	 * @param WP_REST_Request $request RESTリクエスト.
	 * @return WP_REST_Response 投稿タイプとプレーンテキストのラベル。
	 */
	public function get_post_types( $request ) {
		$items = array();
		foreach ( $this->public_post_types() as $type ) {
			$items[] = array(
				'post_type' => $type->name,
				'label'     => wp_strip_all_tags( $type->labels->name ),
			);
		}
		return rest_ensure_response( $items );
	}

	/**
	 * 公開投稿を検索する。パスワード保護投稿は匿名撮影できないため除外する。
	 *
	 * @param WP_REST_Request $request RESTリクエスト.
	 * @return WP_REST_Response|WP_Error 投稿候補とページング情報。
	 */
	public function get_items( $request ) {
		$types = array_keys( $this->public_post_types() );
		if ( $request['post_type'] ) {
			$types = array_intersect( $types, array( $request['post_type'] ) );
		}
		$items = array();
		$total = 0;
		$pages = 0;
		if ( $types ) {
			$query = new WP_Query(
				array(
					'post_type'           => array_values( $types ),
					'post_status'         => 'publish',
					'has_password'        => false,
					's'                   => $request['search'],
					'paged'               => $request['page'],
					'posts_per_page'      => $request['per_page'],
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'ignore_sticky_posts' => true,
				)
			);
			$total = (int) $query->found_posts;
			$pages = (int) $query->max_num_pages;
			foreach ( $query->posts as $post ) {
				if ( ! is_post_publicly_viewable( $post ) || '' !== $post->post_password ) {
					continue;
				}
				$url = ODVR_Target_URL::validate( get_permalink( $post ) );
				if ( is_wp_error( $url ) ) {
					continue;
				}
				$items[] = array(
					'object_id' => (int) $post->ID,
					'post_type' => $post->post_type,
					'label'     => wp_strip_all_tags( $post->post_title ),
					'url'       => $url,
				);
			}
		}
		if ( $request['page'] > 1 && $request['page'] > $pages ) {
			return new WP_Error( 'odvr_invalid_page', __( '指定したページは存在しません。', 'od-visual-regression' ), array( 'status' => 400 ) );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $pages );
		return $response;
	}
}
