<?php

require_once __DIR__ . '/SanitizerTestCase.php';

/**
 * Sanitizer: which link targets survive in `href` and `xlink:href`.
 *
 * The rule is a whitelist of beginnings: empty, `#`, `/`, `http://`,
 * `https://` and a few `data:` image types. Everything else is removed,
 * as is an `xlink:href` outside the XLink namespace.
 */
class SanitizerHrefTest extends SanitizerTestCase {

	/**
	 * @dataProvider provide_safe_targets
	 */
	public function test_keeps_href_with_a_safe_target( $target ) {
		$markup = "<a href=\"$target\"><rect></rect></a>";

		$this->assertSame( $markup, $this->sanitize_inner( $markup ) );
		$this->assertSame( array(), $this->issue_messages() );
	}

	/**
	 * @dataProvider provide_safe_targets
	 */
	public function test_keeps_xlink_href_with_a_safe_target( $target ) {
		$markup = "<a xlink:href=\"$target\"><rect></rect></a>";

		$this->assertSame( $markup, $this->sanitize_inner( $markup, self::XLINK_NS ) );
		$this->assertSame( array(), $this->issue_messages() );
	}

	public function provide_safe_targets() {
		return $this->keyed(
			array(
				'',
				'#fragment',
				'/absolute/path.svg',
				'//example.org/protocol-relative',
				'http://example.org/',
				'https://example.org/page?a=1#b',
				'data:image/png;base64,AAAA',
				'data:image/gif;base64,AAAA',
				'data:image/jpg;base64,AAAA',
				'data:image/jpeg;base64,AAAA',
				'data:image/pjpeg;base64,AAAA',
			)
		);
	}

	/**
	 * @dataProvider provide_unsafe_targets
	 */
	public function test_removes_href_with_any_other_target( $target ) {
		$inner = $this->sanitize_inner( "<a href=\"$target\"><rect/></a>" );

		$this->assertSame( '<a><rect></rect></a>', $inner );
		$this->assertSame( array( "Suspicious attribute 'href'" ), $this->issue_messages() );
	}

	/**
	 * @dataProvider provide_unsafe_targets
	 */
	public function test_removes_xlink_href_with_any_other_target( $target ) {
		$inner = $this->sanitize_inner( "<a xlink:href=\"$target\"><rect/></a>", self::XLINK_NS );

		$this->assertSame( '<a><rect></rect></a>', $inner );
		$this->assertSame( array( "Suspicious attribute 'xlink:href'" ), $this->issue_messages() );
	}

	public function provide_unsafe_targets() {
		return array(
			'javascript'                     => array( 'javascript:alert(1)' ),
			'javascript in mixed case'       => array( 'JaVaScRiPt:alert(1)' ),
			'javascript after a space'       => array( ' javascript:alert(1)' ),
			'javascript split by a tab'      => array( 'java&#x09;script:alert(1)' ),
			'javascript split by a newline'  => array( 'java&#10;script:alert(1)' ),
			'javascript with encoded letter' => array( '&#106;avascript:alert(1)' ),
			'vbscript'                       => array( 'vbscript:msgbox(1)' ),
			'data html'                      => array( 'data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;' ),
			'data svg image'                 => array( 'data:image/svg+xml;base64,AAAA' ),
			'data without a type'            => array( 'data:,x' ),
			'data with a non-image type'     => array( 'data:img/png;base64,AAAA' ),
			'data scheme in upper case'      => array( 'DATA:image/png;base64,AAAA' ),
			'https in upper case'            => array( 'HTTPS://example.org/' ),
			'ftp'                            => array( 'ftp://example.org/' ),
			'file'                           => array( 'file:///etc/passwd' ),
			'mailto'                         => array( 'mailto:someone@example.org' ),
			'relative path'                  => array( 'other.svg' ),
			'relative path with fragment'    => array( 'other.svg#icon' ),
			'fragment after a space'         => array( ' #fragment' ),
		);
	}

	/**
	 * The rule belongs to the attribute, not to `<a>`.
	 */
	public function test_href_rule_applies_to_every_element() {
		$inner = $this->sanitize_inner(
			'<image href="data:image/svg+xml;base64,AAAA"/><image href="data:image/png;base64,AAAA"/><rect href="javascript:alert(1)"/>'
		);

		$this->assertSame( '<image></image><image href="data:image/png;base64,AAAA"></image><rect></rect>', $inner );
	}

	public function test_unsafe_href_and_xlink_href_on_one_element_are_both_removed() {
		$inner = $this->sanitize_inner( '<a href="javascript:a()" xlink:href="javascript:b()"/>', self::XLINK_NS );

		$this->assertSame( '<a></a>', $inner );
		$this->assertSame( array( "Suspicious attribute 'href'", "Suspicious attribute 'xlink:href'" ), $this->issue_messages() );
	}

	/**
	 * The XLink namespace is what counts, not the prefix it is bound to.
	 */
	public function test_removes_unsafe_href_in_the_xlink_namespace_under_another_prefix() {
		$inner = $this->sanitize_inner( '<a x:href="javascript:alert(1)"/>', 'xmlns:x="http://www.w3.org/1999/xlink"' );

		$this->assertSame( '<a></a>', $inner );
	}

	/**
	 * With no declaration for the prefix the attribute is literally named
	 * `xlink:href`. HTML still reads it as a link, so it goes whatever the
	 * target.
	 *
	 * @dataProvider provide_targets_of_an_undeclared_xlink_href
	 */
	public function test_removes_xlink_href_when_the_prefix_is_not_declared( $target ) {
		$inner = $this->sanitize_inner( "<a xlink:href=\"$target\"/>" );

		$this->assertSame( '<a></a>', $inner );
		$this->assertSame( array( "Unexpected attribute 'xlink:href'" ), $this->issue_messages() );
	}

	public function provide_targets_of_an_undeclared_xlink_href() {
		return $this->keyed( array( 'javascript:alert(1)', '#target' ) );
	}

	/**
	 * An `xlink:href` bound to another namespace still acts as a link once
	 * the SVG is inlined in HTML.
	 */
	public function test_removes_xlink_href_when_the_prefix_is_bound_to_another_namespace() {
		$inner = $this->sanitize_inner( '<a xlink:href="javascript:alert(1)"></a>', 'xmlns:xlink="http://example.org/not-xlink"' );

		$this->assertSame( '<a></a>', $inner );
	}
}
