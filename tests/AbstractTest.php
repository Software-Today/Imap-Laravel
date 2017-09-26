<?php

declare(strict_types=1);

namespace Ddeboer\Imap\Tests;

use Ddeboer\Imap\Mailbox;
use Ddeboer\Imap\Server;
use PHPUnit_Framework_TestCase;

abstract class AbstractTest extends PHPUnit_Framework_TestCase
{
    const IMAP_FLAGS = '/imap/ssl/novalidate-cert';

    const SPECIAL_CHARS = 'A_\\|!"£$%&()=?àèìòùÀÈÌÒÙ<>-@#[]{}_ß_б_π_€_✔_你_يد_Z_';

    final protected function getConnection()
    {
        static $connection;
        if (null === $connection) {
            $connection = $this->createConnection();
        }

        return $connection;
    }

    final protected function createConnection()
    {
        $server = new Server(\getenv('IMAP_SERVER_NAME'), \getenv('IMAP_SERVER_PORT'), self::IMAP_FLAGS);

        return $server->authenticate(\getenv('IMAP_USERNAME'), \getenv('IMAP_PASSWORD'));
    }

    final protected function createMailbox()
    {
        $this->mailboxName = uniqid('mailbox_' . self::SPECIAL_CHARS);

        return $this->getConnection()->createMailbox($this->mailboxName);
    }

    final protected function createTestMessage(
        Mailbox $mailbox,
        $subject = 'Don\'t panic!',
        $contents = 'Don\'t forget your towel',
        $from = 'someone@there.com',
        $to = 'me@here.com'
    ) {
        $message = "From: $from\r\n"
            . "To: $to\r\n"
            . "Subject: $subject\r\n"
            . "\r\n"
            . "$contents";

        $mailbox->addMessage($message);
    }

    final protected function getFixture($fixture)
    {
        return file_get_contents(__DIR__ . '/fixtures/' . $fixture);
    }
}
