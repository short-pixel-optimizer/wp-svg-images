<?php
namespace enshrined\svgSanitize;

use enshrined\svgSanitize\data\AllowedAttributes;
use enshrined\svgSanitize\data\AllowedTags;
use enshrined\svgSanitize\data\AttributeInterface;
use enshrined\svgSanitize\data\TagInterface;
use enshrined\svgSanitize\data\XPath;
use enshrined\svgSanitize\ElementReference\Resolver;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Whitelist-based SVG sanitizer (bundled copy of enshrined/svg-sanitize).
 *
 * Parses the input with DOMDocument, removes elements and attributes that are
 * not whitelisted, unsafe hrefs, non-element nodes such as comments and
 * processing instructions, `<font>` elements that break out of foreign
 * content, and `<use>` elements that reference external resources, form
 * loops, nest too deeply or expand beyond the use threshold. Everything
 * removed is recorded in getXmlIssues().
 *
 * CDATA sections are passed through HTMLPurifier, which is not shipped with
 * this plugin.
 *
 * @todo The bundled copy does not include HTMLPurifier, so any CDATA section
 *       makes sanitize() fail with "Class HTMLPurifier not found" unless
 *       another plugin happens to load it. Bundle HTMLPurifier with the
 *       library.
 *
 * @package enshrined\svgSanitize
 */
class Sanitizer
{

    /**
     * @var \DOMDocument
     */
    protected $xmlDocument;

    /**
     * @var array
     */
    protected $allowedTags;

    /**
     * @var array
     */
    protected $allowedAttrs;

    /**
     * @var
     */
    protected $xmlLoaderValue;

    /**
     * @var bool
     */
    protected $minifyXML = false;

    /**
     * @var bool
     */
    protected $removeRemoteReferences = false;

    /**
     * @var int
     */
    protected $useThreshold = 1000;

    /**
     * @var bool
     */
    protected $removeXMLTag = false;

    /**
     * @var int
     */
    protected $xmlOptions = LIBXML_NOEMPTYTAG;

    /**
     * @var array
     */
    protected $xmlIssues = array();

    /**
     * @var Resolver
     */
    protected $elementReferenceResolver;

    /**
     * @var int
     */
    protected $useNestingLimit = 15;

    /**
     * Loads the default tag and attribute whitelists, lower-cased.
     */
    function __construct()
    {
        // Load default tags/attributes
        $this->allowedAttrs = array_map('strtolower', AllowedAttributes::getAttributes());
        $this->allowedTags = array_map('strtolower', AllowedTags::getTags());
    }

    /**
     * Set up the DOMDocument
     */
    protected function resetInternal()
    {
        $this->xmlDocument = new \DOMDocument();
        $this->xmlDocument->preserveWhiteSpace = false;
        $this->xmlDocument->strictErrorChecking = false;
        $this->xmlDocument->formatOutput = !$this->minifyXML;
    }

    /**
     * Set XML options to use when saving XML
     * See: DOMDocument::saveXML
     *
     * @param int  $xmlOptions
     */
    public function setXMLOptions($xmlOptions)
    {
        $this->xmlOptions = $xmlOptions;
    }

    /**
     * Get XML options to use when saving XML
     * See: DOMDocument::saveXML
     *
     * @return int
     */
    public function getXMLOptions()
    {
        return $this->xmlOptions;
    }

    /**
     * Get the array of allowed tags
     *
     * @return array
     */
    public function getAllowedTags()
    {
        return $this->allowedTags;
    }

    /**
     * Set custom allowed tags
     *
     * @param TagInterface $allowedTags
     */
    public function setAllowedTags(TagInterface $allowedTags)
    {
        $this->allowedTags = array_map('strtolower', $allowedTags::getTags());
    }

    /**
     * Get the array of allowed attributes
     *
     * @return array
     */
    public function getAllowedAttrs()
    {
        return $this->allowedAttrs;
    }

    /**
     * Set custom allowed attributes
     *
     * @param AttributeInterface $allowedAttrs
     */
    public function setAllowedAttrs(AttributeInterface $allowedAttrs)
    {
        $this->allowedAttrs = array_map('strtolower', $allowedAttrs::getAttributes());
    }

    /**
     * Should we remove references to remote files?
     *
     * When enabled, an attribute whose whole value is a quoted `url(...)`
     * pointing to an http, https, ftp, file or protocol-relative address is
     * removed. `href` attributes, `style` attributes and the content of
     * `<style>` elements are not examined.
     *
     * @param bool $removeRemoteRefs
     */
    public function removeRemoteReferences($removeRemoteRefs = false)
    {
        $this->removeRemoteReferences = $removeRemoteRefs;
    }

    /**
     * Get XML issues.
     *
     * @return array
     */
    public function getXmlIssues() {
        return $this->xmlIssues;
    }


    /**
     * Sanitize the passed string
     *
     * PHP tags (`<?php ... ?>`, `<?= ... ?>`) written on one line are
     * stripped before parsing; a PHP tag that spans several lines is removed
     * later as a processing instruction. Empty input (including "0") returns
     * an empty string without resetting getXmlIssues(). The output keeps
     * the XML declaration unless removeXMLTag() is enabled, and has every
     * whitespace run collapsed to one space when minify() is enabled.
     *
     * @param string $dirty
     * @return string|false Sanitized XML, or false when the input is not
     *                      well-formed XML.
     * @throws \LogicException When the document's root is not a single
     *                         `<svg>` element (see XPath), and in the case
     *                         described on Resolver::processReferences().
     * @throws \Error          When the document contains a CDATA section and
     *                         HTMLPurifier is not loaded.
     *
     * @todo A document whose root is a prefixed `<x:svg>` passes the root
     *       check, then has its root removed because `x:svg` is not on the
     *       whitelist: the method returns an XML declaration and nothing
     *       else, even with removeXMLTag() enabled, instead of failing.
     *       Suggested fix: return false when no root element is left.
     * @todo An entity reference inside an attribute value (`width="&x;"`) is
     *       kept while the DOCTYPE that declares the entity is removed, so
     *       the output is not well-formed XML. Suggested fix: remove
     *       attributes that contain entity references.
     */
    public function sanitize($dirty)
    {
        // Don't run on an empty string
        if (empty($dirty)) {
            return '';
        }

        // Strip php tags
        $dirty = preg_replace('/<\?(=|php)(.+?)\?>/i', '', $dirty);

        $this->resetInternal();
        $this->setUpBefore();

        $loaded = $this->xmlDocument->loadXML($dirty);

        // If we couldn't parse the XML then we go no further. Reset and return false
        if (!$loaded) {
            $this->resetAfter();
            return false;
        }

        // Pre-process all identified elements
        $xPath = new XPath($this->xmlDocument);
        $this->elementReferenceResolver = new Resolver($xPath, $this->useNestingLimit);
        $this->elementReferenceResolver->collect();
        $elementsToRemove = $this->elementReferenceResolver->getElementsToRemove();

        // Start the cleaning process
        $this->startClean($this->xmlDocument->childNodes, $elementsToRemove);

        // Save cleaned XML to a variable
        if ($this->removeXMLTag) {
            $clean = $this->xmlDocument->saveXML($this->xmlDocument->documentElement, $this->xmlOptions);
        } else {
            $clean = $this->xmlDocument->saveXML($this->xmlDocument, $this->xmlOptions);
        }

        $this->resetAfter();

        // Remove any extra whitespaces when minifying
        if ($this->minifyXML) {
            $clean = preg_replace('/\s+/', ' ', $clean);
        }

        // Return result
        return $clean;
    }

    /**
     * Set up libXML before we start
     *
     * @todo libxml_use_internal_errors(true) is never undone: after
     *       sanitize() the setting stays enabled for the rest of the request
     *       and the collected errors are never cleared. Suggested fix:
     *       remember the previous value here and restore it, after
     *       libxml_clear_errors(), in resetAfter().
     */
    protected function setUpBefore()
    {
        // This function has been deprecated in PHP 8.0 because in libxml 2.9.0, external entity loading is
        // disabled by default, so this function is no longer needed to protect against XXE attacks.
        if (\LIBXML_VERSION < 20900) {
            // Turn off the entity loader
            $this->xmlLoaderValue = libxml_disable_entity_loader(true);
        }

        // Suppress the errors because we don't really have to worry about formation before cleansing
        libxml_use_internal_errors(true);

        // Reset array of altered XML
        $this->xmlIssues = array();
    }

    /**
     * Reset the class after use
     */
    protected function resetAfter()
    {
        // This function has been deprecated in PHP 8.0 because in libxml 2.9.0, external entity loading is
        // disabled by default, so this function is no longer needed to protect against XXE attacks.
        if (\LIBXML_VERSION < 20900) {
            // Reset the entity loader
            libxml_disable_entity_loader($this->xmlLoaderValue);
        }
    }

    /**
     * Start the cleaning with tags, then we move onto attributes and hrefs later
     *
     * @param \DOMNodeList $elements
     * @param array        $elementsToRemove
     *
     * @todo The in_array() check against $elementsToRemove is not strict, and
     *       any two DOMElement objects compare equal, so as soon as the list
     *       has one entry every `<use>` in the document is removed, valid
     *       ones included. A strict check alone would remove nothing: the
     *       list holds the referenced elements, which are rarely `<use>`
     *       elements themselves. Suggested fix: have the Resolver list the
     *       `<use>` elements that take part in a loop or exceed the nesting
     *       limit, then compare strictly here.
     */
    protected function startClean(\DOMNodeList $elements, array $elementsToRemove)
    {
        // loop through all elements
        // we do this backwards so we don't skip anything if we delete a node
        // see comments at: http://php.net/manual/en/class.domnamednodemap.php
        for ($i = $elements->length - 1; $i >= 0; $i--) {
            /** @var \DOMElement $currentElement */
            $currentElement = $elements->item($i);

            /**
             * If the element has exceeded the nesting limit, we should remove it.
             *
             * As it's only <use> elements that cause us issues with nesting DOS attacks
             * we should check what the element is before removing it. For now we'll only
             * remove <use> elements.
             */
            if (in_array($currentElement, $elementsToRemove) && 'use' === $currentElement->nodeName) {
                $currentElement->parentNode->removeChild($currentElement);
                $this->xmlIssues[] = array(
                    'message' => 'Invalid \'' . $currentElement->tagName . '\'',
                    'line'    => $currentElement->getLineNo(),
                );
                continue;
            }

            if ($currentElement instanceof \DOMElement) {
                // If the tag isn't in the whitelist, remove it and continue with next iteration
                if (!in_array(strtolower($currentElement->tagName), $this->allowedTags)) {
                    $currentElement->parentNode->removeChild($currentElement);
                    $this->xmlIssues[] = array(
                        'message' => 'Suspicious tag \'' . $currentElement->tagName . '\'',
                        'line' => $currentElement->getLineNo(),
                    );
                    continue;
                }

                $this->cleanHrefs( $currentElement );

                $this->cleanXlinkHrefs( $currentElement );

                $this->cleanAttributesOnWhitelist($currentElement);

                if (strtolower($currentElement->tagName) === 'use') {
                    if ($this->isUseTagDirty($currentElement)
                        || $this->isUseTagExceedingThreshold($currentElement)
                    ) {
                        $currentElement->parentNode->removeChild($currentElement);
                        $this->xmlIssues[] = array(
                            'message' => 'Suspicious \'' . $currentElement->tagName . '\'',
                            'line' => $currentElement->getLineNo(),
                        );
                        continue;
                    }
                }

                // Strip out font elements that will break out of foreign content.
                if (strtolower($currentElement->tagName) === 'font') {
                    $breaksOutOfForeignContent = false;
                    for ($x = $currentElement->attributes->length - 1; $x >= 0; $x--) {
                        // get attribute name
                        $attrName = $currentElement->attributes->item( $x )->name;

                        if (in_array(strtolower($attrName), ['face', 'color', 'size'])) {
                            $breaksOutOfForeignContent = true;
                        }
                    }

                    if ($breaksOutOfForeignContent) {
                        $currentElement->parentNode->removeChild($currentElement);
                        $this->xmlIssues[] = array(
                            'message' => 'Suspicious tag \'' . $currentElement->tagName . '\'',
                            'line' => $currentElement->getLineNo(),
                        );
                        continue;
                    }
                }
            }

            $this->cleanUnsafeNodes($currentElement);

            if ($currentElement->hasChildNodes()) {
                $this->startClean($currentElement->childNodes, $elementsToRemove);
            }
        }
    }

    /**
     * Only allow attributes that are on the whitelist
     *
     * @param \DOMElement $element
     *
     * @todo Attributes are read and removed by local name without their
     *       namespace, which misses every attribute that has a namespace.
     *       A prefixed attribute that is not on the whitelist
     *       (`xlink:actuate`, `x:onload`) is reported in getXmlIssues() but
     *       stays in the output, and `xlink:href="javascript:..."` is kept
     *       untouched when the `xlink` prefix is bound to a namespace other
     *       than XLink. An HTML parser treats that attribute as a real XLink
     *       href when the SVG is inlined in a page. Suggested fix: use the
     *       attribute node itself (nodeName, value, removeAttributeNode()).
     */
    protected function cleanAttributesOnWhitelist(\DOMElement $element)
    {
        for ($x = $element->attributes->length - 1; $x >= 0; $x--) {
            // get attribute name
            $attrName = $element->attributes->item($x)->name;

            // Remove attribute if not in whitelist
            if (!in_array(strtolower($attrName), $this->allowedAttrs) && !$this->isAriaAttribute(strtolower($attrName)) && !$this->isDataAttribute(strtolower($attrName))) {

                $element->removeAttribute($attrName);
                $this->xmlIssues[] = array(
                    'message' => 'Suspicious attribute \'' . $attrName . '\'',
                    'line' => $element->getLineNo(),
                );
            }

            /**
             * This is used for when a namespace isn't imported properly.
             * Such as xlink:href when the xlink namespace isn't imported.
             * We have to do this as the link is still ran in this case.
             */
            if (false !== strpos($attrName, 'href')) {
                $href = $element->getAttribute($attrName);
                if (false === $this->isHrefSafeValue($href)) {
                    $element->removeAttribute($attrName);
                    $this->xmlIssues[] = array(
                        'message' => 'Suspicious attribute \'href\'',
                        'line'    => $element->getLineNo(),
                    );
                }
            }

            // Do we want to strip remote references?
            if($this->removeRemoteReferences) {
                // Remove attribute if it has a remote reference
                if (isset($element->attributes->item($x)->value) && $this->hasRemoteReference($element->attributes->item($x)->value)) {
                    $element->removeAttribute($attrName);
                    $this->xmlIssues[] = array(
                        'message' => 'Suspicious attribute \'' . $attrName . '\'',
                        'line' => $element->getLineNo(),
                    );
                }
            }
        }
    }

    /**
     * Clean the xlink:hrefs of script and data embeds
     *
     * @param \DOMElement $element
     */
    protected function cleanXlinkHrefs(\DOMElement $element)
    {
        $xlinks = $element->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
        if (false === $this->isHrefSafeValue($xlinks)) {
            $element->removeAttributeNS( 'http://www.w3.org/1999/xlink', 'href' );
            $this->xmlIssues[] = array(
                'message' => 'Suspicious attribute \'href\'',
                'line' => $element->getLineNo(),
            );
        }
    }

    /**
     * Clean the hrefs of script and data embeds
     *
     * @param \DOMElement $element
     */
    protected function cleanHrefs(\DOMElement $element)
    {
        $href = $element->getAttribute('href');
        if (false === $this->isHrefSafeValue($href)) {
            $element->removeAttribute('href');
            $this->xmlIssues[] = array(
                'message' => 'Suspicious attribute \'href\'',
                'line' => $element->getLineNo(),
            );
        }
    }

    /**
     * Only allow whitelisted starts to be within the href.
     *
     * This will stop scripts etc from being passed through, with or without attempting to hide bypasses.
     * This stops the need for us to use a complicated script regex.
     *
     * @param $value
     * @return bool
     */
    protected function isHrefSafeValue($value) {

        // Allow empty values
        if (empty($value)) {
            return true;
        }

        // Allow fragment identifiers.
        if ('#' === substr($value, 0, 1)) {
            return true;
        }

        // Allow relative URIs.
        if ('/' === substr($value, 0, 1)) {
            return true;
        }

        // Allow HTTPS domains.
        if ('https://' === substr($value, 0, 8)) {
            return true;
        }

        // Allow HTTP domains.
        if ('http://' === substr($value, 0, 7)) {
            return true;
        }

        // Allow known data URIs.
        if (in_array(substr($value, 0, 14), array(
            'data:image/png', // PNG
            'data:image/gif', // GIF
            'data:image/jpg', // JPG
            'data:image/jpe', // JPEG
            'data:image/pjp', // PJPEG
        ))) {
            return true;
        }

        // Allow known short data URIs.
        if (in_array(substr($value, 0, 12), array(
            'data:img/png', // PNG
            'data:img/gif', // GIF
            'data:img/jpg', // JPG
            'data:img/jpe', // JPEG
            'data:img/pjp', // PJPEG
        ))) {
            return true;
        }

        return false;
    }

    /**
     * Removes non-printable ASCII characters from string & trims it
     *
     * @param string $value
     * @return string
     */
    protected function removeNonPrintableCharacters($value)
    {
        return trim(preg_replace('/[^ -~]/xu','',$value));
    }

    /**
     * Does this attribute value have a remote reference?
     *
     * Only a value that is a single `url(...)` is examined.
     *
     * @param $value
     * @return bool
     *
     * @todo The pattern requires quotes inside `url()`, so the unquoted form
     *       `url(http://example.com/x)` is not recognised as remote.
     *       Suggested fix: make the quotes optional.
     */
    protected function hasRemoteReference($value)
    {
        $value = $this->removeNonPrintableCharacters($value);

        $wrapped_in_url = preg_match('~^url\(\s*[\'"]\s*(.*)\s*[\'"]\s*\)$~xi', $value, $match);
        if (!$wrapped_in_url){
            return false;
        }

        $value = trim($match[1], '\'"');

        return preg_match('~^((https?|ftp|file):)?//~xi', $value);
    }

    /**
     * Should we minify the output?
     *
     * @param bool $shouldMinify
     */
    public function minify($shouldMinify = false)
    {
        $this->minifyXML = (bool) $shouldMinify;
    }

    /**
     * Should we remove the XML tag in the header?
     *
     * @param bool $removeXMLTag
     */
    public function removeXMLTag($removeXMLTag = false)
    {
        $this->removeXMLTag = (bool) $removeXMLTag;
    }

    /**
     * Whether `<use ... xlink:href="#identifier">` elements shall be
     * removed in case expansion would exceed this threshold.
     *
     * @param int $useThreshold
     */
    public function useThreshold($useThreshold = 1000)
    {
        $this->useThreshold = (int)$useThreshold;
    }

    /**
     * Check to see if an attribute is an aria attribute or not
     *
     * @param $attributeName
     *
     * @return bool
     */
    protected function isAriaAttribute($attributeName)
    {
        return strpos($attributeName, 'aria-') === 0;
    }

    /**
     * Check to see if an attribute is an data attribute or not
     *
     * @param $attributeName
     *
     * @return bool
     */
    protected function isDataAttribute($attributeName)
    {
        return strpos($attributeName, 'data-') === 0;
    }

    /**
     * Make sure our use tag is only referencing internal resources
     *
     * @param \DOMElement $element
     * @return bool
     */
    protected function isUseTagDirty(\DOMElement $element)
    {
        $href = Helper::getElementHref($element);
        return $href && strpos($href, '#') !== 0;
    }

    /**
     * Determines whether `<use ... xlink:href="#identifier">` is expanded
     * recursively in order to create DoS scenarios. The amount of a actually
     * used element needs to be below `$this->useThreshold`.
     *
     * @param \DOMElement $element
     * @return bool
     */
    protected function isUseTagExceedingThreshold(\DOMElement $element)
    {
        if ($this->useThreshold <= 0) {
            return false;
        }
        $useId = Helper::extractIdReferenceFromHref(
            Helper::getElementHref($element)
        );
        if ($useId === null) {
            return false;
        }
        foreach ($this->elementReferenceResolver->findByElementId($useId) as $subject) {
            if ($subject->countUse() >= $this->useThreshold) {
                return true;
            }
        }
        return false;
    }

    /**
     * Set the nesting limit for <use> tags (default 15). Takes effect on the
     * next sanitize() call.
     *
     * @param int $limit
     */
    public function setUseNestingLimit($limit)
    {
        $this->useNestingLimit = (int) $limit;
    }

    /**
     * Remove nodes that are either invalid or malformed.
     *
     * A CDATA section is replaced with a text node holding what HTMLPurifier
     * makes of its content read as HTML: markup that is not allowed in HTML
     * is dropped and `<`, `>` and `&` come back as entities.
     *
     * @todo The purified text is HTML-encoded and then stored as a text
     *       node, which encodes it once more on output. A `<style>` rule
     *       such as `a > b {}` becomes `a &gt; b {}` for the browser and no
     *       longer applies, and `a < b` in a `<text>` element is displayed as
     *       `a &lt; b`. Suggested fix: decode the purified text before
     *       creating the text node, or keep CDATA content as plain text
     *       without HTMLPurifier.
     *
     * @param \DOMNode $currentElement The current element.
     */
    protected function cleanUnsafeNodes(\DOMNode $currentElement) {
        // Replace CDATA node with encoded text node
        if ($currentElement instanceof \DOMCdataSection) {
            $purifier = new HTMLPurifier(HTMLPurifier_Config::createDefault());
            $clean_html = $purifier->purify($currentElement->nodeValue);
            $textNode = $currentElement->ownerDocument->createTextNode($clean_html);
            $currentElement->parentNode->replaceChild($textNode, $currentElement);
        // If the element doesn't have a tagname, remove it and continue with next iteration
        } elseif (!$currentElement instanceof \DOMElement && !$currentElement instanceof \DOMText) {
            $currentElement->parentNode->removeChild($currentElement);
            $this->xmlIssues[] = array(
                'message' => 'Suspicious node \'' . $currentElement->nodeName . '\'',
                'line' => $currentElement->getLineNo(),
            );
            return;
        }

        if ( $currentElement->childNodes && $currentElement->childNodes->length > 0 ) {
            for ($j = $currentElement->childNodes->length - 1; $j >= 0; $j--) {
                /** @var \DOMElement $childElement */
                $childElement = $currentElement->childNodes->item($j);
                $this->cleanUnsafeNodes($childElement);
            }
        }
    }
}
