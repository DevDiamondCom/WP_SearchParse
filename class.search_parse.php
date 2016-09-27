<?php
/**
 * class SearchParse, to optimize / filtering of search words
 *
 * @link   http://devdiamond.com/
 * @author DevDiamond <me@devdiamond.com>
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

	/**
	 * Search filter on post title
	 *
	 * @param string $like_type  - "AND" or "OR"
	 */
	public static function search_set_filter_post_title( $like_type = 'AND' )
	{
		$like_type = ($like_type === 'OR' ? 'OR' : 'AND');

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
	 * @param string $search_content    - Search content
	 * @param string $stopwords         - SopWords list prescribed by a comma separated, space, or new line
	 * @param bool   $is_stemmer        - OFF/ON Stemmer filter
	 * @param bool   $is_count_keyword  - OFF/ON Max 9 keyword
	 *
	 * @return string
	 */
	public static function parse_stopwords( $search_content, $stopwords, $is_stemmer = false, $is_count_keyword = false )
	{
		if ( ! is_string($search_content) || empty($search_content) ||
			 ! is_string($stopwords) || empty($stopwords) )
			return '';

		# StopWords
		$stopwords = trim( preg_replace("/(\s|\r\n|\r|\n)+/im", ',', $stopwords), ',' );
		$stopwords = preg_replace("/\*/i", '(.*?)', $stopwords);
		$stopwords = explode(',', $stopwords);
		$search_content = ' '.$search_content.' ';
		foreach ( $stopwords as $f_word )
			$search_content = preg_replace("/\s". $f_word ."\s/i", ' ', $search_content);

		# Filter words
		if ( preg_match_all( '/".*?("|$)|((?<=[\t ",+])|^)[^\t ",+]+/', $search_content, $matches ) )
		{
			$search_terms = self::parse_search_terms( $matches[0], $is_stemmer );
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
	 * Check if the terms are suitable for searching.
	 *
	 * @param array $terms       - Terms to check.
	 * @param bool  $is_stemmer  - OFF/ON Stemmer filter
	 *
	 * @return array - Checked Terms
	 */
	private static function parse_search_terms( $terms, $is_stemmer )
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