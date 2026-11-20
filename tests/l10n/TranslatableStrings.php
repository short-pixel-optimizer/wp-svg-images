<?php

/**
 * Finds the translatable strings of the plugin's PHP files and reads the
 * entries of its translation template, so the two can be compared.
 */
class TranslatableStrings {

	/** WordPress translation functions and the position of their text domain argument. */
	const FUNCTIONS = array(
		'__'            => 1,
		'_e'            => 1,
		'esc_html__'    => 1,
		'esc_html_e'    => 1,
		'esc_attr__'    => 1,
		'esc_attr_e'    => 1,
		'_x'            => 2,
		'_ex'           => 2,
		'esc_html_x'    => 2,
		'esc_attr_x'    => 2,
		'_n'            => 3,
		'_nx'           => 4,
		'_n_noop'       => 2,
		'_nx_noop'      => 3,
		'translate'     => 1,
	);

	/**
	 * Lists every call of a translation function in a PHP file.
	 *
	 * @param string $file Absolute path.
	 * @return array[] One entry per call: `function`, `line`, `text` (null
	 *                 when the first argument is not a plain string) and
	 *                 `domain` (null when missing or not a plain string).
	 */
	public static function in_file( $file ) {
		$tokens = token_get_all( file_get_contents( $file ) );
		$count  = count( $tokens );
		$calls  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( self::FUNCTIONS[ $token[1] ] ) ) {
				continue;
			}
			$previous = self::significant( $tokens, $i, -1 );
			if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}
			$open = self::significant_index( $tokens, $i, 1 );
			if ( null === $open || '(' !== $tokens[ $open ] ) {
				continue;
			}

			$arguments = self::arguments( $tokens, $open );
			$domain_at = self::FUNCTIONS[ $token[1] ];
			$calls[]   = array(
				'function' => $token[1],
				'line'     => $token[2],
				'text'     => isset( $arguments[0] ) ? self::plain_string( $arguments[0] ) : null,
				'domain'   => isset( $arguments[ $domain_at ] ) ? self::plain_string( $arguments[ $domain_at ] ) : null,
			);
		}

		return $calls;
	}

	/**
	 * Reads the message IDs of a translation template.
	 *
	 * @param string $file Absolute path of a .pot file.
	 * @return string[] Message IDs without the empty header entry.
	 */
	public static function in_template( $file ) {
		$ids     = array();
		$current = null;
		foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( preg_match( '/^msgid "(.*)"$/', $line, $match ) ) {
				$current = $match[1];
			} elseif ( null !== $current && preg_match( '/^"(.*)"$/', $line, $match ) ) {
				$current .= $match[1];
			} elseif ( null !== $current ) {
				$ids[]   = stripcslashes( $current );
				$current = null;
			}
		}
		if ( null !== $current ) {
			$ids[] = stripcslashes( $current );
		}

		return array_values( array_filter( $ids, 'strlen' ) );
	}

	/**
	 * @param array $tokens
	 * @param int   $from      Index to start after.
	 * @param int   $direction 1 or -1.
	 * @return int|null Index of the next token that is not whitespace or a comment.
	 */
	private static function significant_index( array $tokens, $from, $direction ) {
		for ( $i = $from + $direction; isset( $tokens[ $i ] ); $i += $direction ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return $i;
		}

		return null;
	}

	/**
	 * @param array $tokens
	 * @param int   $from
	 * @param int   $direction
	 * @return array|string|null The token found by significant_index().
	 */
	private static function significant( array $tokens, $from, $direction ) {
		$index = self::significant_index( $tokens, $from, $direction );

		return null === $index ? null : $tokens[ $index ];
	}

	/**
	 * Splits the tokens of a call's argument list at its top-level commas.
	 *
	 * @param array $tokens
	 * @param int   $open   Index of the opening parenthesis.
	 * @return array[] Tokens of each argument, whitespace and comments removed.
	 */
	private static function arguments( array $tokens, $open ) {
		$arguments = array( array() );
		$depth     = 0;
		for ( $i = $open + 1; isset( $tokens[ $i ] ); $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( in_array( $token, array( '(', '[', '{' ), true ) ) {
				++$depth;
			} elseif ( in_array( $token, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $depth ) {
					break;
				}
				--$depth;
			} elseif ( ',' === $token && 0 === $depth ) {
				$arguments[] = array();
				continue;
			}
			$arguments[ count( $arguments ) - 1 ][] = $token;
		}

		return array_values( array_filter( $arguments ) );
	}

	/**
	 * @param array $argument Tokens of one argument.
	 * @return string|null The string's value when the argument is a single
	 *                     quoted string without variables, null otherwise.
	 */
	private static function plain_string( array $argument ) {
		if ( 1 !== count( $argument ) || ! is_array( $argument[0] ) || T_CONSTANT_ENCAPSED_STRING !== $argument[0][0] ) {
			return null;
		}
		$source = $argument[0][1];
		$inner  = substr( $source, 1, -1 );

		return "'" === $source[0] ? str_replace( array( "\\\\", "\\'" ), array( "\\", "'" ), $inner ) : stripcslashes( $inner );
	}
}
