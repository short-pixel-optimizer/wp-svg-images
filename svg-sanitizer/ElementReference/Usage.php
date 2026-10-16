<?php
namespace enshrined\svgSanitize\ElementReference;

/**
 * A reference to a Subject together with how many times it occurs.
 *
 * @package enshrined\svgSanitize\ElementReference
 */
class Usage
{
    /**
     * @var Subject
     */
    protected $subject;

    /**
     * @var int
     */
    protected $count;

    /**
     * @param Subject $subject
     * @param int $count
     */
    public function __construct(Subject $subject, $count = 1)
    {
        $this->subject = $subject;
        $this->count = (int)$count;
    }

    /**
     * Adds $by (cast to int) to the count.
     *
     * @param int $by
     */
    public function increment($by = 1)
    {
        $this->count += (int)$by;
    }

    /**
     * Returns the referenced subject.
     *
     * @return Subject
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * Returns how many times the subject is referenced.
     *
     * @return int
     */
    public function getCount()
    {
        return $this->count;
    }
}