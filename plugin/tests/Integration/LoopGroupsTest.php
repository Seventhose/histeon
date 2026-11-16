<?php

declare(strict_types=1);

namespace XB\Tests\Integration;

/** Doc 23 item 118: a list in groups, a heading per group, with an outer loop over groupBy and an inner one per group. */
final class LoopGroupsTest extends IntegrationTestCase {

	public function test_a_list_grouped_by_year_with_a_heading_each(): void {
		$this->set_option(
			\XB\Render\Sources::OPTION,
			array(
				'zq_foto' => array(
					'type' => 'json',
					'data' => array(
						array( 'titolo' => 'Mare', 'data' => '2025-08-01' ),
						array( 'titolo' => 'Neve', 'data' => '2025-01-10' ),
						array( 'titolo' => 'Fiume', 'data' => '2024-05-02' ),
					),
				),
			)
		);
		$inner = $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'anno.items' ), 'as' => 'f' ), array( $this->el( 'p', array(), array( $this->text( '[{f.titolo}]' ) ) ) ) );
		$outer = $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'data.zq_foto.groupBy("data", "Y")' ), 'as' => 'anno' ), array( $this->el( 'h2', array(), array( $this->text( '{anno.key}' ) ) ), $inner ) );
		$html  = (string) preg_replace( '/\s+/', ' ', $this->render( array( $outer ) ) );
		$this->assertMatchesRegularExpression( '#<h2[^>]*>2025</h2>.*\[Mare\].*\[Neve\].*<h2[^>]*>2024</h2>.*\[Fiume\]#', $html );
	}

	/**
	 * Doc 23 of Publish, item 187: a query's result in groups, `{#loop zq_notizie().groupBy("date", "Y") as anno}`, from
	 * one query (not one per group), each group {key, items} as groupBy makes them from a list, in the query's order.
	 */
	public function test_a_query_result_grouped_by_year_and_by_letter_from_one_query(): void {
		$this->set_option( \XB\Render\LoopPresets::OPTION, array( 'zq_notizie' => array( 'type' => 'posts', 'args' => array( 'post_type' => 'post', 'posts_per_page' => 10, 'orderby' => 'date', 'order' => 'DESC', 'tag' => 'zq-gruppi' ) ) ) );
		foreach ( array( array( 'Mare', '2025-08-01 10:00:00' ), array( 'Neve', '2025-01-10 10:00:00' ), array( 'Fiume', '2024-05-02 10:00:00' ), array( 'alba', '2023-03-03 10:00:00' ) ) as $row ) {
			$this->post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $row[0], 'post_date' => $row[1], 'tags_input' => array( 'zq-gruppi' ) ) );
		}
		$queries = 0;
		$count   = static function () use ( &$queries ): void {
			++$queries;
		};
		add_action( 'pre_get_posts', $count );
		$inner = $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'expr', 'expr' => 'anno.items' ), 'as' => 'n' ), array( $this->el( 'p', array(), array( $this->text( '[{n.title}]' ) ) ) ) );
		$outer = $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'zq_notizie', 'groupBy' => array( 'field' => 'date', 'how' => 'Y' ) ), 'as' => 'anno', 'index' => 'i' ), array( $this->el( 'h2', array(), array( $this->text( '{i}:{anno.key} ({anno.items.length()})' ) ) ), $inner ) );
		$html  = (string) preg_replace( '/\s+/', ' ', $this->render( array( $outer ) ) );
		remove_action( 'pre_get_posts', $count );
		$this->assertMatchesRegularExpression( '#<h2[^>]*>0:2025 \(2\)</h2>.*\[Mare\].*\[Neve\].*<h2[^>]*>1:2024 \(1\)</h2>.*\[Fiume\].*<h2[^>]*>2:2023 \(1\)</h2>.*\[alba\]#', $html );
		$this->assertSame( 1, $queries, 'one query for every group' );

		// By first letter, with arguments of the loop: limit still applies to the query, before the groups.
		$letters = $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'zq_notizie', 'args' => array( 'limit' => '3' ), 'groupBy' => array( 'field' => 'title', 'how' => 'letter' ) ), 'as' => 'l' ), array( $this->text( '«{l.key}:{l.items.pluck("title").join("+")}»' ) ) );
		$this->assertSame( '«M:Mare»«N:Neve»«F:Fiume»', (string) preg_replace( '/\s+/', '', html_entity_decode( $this->render( array( $letters ) ) ) ) );

		// By value, without a mode, and a grouping the site cannot read lists nothing.
		$values = $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'zq_notizie', 'groupBy' => array( 'field' => 'type' ) ), 'as' => 'g' ), array( $this->text( '«{g.key}:{g.items.length()}»' ) ) );
		$this->assertSame( '«post:4»', trim( html_entity_decode( $this->render( array( $values ) ) ) ) );
		foreach ( array( array( 'field' => 'a b' ), array( 'field' => 'date', 'how' => '' ), array( 'field' => 'date', 'order' => 'desc' ), 'date' ) as $invalid ) {
			$broken = $this->block( 'xb/loop', array( 'source' => array( 'kind' => 'preset', 'id' => 'zq_notizie', 'groupBy' => $invalid ), 'as' => 'g' ), array( $this->text( '«{g.key}»' ) ) );
			$this->assertStringNotContainsString( '«', $this->render( array( $broken ) ), (string) wp_json_encode( $invalid ) );
		}
	}
}
