<?php
namespace enshrined\svgSanitize\ElementReference;

use enshrined\svgSanitize\data\XPath;
use enshrined\svgSanitize\Exceptions\NestingException;
use enshrined\svgSanitize\Helper;

/**
 * Builds the graph of `id`-bearing elements and the `<use>` references between
 * them, and lists the elements that must be removed because they take part in
 * a reference loop or exceed the nesting limit.
 *
 * @package enshrined\svgSanitize\ElementReference
 */
class Resolver
{
    /**
     * @var XPath
     */
    protected $xPath;

    /**
     * @var Subject[]
     */
    protected $subjects = [];

    /**
     * @var array DOMElement[]
     */
    protected $elementsToRemove = [];

    /**
     * @var int
     */
    protected $useNestingLimit;

    /**
     * @param XPath $xPath           Query object for the document to analyse.
     * @param int   $useNestingLimit Maximum `<use>` nesting depth before an
     *                               element is marked for removal.
     */
    public function __construct(XPath $xPath, $useNestingLimit)
    {
        $this->xPath = $xPath;
        $this->useNestingLimit = $useNestingLimit;
    }

    /**
     * Analyses the document: collects identified elements, records `<use>`
     * references between them and fills the removal list. Call once per
     * instance: the removal list is never reset.
     *
     * @throws \LogicException See the first todo on processReferences().
     */
    public function collect()
    {
        $this->collectIdentifiedElements();
        $this->processReferences();
        $this->determineInvalidSubjects();
    }

    /**
     * Resolves one subject by element.
     *
     * @param \DOMElement $element
     * @param bool $considerChildren Whether to search in Subject's children as well
     * @return Subject|null
     */
    public function findByElement(\DOMElement $element, $considerChildren = false)
    {
        foreach ($this->subjects as $subject) {
            if (
                $element === $subject->getElement()
                || $considerChildren && Helper::isElementContainedIn($element, $subject->getElement())
            ) {
                return $subject;
            }
        }
        return null;
    }

    /**
     * Resolves subjects (plural!) by element id - in theory malformed
     * DOM might have same ids assigned to different elements and leaving
     * it to client/browser implementation which element to actually use.
     *
     * Subjects are stored by id, so when several elements share an id only
     * the last one in document order is kept and at most one subject is
     * returned, keyed by its id.
     *
     * @param string $elementId
     * @return Subject[]
     */
    public function findByElementId($elementId)
    {
        return array_filter(
            $this->subjects,
            function (Subject $subject) use ($elementId) {
                return $elementId === $subject->getElementId();
            }
        );
    }

    /**
     * Collects elements having `id` attribute (those that can be referenced).
     */
    protected function collectIdentifiedElements()
    {
        /** @var \DOMNodeList|\DOMElement[] $elements */
        $elements = $this->xPath->query('//*[@id]');
        foreach ($elements as $element) {
            $this->subjects[$element->getAttribute('id')] = new Subject($element, $this->useNestingLimit);
        }
    }

    /**
     * Processes references from and to elements having `id` attribute concerning
     * their occurrence in `<use ... xlink:href="#identifier">` statements.
     *
     * Only `<use>` elements that are direct children of an identified element
     * are recorded, and only when they are in the document's default
     * namespace. A `<use>` nested deeper, or one whose parent has no `id`, is
     * not a reference for this graph.
     *
     * @todo A `<use>` that references its own parent
     *       (`<g id="a"><use href="#a"/></g>`) makes Subject::addUse() throw a
     *       LogicException that nothing catches, so collect() and
     *       Sanitizer::sanitize() end with an uncaught exception. Suggested
     *       fix: mark the subject as invalid instead of calling addUse().
     * @todo The query uses the `xlink` prefix without registering it. When a
     *       child `<use>` has no plain `href` and the prefix is not declared
     *       on the identified element or its ancestors, the query fails with
     *       PHP warnings and none of that element's references are recorded.
     *       Suggested fix: register the XLink namespace under `xlink` in
     *       XPath::__construct().
     */
    protected function processReferences()
    {
        $useNodeName = $this->xPath->createNodeName('use');
        foreach ($this->subjects as $subject) {
            $useElements = $this->xPath->query(
                $useNodeName . '[@href or @xlink:href]',
                $subject->getElement()
            );

            /** @var \DOMElement $useElement */
            foreach ($useElements as $useElement) {
                $useId = Helper::extractIdReferenceFromHref(
                    Helper::getElementHref($useElement)
                );
                if ($useId === null || !isset($this->subjects[$useId])) {
                    continue;
                }
                $subject->addUse($this->subjects[$useId]);
                $this->subjects[$useId]->addUsedIn($subject);
            }
        }
    }

    /**
     * Determines and tags infinite loops.
     *
     * For a subject with a loop, the elements it references are added to the
     * removal list under their id and its own references are cleared. When
     * the nesting limit is hit, the element where it was hit is added as well.
     * A `<use id="a" href="#a"/>` adds nothing: it has no references to list.
     *
     * @todo The in_array() check is not strict, and any two DOMElement
     *       objects compare equal, so once the removal list has one entry
     *       every remaining subject is skipped and its loops stay undetected.
     *       Suggested fix: pass `true` as the third argument.
     */
    protected function determineInvalidSubjects()
    {
        foreach ($this->subjects as $subject) {

            if (in_array($subject->getElement(), $this->elementsToRemove)) {
                continue;
            }

            $useId = Helper::extractIdReferenceFromHref(
                Helper::getElementHref($subject->getElement())
            );

            try {
                if ($useId === $subject->getElementId()) {
                    $this->markSubjectAsInvalid($subject);
                } elseif ($subject->hasInfiniteLoop()) {
                    $this->markSubjectAsInvalid($subject);
                }
            } catch (NestingException $e) {
                $this->elementsToRemove[] = $e->getElement();
                $this->markSubjectAsInvalid($subject);
            }
        }
    }

    /**
     * Get all the elements that caused a nesting exception.
     *
     * @return array
     */
    public function getElementsToRemove() {
        return $this->elementsToRemove;
    }

    /**
     * The Subject is invalid for some reason, therefore we should
     * remove it and all it's child usages.
     *
     * @param Subject $subject
     */
    protected function markSubjectAsInvalid(Subject $subject) {
        $this->elementsToRemove = array_merge(
            $this->elementsToRemove,
            $subject->clearInternalAndGetAffectedElements()
        );
    }
}