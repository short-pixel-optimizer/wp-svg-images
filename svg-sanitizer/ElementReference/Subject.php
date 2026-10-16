<?php
namespace enshrined\svgSanitize\ElementReference;

/**
 * An element with an `id`, together with the elements it references through
 * `<use>` and the elements that reference it.
 *
 * @package enshrined\svgSanitize\ElementReference
 */
class Subject
{
    /**
     * @var \DOMElement
     */
    protected $element;

    /**
     * @var Usage[]
     */
    protected $useCollection = [];

    /**
     * @var Usage[]
     */
    protected $usedInCollection = [];

    /**
     * @var int
     */
    protected $useNestingLimit;

    /**
     * Subject constructor.
     *
     * @param \DOMElement $element
     * @param int         $useNestingLimit
     */
    public function __construct(\DOMElement $element, $useNestingLimit)
    {
        $this->element = $element;
        $this->useNestingLimit = $useNestingLimit;
    }

    /**
     * Returns the wrapped element.
     *
     * @return \DOMElement
     */
    public function getElement()
    {
        return $this->element;
    }

    /**
     * Returns the element's `id` attribute.
     *
     * @return string
     */
    public function getElementId()
    {
        return $this->element->getAttribute('id');
    }

    /**
     * Tells whether following this subject's `<use>` references leads back to
     * a subject already on the current path.
     *
     * @param array $subjects   Previously processed subjects
     * @param int   $level      The current level of nesting.
     * @return bool
     * @throws \enshrined\svgSanitize\Exceptions\NestingException
     */
    public function hasInfiniteLoop(array $subjects = [], $level = 1)
    {
        if ($level > $this->useNestingLimit) {
            throw new \enshrined\svgSanitize\Exceptions\NestingException('Nesting level too high, aborting', 1570713498, null, $this->getElement());
        }

        if (in_array($this, $subjects, true)) {
            return true;
        }
        $subjects[] = $this;
        foreach ($this->useCollection as $usage) {
            if ($usage->getSubject()->hasInfiniteLoop($subjects, $level + 1)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Records that this subject references $subject; repeated references
     * increment the count.
     *
     * @param Subject $subject
     * @throws \LogicException When $subject is this subject (code 1570713416).
     */
    public function addUse(Subject $subject)
    {
        if ($subject === $this) {
            throw new \LogicException('Cannot add self usage', 1570713416);
        }
        $identifier = $subject->getElementId();
        if (isset($this->useCollection[$identifier])) {
            $this->useCollection[$identifier]->increment();
            return;
        }
        $this->useCollection[$identifier] = new Usage($subject);
    }

    /**
     * Records that $subject references this subject; repeated references
     * increment the count.
     *
     * @param Subject $subject
     * @throws \LogicException When $subject is this subject (code 1570713417).
     */
    public function addUsedIn(Subject $subject)
    {
        if ($subject === $this) {
            throw new \LogicException('Cannot add self as usage', 1570713417);
        }
        $identifier = $subject->getElementId();
        if (isset($this->usedInCollection[$identifier])) {
            $this->usedInCollection[$identifier]->increment();
            return;
        }
        $this->usedInCollection[$identifier] = new Usage($subject);
    }

    /**
     * Counts the element instances this subject expands to through `<use>`,
     * recursively. Each reference counts its multiplicity times the
     * referenced subject's own count (at least 1); with $accumulated the
     * referenced subject itself is added to its count instead.
     *
     * @param bool $accumulated
     * @return int
     */
    public function countUse($accumulated = false)
    {
        $count = 0;
        foreach ($this->useCollection as $use) {
            $useCount = $use->getSubject()->countUse();
            $count += $use->getCount() * ($accumulated ? 1 + $useCount : max(1, $useCount));
        }
        return $count;
    }

    /**
     * Counts, recursively, how many times this subject is referenced.
     *
     * @return int
     */
    public function countUsedIn()
    {
        $count = 0;
        foreach ($this->usedInCollection as $usedIn) {
            $count += $usedIn->getCount() * max(1, $usedIn->getSubject()->countUsedIn());
        }
        return $count;
    }

    /**
     * Clear the internal arrays (to free up memory as they can get big)
     * and return all the child usages DOMElement's
     *
     * @return array
     */
    public function clearInternalAndGetAffectedElements()
    {
        $elements = array_map(function(Usage $usage) {
            return $usage->getSubject()->getElement();
        }, $this->useCollection);

        $this->usedInCollection = [];
        $this->useCollection = [];

        return $elements;
    }
}