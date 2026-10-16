<?php
namespace enshrined\svgSanitize;

/**
 * Stateless DOM helpers for href handling and element containment.
 *
 * @package enshrined\svgSanitize
 */
class Helper
{
    /**
     * Returns the element's link target, preferring a plain `href` attribute
     * over `xlink:href` (namespace http://www.w3.org/1999/xlink).
     *
     * @param \DOMElement $element
     * @return string|null
     */
    public static function getElementHref(\DOMElement $element)
    {
        if ($element->hasAttribute('href')) {
            return $element->getAttribute('href');
        }
        if ($element->hasAttributeNS('http://www.w3.org/1999/xlink', 'href')) {
            return $element->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
        }
        return null;
    }

    /**
     * Returns the identifier of a same-document reference: `#foo` gives `foo`,
     * `#` gives an empty string. Returns null for non-strings and for values
     * that do not start with `#`.
     *
     * @param string $href
     * @return string|null
     */
    public static function extractIdReferenceFromHref($href)
    {
        if (!is_string($href) || strpos($href, '#') !== 0) {
            return null;
        }
        return substr($href, 1);
    }

    /**
     * Tells whether $needle is $haystack itself or one of its element
     * descendants (recursive, depth-first).
     *
     * @param \DOMElement $needle
     * @param \DOMElement $haystack
     * @return bool
     */
    public static function isElementContainedIn(\DOMElement $needle, \DOMElement $haystack)
    {
        if ($needle === $haystack) {
            return true;
        }
        foreach ($haystack->childNodes as $childNode) {
            if (!$childNode instanceof \DOMElement) {
                continue;
            }
            if (self::isElementContainedIn($needle, $childNode)) {
                return true;
            }
        }
        return false;
    }
}
