<?php

namespace Ddeboer\Imap\Message;

use Ddeboer\Headers\Mail\MailHeaders;

class Headers
{
    protected $array = array();

    public function __construct(\stdClass $headers)
    {
        // Store all headers as lowercase
        $this->array = array_change_key_case((array) $headers);

        // Decode subject, as it may be UTF-8 encoded
        if (isset($headers->subject)) {
            $this->array['subject'] = '';
            foreach (\imap_mime_header_decode($headers->subject) as $part) {
                $this->array['subject'] .= $part->text;
            }
        }

        $this->array['msgno'] = (int) $this->array['msgno'];

        foreach (array('answered', 'deleted', 'draft') as $flag) {
            $this->array[$flag] = (bool) trim($this->array[$flag]);
        }

        if (isset($this->array['date'])) {
            $this->array['date'] = new \DateTime($this->array['date']);
        }

        if (isset($this->array['from'])) {
            $from = current($this->array['from']);
            $this->array['from'] = new EmailAddress($from->mailbox, $from->host, $from->personal);
        }

        if (isset($this->array['to'])) {
            $recipients = array();
            foreach ($this->array['to'] as $to) {
                $recipients[] = new EmailAddress($to->mailbox, $to->host, $to->personal);
            }
            $this->array['to'] = $recipients;
        }
    }

    public function current()
    {
        return current($this->array);
    }

    public function key()
    {
        return key($this->array);
    }

    public function next()
    {
        return next($this->array);
    }

    public function rewind()
    {
        return rewind($this->array);
    }

    public function valid()
    {
        return valid($this->array);
    }

    public function get($key)
    {
        $key = strtolower($key);

        if (isset($this->array[$key])) {
            return $this->array[$key];
        }
    }
}