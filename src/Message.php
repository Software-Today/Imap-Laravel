<?php

declare(strict_types=1);

namespace Ddeboer\Imap;

use Ddeboer\Imap\Exception\MessageDeleteException;
use Ddeboer\Imap\Exception\MessageDoesNotExistException;
use Ddeboer\Imap\Exception\MessageMoveException;
use Ddeboer\Imap\Exception\MessageStructureException;

/**
 * An IMAP message (e-mail).
 */
final class Message extends Message\AbstractMessage implements MessageInterface
{
    /**
     * @var null|Message\Headers
     */
    private $headers;

    /**
     * @var null|string
     */
    private $rawHeaders;

    /**
     * Constructor.
     *
     * @param ImapResourceInterface $resource      IMAP resource
     * @param int                   $messageNumber Message number
     */
    public function __construct(ImapResourceInterface $resource, int $messageNumber)
    {
        $structure = $this->loadStructure($resource, $messageNumber);
        parent::__construct($resource, $messageNumber, '1', $structure);
    }

    /**
     * Load message structure.
     *
     * @param ImapResourceInterface $resource
     * @param int                   $messageNumber
     */
    private function loadStructure(ImapResourceInterface $resource, int $messageNumber): \stdClass
    {
        \set_error_handler(function ($nr, $error) use ($messageNumber) {
            throw new MessageDoesNotExistException(\sprintf('Message "%s" does not exist: %s', $messageNumber, $error), $nr);
        });

        $structure = \imap_fetchstructure(
            $resource->getStream(),
            $messageNumber,
            \FT_UID
        );

        \restore_error_handler();

        if (!$structure instanceof \stdClass) {
            throw new MessageStructureException(\sprintf('Message "%s" structure is empty', $messageNumber));
        }

        return $structure;
    }

    /**
     * Get raw message headers.
     *
     * @return string
     */
    public function getRawHeaders(): string
    {
        if (null === $this->rawHeaders) {
            $this->rawHeaders = \imap_fetchheader($this->resource->getStream(), $this->messageNumber, \FT_UID);
        }

        return $this->rawHeaders;
    }

    /**
     * Get the raw message, including all headers, parts, etc. unencoded and unparsed.
     *
     * @return string the raw message
     */
    public function getRawMessage(): string
    {
        return $this->doGetContent('');
    }

    /**
     * Get message headers.
     *
     * @return Message\Headers
     */
    public function getHeaders(): Message\Headers
    {
        if (null === $this->headers) {
            // imap_headerinfo is much faster than imap_fetchheader
            // imap_headerinfo returns only a subset of all mail headers,
            // but it does include the message flags.
            $headers = \imap_headerinfo($this->resource->getStream(), \imap_msgno($this->resource->getStream(), $this->messageNumber));
            $this->headers = new Message\Headers($headers);
        }

        return $this->headers;
    }

    /**
     * Clearmessage headers.
     */
    private function clearHeaders()
    {
        $this->headers = null;
    }

    /**
     * Get message recent flag value (from headers).
     *
     * @return string
     */
    public function isRecent(): string
    {
        return $this->getHeaders()->get('recent');
    }

    /**
     * Get message unseen flag value (from headers).
     *
     * @return bool
     */
    public function isUnseen(): bool
    {
        return 'U' === $this->getHeaders()->get('unseen');
    }

    /**
     * Get message flagged flag value (from headers).
     *
     * @return bool
     */
    public function isFlagged(): bool
    {
        return 'F' === $this->getHeaders()->get('flagged');
    }

    /**
     * Get message answered flag value (from headers).
     *
     * @return bool
     */
    public function isAnswered(): bool
    {
        return 'A' === $this->getHeaders()->get('answered');
    }

    /**
     * Get message deleted flag value (from headers).
     *
     * @return bool
     */
    public function isDeleted(): bool
    {
        return 'D' === $this->getHeaders()->get('deleted');
    }

    /**
     * Get message draft flag value (from headers).
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return 'X' === $this->getHeaders()->get('draft');
    }

    /**
     * Has the message been marked as read?
     *
     * @return bool
     */
    public function isSeen(): bool
    {
        return 'N' !== $this->getHeaders()->get('recent') && 'U' !== $this->getHeaders()->get('unseen');
    }

    /**
     * Mark message as seen.
     *
     * @return bool
     */
    public function maskAsSeen(): bool
    {
        return $this->setFlag('\\Seen');
    }

    /**
     * Move message to another mailbox.
     *
     * @param MailboxInterface $mailbox
     *
     * @throws MessageCopyException
     */
    public function copy(MailboxInterface $mailbox)
    {
        // 'deleted' header changed, force to reload headers, would be better to set deleted flag to true on header
        $this->clearHeaders();

        if (!\imap_mail_copy($this->resource->getStream(), (string) $this->messageNumber, $mailbox->getEncodedName(), \CP_UID)) {
            throw new MessageCopyException(\sprintf('Message "%s" cannot be copied to "%s"', $this->messageNumber, $mailbox->getName()));
        }
    }

    /**
     * Move message to another mailbox.
     *
     * @param MailboxInterface $mailbox
     *
     * @throws MessageMoveException
     */
    public function move(MailboxInterface $mailbox)
    {
        // 'deleted' header changed, force to reload headers, would be better to set deleted flag to true on header
        $this->clearHeaders();

        if (!\imap_mail_move($this->resource->getStream(), (string) $this->messageNumber, $mailbox->getEncodedName(), \CP_UID)) {
            throw new MessageMoveException(\sprintf('Message "%s" cannot be moved to "%s"', $this->messageNumber, $mailbox->getName()));
        }
    }

    /**
     * Delete message.
     *
     * @throws MessageDeleteException
     */
    public function delete()
    {
        // 'deleted' header changed, force to reload headers, would be better to set deleted flag to true on header
        $this->clearHeaders();

        if (!\imap_delete($this->resource->getStream(), $this->messageNumber, \FT_UID)) {
            throw new MessageDeleteException(\sprintf('Message "%s" cannot be deleted', $this->messageNumber));
        }
    }

    /**
     * Set Flag Message.
     *
     * @param $flag \Seen, \Answered, \Flagged, \Deleted, and \Draft
     *
     * @return bool
     */
    public function setFlag(string $flag): bool
    {
        $result = \imap_setflag_full($this->resource->getStream(), (string) $this->messageNumber, $flag, \ST_UID);

        $this->clearHeaders();

        return $result;
    }

    /**
     * Clear Flag Message.
     *
     * @param $flag \Seen, \Answered, \Flagged, \Deleted, and \Draft
     *
     * @return bool
     */
    public function clearFlag(string $flag): bool
    {
        $result = \imap_clearflag_full($this->resource->getStream(), (string) $this->messageNumber, $flag, \ST_UID);

        $this->clearHeaders();

        return $result;
    }
}
