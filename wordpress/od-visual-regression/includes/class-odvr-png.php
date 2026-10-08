<?php
/**
 * PNGのチャンク・完全展開・画像デコードを検証する。
 *
 * @package OD_Visual_Regression
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** PNG検証。クライアントの拡張子や申告MIMEを信用しない。 */
final class ODVR_PNG {
	const MAX_BYTES  = 20971520;
	const MAX_EDGE   = 16384;
	const MAX_PIXELS = 40000000;

	/**
	 * デコードに必要な空きメモリを検査する。
	 *
	 * @param int $pixels 画素数.
	 * @param int $bytes 圧縮サイズ.
	 * @return bool 利用可能.
	 */
	public static function available( $pixels = self::MAX_PIXELS, $bytes = self::MAX_BYTES ) {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'inflate_init' ) || ! class_exists( 'finfo' ) ) {
			return false;
		}
		$limit = ini_get( 'memory_limit' );
		if ( '-1' === $limit ) {
			return true;
		}
		$units = array(
			'g' => 1073741824,
			'm' => 1048576,
			'k' => 1024,
		);
		$unit  = strtolower( substr( $limit, -1 ) );
		$limit = (int) $limit * ( isset( $units[ $unit ] ) ? $units[ $unit ] : 1 );
		return $limit - memory_get_usage( true ) >= $pixels * 16 + $bytes * 3 + 67108864;
	}

	/**
	 * 完全PNGを検証し、実寸とdigestを返す。
	 *
	 * @param string $data PNG本体.
	 * @param int    $width 期待幅.
	 * @param int    $height 期待高さ.
	 * @param string $digest 期待digest。省略時はサーバー計算だけ.
	 * @return array|WP_Error 実寸・digest・サイズ.
	 */
	public static function validate( $data, $width, $height, $digest = null ) {
		$bad = new WP_Error( 'odvr_invalid_png', __( 'PNGが破損しているか、画像の制限を超えています。', 'od-visual-regression' ), array( 'status' => 400 ) );
		if ( ! is_string( $data ) ) {
			return $bad;
		}
		$length = strlen( $data );
		if ( $length > self::MAX_BYTES || $length < 57 || "\x89PNG\r\n\x1a\n" !== substr( $data, 0, 8 ) || ! is_int( $width ) || ! is_int( $height ) || $width < 1 || $height < 1 || $width > self::MAX_EDGE || $height > self::MAX_EDGE || $width * $height > self::MAX_PIXELS ) {
			return $bad;
		}
		if ( ! self::available( $width * $height, $length ) ) {
			return new WP_Error( 'odvr_storage_unavailable', __( 'PNGの完全検証に必要なメモリまたは拡張がありません。', 'od-visual-regression' ), array( 'status' => 503 ) );
		}
		$position    = 8;
		$header      = null;
		$compressed  = '';
		$ended       = false;
		$idat_closed = false;
		$palette     = false;
		$seen        = array();
		while ( $position + 12 <= $length ) {
			$size = unpack( 'N', substr( $data, $position, 4 ) )[1];
			$type = substr( $data, $position + 4, 4 );
			if ( $size > $length - $position - 12 || ! preg_match( '/^[A-Za-z]{4}$/D', $type ) || ctype_lower( $type[2] ) ) {
				return $bad;
			}
			$body = substr( $data, $position + 8, $size );
			if ( ! hash_equals( hash( 'crc32b', $type . $body, true ), substr( $data, $position + 8 + $size, 4 ) ) || ( null === $header && 'IHDR' !== $type ) || in_array( $type, array( 'acTL', 'fcTL', 'fdAT', 'iCCP', 'zTXt', 'iTXt' ), true ) ) {
				return $bad;
			}
			if ( isset( $seen['IDAT'] ) && 'IDAT' !== $type ) {
				$idat_closed = true;
			}
			if ( 'IHDR' === $type ) {
				if ( null !== $header || 13 !== $size ) {
					return $bad;
				}
				$header = unpack( 'Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $body );
				$depths = array(
					0 => array( 1, 2, 4, 8, 16 ),
					2 => array( 8, 16 ),
					3 => array( 1, 2, 4, 8 ),
					4 => array( 8, 16 ),
					6 => array( 8, 16 ),
				);
				if ( $width !== $header['width'] || $height !== $header['height'] || ! isset( $depths[ $header['color'] ] ) || ! in_array( $header['depth'], $depths[ $header['color'] ], true ) || 0 !== $header['compression'] || 0 !== $header['filter'] || $header['interlace'] > 1 ) {
					return $bad;
				}
			} elseif ( 'PLTE' === $type ) {
				if ( $palette || isset( $seen['IDAT'] ) || 0 === $size || $size > 768 || 0 !== $size % 3 || in_array( $header['color'], array( 0, 4 ), true ) || ( 3 === $header['color'] && $size / 3 > 2 ** $header['depth'] ) ) {
					return $bad;
				}
				$palette = true;
			} elseif ( 'IDAT' === $type ) {
				if ( $idat_closed || ( 3 === $header['color'] && ! $palette ) ) {
					return $bad;
				}
				$compressed .= $body;
			} elseif ( 'IEND' === $type ) {
				if ( 0 !== $size || ! isset( $seen['IDAT'] ) || $position + 12 !== $length ) {
					return $bad;
				}
				$ended = true;
			} elseif ( ctype_upper( $type[0] ) ) {
				return $bad;
			}
			$seen[ $type ] = true;
			$position     += $size + 12;
		}
		if ( ! $ended || $position !== $length || '' === $compressed ) {
			return $bad;
		}
		$channels = array(
			0 => 1,
			2 => 3,
			3 => 1,
			4 => 2,
			6 => 4,
		);
		$passes   = $header['interlace'] ? array( array( 0, 0, 8, 8 ), array( 4, 0, 8, 8 ), array( 0, 4, 4, 8 ), array( 2, 0, 4, 4 ), array( 0, 2, 2, 4 ), array( 1, 0, 2, 2 ), array( 0, 1, 1, 2 ) ) : array( array( 0, 0, 1, 1 ) );
		$rows     = array();
		$expected = 0;
		foreach ( $passes as $pass ) {
			$pw = max( 0, (int) ceil( ( $width - $pass[0] ) / $pass[2] ) );
			$ph = max( 0, (int) ceil( ( $height - $pass[1] ) / $pass[3] ) );
			if ( $pw && $ph ) {
				$row       = 1 + (int) ceil( $pw * $channels[ $header['color'] ] * $header['depth'] / 8 );
				$rows[]    = array( $row, $ph );
				$expected += $row * $ph;
			}
		}
		$inflate           = inflate_init( ZLIB_ENCODING_DEFLATE );
		$decoded           = '';
		$compressed_length = strlen( $compressed );
		for ( $offset = 0; $offset < $compressed_length; $offset += 256 ) {
			$part = @inflate_add( $inflate, substr( $compressed, $offset, 256 ), ZLIB_SYNC_FLUSH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 破損入力を定型エラーへ変換する.
			if ( false === $part || strlen( $decoded ) + strlen( $part ) > $expected ) {
				return $bad;
			}
			$decoded .= $part;
		}
		if ( ZLIB_STREAM_END !== inflate_get_status( $inflate ) || inflate_get_read_len( $inflate ) !== strlen( $compressed ) || strlen( $decoded ) !== $expected ) {
			return $bad;
		}
		$offset = 0;
		foreach ( $rows as $row ) {
			for ( $index = 0; $index < $row[1]; ++$index ) {
				if ( ord( $decoded[ $offset ] ) > 4 ) {
					return $bad;
				}
				$offset += $row[0];
			}
		}
		unset( $decoded, $compressed );
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		if ( 'image/png' !== $finfo->buffer( $data ) ) {
			return $bad;
		}
		$warning = false;
		// GDのAdam7対応が返す既知の運用警告だけを除き、破損を警告するデコードは拒否する.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- decoderの警告も検証結果に含める.
		set_error_handler(
			function ( $level, $message ) use ( &$warning, $header ) {
				if ( 1 !== $header['interlace'] || 'imagecreatefromstring(): gd-png: libpng warning: Interlace handling should be turned on when using png_read_image' !== trim( $message ) ) {
					$warning = true;
				}
				return true;
			}
		);
		try {
			$image = imagecreatefromstring( $data );
		} finally {
			restore_error_handler();
		}
		if ( false === $image ) {
			return $bad;
		}
		$valid = ! $warning && imagesx( $image ) === $width && imagesy( $image ) === $height;
		imagedestroy( $image );
		$actual = hash( 'sha256', $data );
		if ( ! $valid || ( null !== $digest && ( ! is_string( $digest ) || ! hash_equals( $actual, $digest ) ) ) ) {
			return $bad;
		}
		return array(
			'width'  => $width,
			'height' => $height,
			'sha256' => $actual,
			'bytes'  => $length,
		);
	}
}
