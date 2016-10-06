<?php
/*
  Copyright (C) 2016 DevDiamond. (email : me@devdiamond.com)

  This program is free software; you can redistribute it and/or
  modify it under the terms of the GNU General Public License
  as published by the Free Software Foundation; either version 2
  of the License, or (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/
/**
 * class WP_SearchParse - to optimize | searching | filtering of search words
 *
 * @link    https://github.com/DevDiamondCom/WP_SearchParse
 * @version 1.1.1
 * @author  DevDiamond <me@devdiamond.com>
 * @license GPLv2 or later
 */
class WP_SearchParse
{
	CONST UNSET_PRETEXT = true;

	CONST REGEXP_PRETEXT = '/(^|\s)(и|для|в|на|под|из|с|по)(\s|$)/u';
	CONST RVRE = '/^(.*?[аеиоуыэюя])(.*)$/u';
	CONST PERFECTIVEGROUND = '/((ив|ивши|ившись|ыв|ывши|ывшись)|((?<=[ая])(в|вши|вшись)))$/u';
	CONST REFLEXIVE = '/(с[яь])$/u';
	CONST ADJECTIVE = '/(ее|ие|ые|ое|ими|ыми|ей|ий|ый|ой|ем|им|ым|ом|его|ого|еых|ую|юю|ая|яя|ою|ею)$/u';
	CONST PARTICIPLE = '/((ивш|ывш|ующ)|((?<=[ая])(ем|нн|вш|ющ|щ)))$/u';
	CONST VERB = '/((ила|ыла|ена|ейте|уйте|ите|или|ыли|ей|уй|ил|ыл|им|ым|ены|ить|ыть|ишь|ую|ю)|((?<=[ая])(ла|на|ете|йте|ли|й|л|ем|н|ло|но|ет|ют|ны|ть|ешь|нно)))$/u';
	CONST NOUN = '/(а|ев|ов|ие|ье|е|иями|ями|ами|еи|ии|и|ией|ей|ой|ий|й|и|ы|ь|ию|ью|ю|ия|ья|я)$/u';
	CONST DERIVATIONAL = '/[^аеиоуыэюя][аеиоуыэюя]+[^аеиоуыэюя]+[аеиоуыэюя].*(?<=о)сть?$/u';

	private static $search_phrase = '';
	private static $search_cache;

	/**
	 * Search filter on post title
	 *
	 * @param string $is_occurrences  - FALSe => "AND", TRUE => "OR"
	 */
	public static function search_set_filter_post_title( $is_occurrences )
	{
		$like_type = ($is_occurrences ? 'OR' : 'AND');

		add_filter('posts_search', function( $search, &$wp_query ) use ( $like_type )
		{
			global $wpdb;
			if ( empty($search) )
				return $search;
			$q = $wp_query->query_vars;
			$n = ! empty($q['exact']) ? '' : '%';
			$search = $searchand = '';
			foreach ( (array) $q['search_terms'] as $term )
			{
				$term = esc_sql(like_escape($term));
				$search .= "{$searchand}($wpdb->posts.post_title LIKE '{$n}{$term}{$n}')";
				$searchand = ' '. $like_type .' ';
			}
			if ( !empty($search) )
			{
				$search = " AND ({$search}) ";
				if ( ! is_user_logged_in() )
					$search .= " AND ($wpdb->posts.post_password = '') ";
			}
			return $search;
		}, 99, 2);
	}

	/**
	 * Parse StopWords
	 *
	 * @param string    $search_content    - Search content
	 * @param string    $stopwords         - SopWords list prescribed by a comma separated, space, or new line
	 * @param int       $min_characters    - Min count characters
	 * @param bool|true $is_stemmer        - OFF/ON Stemmer filter
	 * @param bool|true $is_count_keyword  - OFF/ON Max 9 keyword
	 *
	 * @return string
	 */
	public static function parse_stopwords( $search_content, $stopwords, $min_characters = 3, $is_stemmer = true, $is_count_keyword = true )
	{
		if ( ! is_string($search_content) || empty($search_content) ||
			 ! is_string($stopwords) || empty($stopwords) )
			return '';

		$min_characters = (int) $min_characters;

		# StopWords
		$stopwords = trim( preg_replace("/(\s|\r\n|\r|\n)+/imu", ',', $stopwords), ',' );
		$stopwords = preg_replace("/\*/iu", '(.*?)', $stopwords);
		$stopwords = explode(',', $stopwords);

		$search_content = preg_replace("/(\.|\,|\:|\;|\?|\!|\-|\"|\')+/u", ' ', $search_content);
		foreach ( $stopwords as $f_word )
			$search_content = preg_replace("/(^|\s)". $f_word ."(\s|$)/iu", ' ', $search_content);

		# Filter words
		if ( preg_match_all( '/".*?("|$)|((?<=[\t ",+])|^)[^\t ",+]+/', $search_content, $matches ) )
		{
			$search_terms = self::parse_search_terms( $matches[0], $min_characters, $is_stemmer );
			$search_terms = array_unique( $search_terms, SORT_STRING );
			if ($is_count_keyword)
			{
				while (count( $search_terms ) > 9)
					unset($search_terms[ (count( $search_terms )-1) ]);
				$search_content = implode(" ", $search_terms);
			}
		}
		else
		{
			return '';
		}

		return trim($search_content);
	}

	/**
	 * @param array $wp_query_args  - WP_Query class data
	 * @param array $search_columns - Search columns. "post_title", "post_excerpt", "post_content"
	 *
	 * @return WP_Query
	 */
	public static function semantic_search( &$wp_query_args, $search_columns )
	{
		if ( ! isset( $wp_query_args['s'] ) || ! is_string($wp_query_args['s']) || $wp_query_args['s'] == '' )
			return new WP_Query();

		if ( ! ($is_post_title = in_array('post_title', $search_columns)) &&
			 ! ($is_post_excerpt = in_array('post_excerpt', $search_columns)) &&
			 ! ($is_post_content = in_array('post_content', $search_columns)) )
			return new WP_Query();

		$search_terms = explode(' ', $wp_query_args['s']);
		if ( count( $search_terms ) > 9 )
			$search_terms = array_slice( $search_terms, 0, 9, true );

		if ( ! isset($wp_query_args['posts_per_page']) || 1 > (int) $wp_query_args['posts_per_page'] )
		{
			if ( 1 > ($wp_query_args['posts_per_page'] = get_option( 'posts_per_page' )) )
				return new WP_Query();
		}

		if ( self::$search_phrase == $wp_query_args['s'] && isset(self::$search_cache) )
			return self::$search_cache;
		self::$search_phrase = $wp_query_args['s'];

		if ( count( $search_terms ) < 2 )
		{
			$wp_query_args['showposts'] = $wp_query_args['posts_per_page'];
			return new WP_Query($wp_query_args);
		}

		global $wpdb;

		$search_w = '';

		// post_type
		if ( isset($wp_query_args['post_type']) )
			$search_w .= "post_type IN ('" . join("', '", (array) $wp_query_args['post_type']) . "')";

		// post_status
		if ( isset($wp_query_args['post_status']) )
		{
			$q_status = (array) $wp_query_args['post_status'];
			if ( in_array( 'any', $q_status ) )
			{
				foreach ( get_post_stati() as $status )
				{
					if ( ! in_array( $status, $q_status ) )
						$q_status[] = $status;
				}
			}
			$search_w .= ($search_w ? ' AND ' : '') . "post_status IN ('" . join("', '", $q_status) . "')";
		}

		// post__not_in
		if ( isset($wp_query_args['post__not_in']) )
		{
			$post__not_in = implode(',',  array_map( 'absint', $wp_query_args['post__not_in'] ));
			$search_w .= ($search_w ? ' AND ' : '') . "ID NOT IN ($post__not_in)";
		}

		if ($search_w)
			$search_w .= ' AND ';

		// UNION and LIKE
		$is_logged = is_user_logged_in();
		$search = '';
		foreach ( $search_terms as $term )
		{
			$search_l = '';
			$term = esc_sql($wpdb->esc_like($term));
			if ( $is_post_title )
				$search_l .= "(post_title LIKE '%{$term}%')";
			if ( $is_post_excerpt )
				$search_l .= ($search_l ? ' OR ' : '')."(post_excerpt LIKE '%{$term}%')";
			if ( $is_post_content )
				$search_l .= ($search_l ? ' OR ' : '')."(post_content LIKE '%{$term}%')";
			$search .= ($search ? "\n UNION ALL \n" : '') . "SELECT ID FROM {$wpdb->posts} WHERE {$search_w}({$search_l})";
			if ( ! $is_logged )
				$search .= " AND (post_password = '')";
		}

		// order => DESC
		if ( isset($wp_query_args['order']) && $wp_query_args['order'] === 'DESC' )
			$search .= "\n ORDER BY ID DESC";

		$query_res = $wpdb->get_results( $search );
		if ( empty($query_res) )
			return new WP_Query();

		$arrQ = [];
		foreach ( $query_res as $qVal )
			$arrQ[ $qVal->ID ] = isset($arrQ[ $qVal->ID ]) ? ($arrQ[ $qVal->ID ] + 1) : 1;

		if (arsort($arrQ,SORT_NUMERIC))
		{
			$found_posts = count($arrQ);
			$arrQ = array_slice( $arrQ, 0, $wp_query_args['posts_per_page'], true );
			unset($wp_query_args['s']);
			$wp_query_args['post__in'] = array_keys($arrQ);
			$query = new WP_Query($wp_query_args);
			$query->found_posts = $found_posts;
			$query->max_num_pages = ($found_posts / $wp_query_args['posts_per_page']);
		}
		else
		{
			$query = new WP_Query();
		}

		self::$search_cache = $query;
		return $query;
	}

	/**
	 * Check if the terms are suitable for searching.
	 *
	 * @param array $terms           - Terms to check.
	 * @param int   $min_characters  - Min count characters
	 * @param bool  $is_stemmer      - OFF/ON Stemmer filter
	 *
	 * @return array - Checked Terms
	 */
	private static function parse_search_terms( $terms, $min_characters, $is_stemmer )
	{
		$checked = array();

		foreach ( $terms as $term )
		{
			// keep before/after spaces when term is for exact match
			if ( preg_match( '/^".+"$/', $term ) )
				$term = trim( $term, "\"'" );
			else
				$term = trim( $term, "\"' " );

			// Avoid single A-Z and single dashes.
			if ( ! $term || ( 1 === strlen( $term ) && preg_match( '/^[a-z\-]$/i', $term ) ) )
				continue;

			if ( $is_stemmer )
			{
				// mb_regex_encoding( 'UTF-8' );
				// mb_internal_encoding( 'UTF-8' );
				$term = mb_strtolower($term);
				$term = str_ireplace('ё', 'е', $term);

				if ( self::UNSET_PRETEXT === TRUE )
					$term = preg_replace( self::REGEXP_PRETEXT, ' ', $term);

				do {
					if ( ! preg_match(self::RVRE, $term, $m) )
						break;
					$RV = $m[2];
					if ( ! $RV )
						break;

					# Step 1
					if ( !self::s($RV, self::PERFECTIVEGROUND, '') )
					{
						self::s($RV, self::REFLEXIVE, '');

						if ( self::s($RV, self::ADJECTIVE, '') )
							self::s($RV, self::PARTICIPLE, '');
						elseif ( !self::s($RV, self::VERB, '') )
							self::s($RV, self::NOUN, '');
					}

					# Step 2
					self::s($RV, '/и$/', '');

					# Step 3
					if ( preg_match($RV, self::DERIVATIONAL) )
						self::s($RV, '/ость?$/', '');

					# Step 4
					if ( !self::s($RV, '/ь$/', '') )
					{
						self::s($RV, '/ейше?/', '');
						self::s($RV, '/нн$/', 'н');
					}

					$term = $m[1] . $RV;
				} while(false);

				if ( empty($term) )
					continue;
			}

			if ( mb_strlen($term) < $min_characters )
				continue;

			$checked[] = $term;
		}

		return $checked;
	}

	/**
	 * Check and Replace
	 *
	 * @param string $s  - word
	 * @param string $re - replace
	 * @param string $to - to
	 *
	 * @return bool
	 */
	private static function s(&$s, $re, $to)
	{
		$orig = $s;
		$s = preg_replace($re, $to, $s);
		return $orig !== $s;
	}
}