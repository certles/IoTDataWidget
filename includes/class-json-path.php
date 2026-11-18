<?php
/**
 * Uproszczony resolver ścieżek JSON (notacja kropkowa) — współdzielony przez
 * konektory REST i Shelly Cloud (v1.3 Tydzień 8), żeby oba parsowały
 * "$.data.temperature"-style ścieżki identycznie zamiast dwóch niezależnych
 * kopii tej samej logiki.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Json_Path
 *
 * To NIE jest pełna specyfikacja JSONPath (bez filtrów, wildcardów,
 * zakresów) — celowe uproszczenie pod MVP, niezmienione od IOTDW_REST_Connector
 * (patrz jego komentarz klasy o trybie zaawansowanym).
 */
class IOTDW_Json_Path {

	/**
	 * Rozwiązuje uproszczoną ścieżkę kropkową w zdekodowanej strukturze JSON.
	 *
	 * @param mixed  $data Zdekodowana odpowiedź JSON (tablica asocjacyjna/indeksowana).
	 * @param string $path Ścieżka, np. "data.temperature" albo "$.readings.0.value".
	 * @return mixed|null Null, gdy ścieżka nie istnieje.
	 */
	public static function resolve( $data, string $path ) {
		$path = trim( $path );

		// Literalny prefiks "$." (albo samo "$"), nie maska znaków — ltrim()
		// z maską usuwałby też wiodące znaki '$'/'.' będące częścią pierwszego
		// prawdziwego segmentu ścieżki (np. klucz JSON dosłownie nazwany
		// "$ref"), nie tylko sam prefiks korzenia. Znalezione przy review.md
		// (docs/archiwum/review-log.md, 2026-08-06).
		if ( str_starts_with( $path, '$.' ) ) {
			$path = substr( $path, 2 );
		} elseif ( '$' === $path ) {
			$path = '';
		}

		if ( '' === $path ) {
			return $data;
		}

		$current = $data;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}

			$current = $current[ $segment ];
		}

		return $current;
	}
}
