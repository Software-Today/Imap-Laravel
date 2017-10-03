<?php

declare(strict_types=1);

namespace Ddeboer\Imap\Message;

use Ddeboer\Imap\Exception\UnexpectedEncodingException;
use Ddeboer\Imap\ImapResourceInterface;

/**
 * A message part.
 */
abstract class AbstractPart implements PartInterface
{
    private $typesMap = [
        \TYPETEXT => self::TYPE_TEXT,
        \TYPEMULTIPART => self::TYPE_MULTIPART,
        \TYPEMESSAGE => self::TYPE_MESSAGE,
        \TYPEAPPLICATION => self::TYPE_APPLICATION,
        \TYPEAUDIO => self::TYPE_AUDIO,
        \TYPEIMAGE => self::TYPE_IMAGE,
        \TYPEVIDEO => self::TYPE_VIDEO,
        \TYPEMODEL => self::TYPE_MODEL,
        \TYPEOTHER => self::TYPE_OTHER,
    ];

    private $encodingsMap = [
        \ENC7BIT => self::ENCODING_7BIT,
        \ENC8BIT => self::ENCODING_8BIT,
        \ENCBINARY => self::ENCODING_BINARY,
        \ENCBASE64 => self::ENCODING_BASE64,
        \ENCQUOTEDPRINTABLE => self::ENCODING_QUOTED_PRINTABLE,
    ];

    protected $type;

    protected $subtype;

    protected $encoding;

    protected $bytes;

    protected $lines;

    /**
     * @var Parameters
     */
    protected $parameters;

    /**
     * @var ImapResourceInterface
     */
    protected $resource;

    protected $messageNumber;

    protected $partNumber;

    protected $structure;

    protected $content;

    protected $decodedContent;

    protected $parts = [];

    protected $key = 0;

    protected $disposition;

    /**
     * Constructor.
     *
     * @param ImapResourceInterface $resource      IMAP resource
     * @param int                   $messageNumber Message number
     * @param int                   $partNumber    Part number (optional)
     * @param \stdClass             $structure     Part structure
     */
    public function __construct(
        ImapResourceInterface $resource,
        int $messageNumber,
        string $partNumber = null,
        \stdClass $structure = null
    ) {
        $this->resource = $resource;
        $this->messageNumber = $messageNumber;
        $this->partNumber = $partNumber;
        $this->structure = $structure;
        $this->parseStructure($structure);
    }

    /**
     * Get message number (from headers).
     *
     * @return int
     */
    final public function getNumber(): int
    {
        return $this->messageNumber;
    }

    final public function getCharset(): string
    {
        return $this->parameters->get('charset');
    }

    final public function getType()
    {
        return $this->type;
    }

    final public function getSubtype()
    {
        return $this->subtype;
    }

    final public function getEncoding()
    {
        return $this->encoding;
    }

    final public function getBytes()
    {
        return $this->bytes;
    }

    final public function getLines()
    {
        return $this->lines;
    }

    final public function getParameters(): Parameters
    {
        return $this->parameters;
    }

    /**
     * Get raw part content.
     *
     * @return string
     */
    public function getContent(): string
    {
        if (null === $this->content) {
            $this->content = $this->doGetContent();
        }

        return $this->content;
    }

    /**
     * Get decoded part content.
     *
     * @return string
     */
    final public function getDecodedContent(): string
    {
        if (null === $this->decodedContent) {
            $content = $this->getContent();
            if (self::ENCODING_BASE64 === $this->getEncoding()) {
                $content = \base64_decode($content);
            } elseif (self::ENCODING_QUOTED_PRINTABLE === $this->getEncoding()) {
                $content = \quoted_printable_decode($content);
            }

            // If this part is a text part, try to convert its encoding to UTF-8.
            // We don't want to convert an attachment's encoding.
            if (self::TYPE_TEXT === $this->getType()) {
                $content = Transcoder::decode($content, $this->getCharset());
            }

            $this->decodedContent = $content;
        }

        return $this->decodedContent;
    }

    final public function getStructure(): \stdClass
    {
        return $this->structure;
    }

    final protected function parseStructure(\stdClass $structure)
    {
        $this->type = $this->typesMap[$structure->type] ?? self::TYPE_UNKNOWN;

        if (!isset($this->encodingsMap[$structure->encoding])) {
            throw new UnexpectedEncodingException(\sprintf('Cannot decode "%s"', $structure->encoding));
        }

        $this->encoding = $this->encodingsMap[$structure->encoding];
        $this->subtype = $structure->subtype;

        foreach (['disposition', 'bytes', 'description'] as $optional) {
            if (isset($structure->{$optional})) {
                $this->{$optional} = $structure->{$optional};
            }
        }

        $this->parameters = new Parameters();
        if ($structure->ifparameters) {
            $this->parameters->add($structure->parameters);
        }

        if ($structure->ifdparameters) {
            $this->parameters->add($structure->dparameters);
        }

        // When the message is not multipart and the body is the attachment content
        // Prevents infinite recursion
        if ($this->isAttachment($structure) && !$this instanceof Attachment) {
            $this->parts[] = new Attachment($this->resource, $this->messageNumber, '1', $structure);
        }

        if (isset($structure->parts)) {
            foreach ($structure->parts as $key => $partStructure) {
                $partNumber = isset($this->partNumber) ? $this->partNumber . '.' : '';
                $partNumber .= (string) ($key + 1);

                $newPartClass = $this->isAttachment($partStructure)
                    ? Attachment::class
                    : SimplePart::class
                ;

                $this->parts[] = new $newPartClass($this->resource, $this->messageNumber, $partNumber, $partStructure);
            }
        }
    }

    /**
     * Get an array of all parts for this message.
     *
     * @return PartInterface[]
     */
    final public function getParts(): array
    {
        return $this->parts;
    }

    final public function current()
    {
        return $this->parts[$this->key];
    }

    final public function getChildren()
    {
        return $this->current();
    }

    final public function hasChildren()
    {
        return \count($this->parts) > 0;
    }

    final public function key()
    {
        return $this->key;
    }

    final public function next()
    {
        ++$this->key;
    }

    final public function rewind()
    {
        $this->key = 0;
    }

    final public function valid()
    {
        return isset($this->parts[$this->key]);
    }

    final public function getDisposition()
    {
        return $this->disposition;
    }

    /**
     * Get raw message content.
     *
     * @return string
     */
    final protected function doGetContent(): string
    {
        return \imap_fetchbody(
            $this->resource->getStream(),
            $this->messageNumber,
            (string) ($this->partNumber ?: '1'),
            \FT_UID | \FT_PEEK
        );
    }

    private function isAttachment(\stdClass $part)
    {
        // Attachment with correct Content-Disposition header
        if (isset($part->disposition)) {
            if (
                    ('attachment' === \strtolower($part->disposition) || 'inline' === \strtolower($part->disposition))
                && self::SUBTYPE_PLAIN !== \strtoupper($part->subtype)
                && self::SUBTYPE_HTML !== \strtoupper($part->subtype)
            ) {
                return true;
            }
        }

        // Attachment without Content-Disposition header
        if (isset($part->parameters)) {
            foreach ($part->parameters as $parameter) {
                if ('name' === \strtolower($parameter->attribute) || 'filename' === \strtolower($parameter->attribute)) {
                    return true;
                }
            }
        }

        /*
        if (isset($part->dparameters)) {
            foreach ($part->dparameters as $parameter) {
                if ('name' === strtolower($parameter->attribute) || 'filename' === strtolower($parameter->attribute)) {
                    return true;
                }
            }
        }
        */

        return false;
    }
}
