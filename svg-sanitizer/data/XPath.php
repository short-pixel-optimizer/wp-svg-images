<?php
namespace enshrined\svgSanitize\data;

/**
 * DOMXPath that registers the document's default namespace under the `svg`
 * prefix, so queries work whether or not the SVG declares a namespace.
 *
 * @package enshrined\svgSanitize\data
 */
class XPath extends \DOMXPath
{
    const DEFAULT_NAMESPACE_PREFIX = 'svg';

    /**
     * @var string
     */
    protected $defaultNamespaceURI;

    /**
     * @param \DOMDocument $doc Parsed document.
     * @throws \LogicException When the document does not have exactly one
     *                         `<svg>` element as its root (code 1570870568),
     *                         for example any XML whose root is not `<svg>`
     *                         or whose root is a prefixed `<x:svg>`.
     */
    public function __construct(\DOMDocument $doc)
    {
        parent::__construct($doc);
        $this->handleDefaultNamespace();
    }

    /**
     * Returns the node name to use in a query: `svg:<name>` when the document
     * has a default namespace, the bare name otherwise.
     *
     * @param string $nodeName
     * @return string
     */
    public function createNodeName($nodeName)
    {
        if (empty($this->defaultNamespaceURI)) {
            return $nodeName;
        }
        return self::DEFAULT_NAMESPACE_PREFIX . ':' . $nodeName;
    }

    /**
     * Records the root `<svg>` element's namespace and registers it under
     * DEFAULT_NAMESPACE_PREFIX when it is not empty.
     *
     * @throws \LogicException When there is not exactly one root `<svg>`.
     */
    protected function handleDefaultNamespace()
    {
        $rootElements = $this->getRootElements();

        if (count($rootElements) !== 1) {
            throw new \LogicException(
                sprintf('Got %d svg elements, expected exactly one', count($rootElements)),
                1570870568
            );
        }
        $this->defaultNamespaceURI = (string)$rootElements[0]->namespaceURI;

        if ($this->defaultNamespaceURI !== '') {
            $this->registerNamespace(self::DEFAULT_NAMESPACE_PREFIX, $this->defaultNamespaceURI);
        }
    }

    /**
     * Returns the `<svg>` elements that are direct children of the document.
     *
     * @return \DOMElement[]
     */
    protected function getRootElements()
    {
        $rootElements = [];
        $elements = $this->document->getElementsByTagName('svg');
        /** @var \DOMElement $element */
        foreach ($elements as $element) {
            if ($element->parentNode !== $this->document) {
                continue;
            }
            $rootElements[] = $element;
        }
        return $rootElements;
    }
}
