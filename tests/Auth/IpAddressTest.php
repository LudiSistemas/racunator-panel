<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Auth;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\IpAddress;

final class IpAddressTest extends TestCase
{
    public function testOpsezi(): void
    {
        self::assertTrue(IpAddress::inRange('10.1.2.3', '10.0.0.0/8'));
        self::assertFalse(IpAddress::inRange('11.1.2.3', '10.0.0.0/8'));
        self::assertTrue(IpAddress::inRange('192.168.1.130', '192.168.1.128/25'));
        self::assertFalse(IpAddress::inRange('192.168.1.127', '192.168.1.128/25'));
        self::assertTrue(IpAddress::inRange('203.0.113.5', '203.0.113.5'));
        self::assertTrue(IpAddress::inRange('1.2.3.4', '0.0.0.0/0'));
        self::assertTrue(IpAddress::inRange('2001:db8:1::5', '2001:db8::/32'));
        self::assertFalse(IpAddress::inRange('2001:db9::1', '2001:db8::/32'));
        self::assertTrue(IpAddress::inRange('::ffff:10.0.0.1', '10.0.0.0/8'));
        // IPv4 i IPv6 se ne mešaju; neispravni opsezi ne odgovaraju ničemu.
        self::assertFalse(IpAddress::inRange('10.0.0.1', '::/0'));
        self::assertFalse(IpAddress::inRange('10.0.0.1', '10.0.0.0/33'));
        self::assertFalse(IpAddress::inRange('10.0.0.1', 'nije-opseg'));
        self::assertFalse(IpAddress::inRange('nije-ip', '10.0.0.0/8'));
        self::assertTrue(IpAddress::inAny('10.0.0.1', ['192.168.0.0/16', '10.0.0.0/8']));
        self::assertFalse(IpAddress::inAny('10.0.0.1', []));
    }

    public function testKljucZaOgranicenje(): void
    {
        self::assertSame('203.0.113.7', IpAddress::limiterKey('203.0.113.7'));
        self::assertSame('203.0.113.7', IpAddress::limiterKey('::ffff:203.0.113.7'));
        self::assertSame('2001:db8:1:2::/64', IpAddress::limiterKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame(IpAddress::limiterKey('2001:db8:1:2::1'), IpAddress::limiterKey('2001:0db8:0001:0002:ffff::9'));
        self::assertNotSame(IpAddress::limiterKey('2001:db8:1:2::1'), IpAddress::limiterKey('2001:db8:1:3::1'));
        self::assertSame('nepoznato', IpAddress::limiterKey('nepoznato'));
        self::assertLessThanOrEqual(45, strlen(IpAddress::limiterKey('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff')));
    }
}
