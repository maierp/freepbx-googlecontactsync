<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * Google Contact Sync — FreePBX module
 *
 * Copyright (C) 2026 Dr. Patrick Maier, Softwareentwicklung Patrick Maier
 * https://www.se-pm.de — mail@se-pm.de
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

use PHPUnit\Framework\TestCase;
use FreePBX\modules\Googlecontactsync\Lib\ContactMapper;
use Google\Service\PeopleService\Person;

/**
 * @covers \FreePBX\modules\Googlecontactsync\Lib\ContactMapper
 */
class ContactMapperTest extends TestCase {

	/** @var ContactMapper */
	private $mapper;

	protected function setUp(): void {
		$this->mapper = new ContactMapper();
	}

	private function person(array $data): Person {
		return new Person($data);
	}

	public function testMapsAllSupportedFields(): void {
		$entry = $this->mapper->map($this->person(array(
			'resourceName' => 'people/c1',
			'etag'         => 'etag-1',
			'names' => array(array(
				'displayName'     => 'Jane Doe',
				'givenName'       => 'Jane',
				'familyName'      => 'Doe',
				'honorificPrefix' => 'Dr.',
				'honorificSuffix' => 'PhD',
			)),
			'organizations' => array(array('name' => 'Acme Inc', 'title' => 'CTO')),
			'addresses'     => array(array('formattedValue' => "123 Main St\nSpringfield")),
			'phoneNumbers'  => array(array('value' => '+15551234567', 'type' => 'mobile')),
			'emailAddresses'=> array(array('value' => 'jane@acme.com')),
			'urls'          => array(array('value' => 'https://acme.com')),
		)));

		$this->assertNotNull($entry);
		$this->assertSame('Dr. Jane Doe', $entry['displayname']);
		$this->assertSame('Jane', $entry['fname']);
		$this->assertSame('Doe', $entry['lname']);
		$this->assertSame('Dr. PhD', $entry['title']);
		$this->assertSame('Acme Inc — CTO', $entry['company']);
		$this->assertSame("123 Main St\nSpringfield", $entry['address']);
		$this->assertSame(array(array('email' => 'jane@acme.com')), $entry['emails']);
		$this->assertSame(array(array('website' => 'https://acme.com')), $entry['websites']);
		$this->assertSame(-1, $entry['userid']);
		$this->assertFalse($entry['gravatar']);

		$this->assertCount(1, $entry['numbers']);
		$this->assertSame('+15551234567', $entry['numbers'][0]['number']);
		$this->assertSame('cell', $entry['numbers'][0]['type']);
		$this->assertSame('AUTO', $entry['numbers'][0]['locale']);
		$this->assertSame(array(), $entry['numbers'][0]['flags']);
	}

	public function testHonorificPrefixNotDoubledWhenAlreadyPresent(): void {
		$entry = $this->mapper->map($this->person(array(
			'names' => array(array('displayName' => 'Dr. Jane Doe', 'honorificPrefix' => 'Dr.')),
		)));
		$this->assertSame('Dr. Jane Doe', $entry['displayname']);
	}

	public function testDisplayNameConstructedFromPartsWhenNoDisplayName(): void {
		$entry = $this->mapper->map($this->person(array(
			'names' => array(array('givenName' => 'Jane', 'familyName' => 'Doe', 'honorificPrefix' => 'Dr.')),
		)));
		$this->assertSame('Dr. Jane Doe', $entry['displayname']);
	}

	public function testDisplayNameFallsBackToEmailThenPhone(): void {
		$emailOnly = $this->mapper->map($this->person(array(
			'emailAddresses' => array(array('value' => 'no-name@example.com')),
		)));
		$this->assertSame('no-name@example.com', $emailOnly['displayname']);

		$phoneOnly = $this->mapper->map($this->person(array(
			'phoneNumbers' => array(array('value' => '+15550001111', 'type' => 'home')),
		)));
		$this->assertSame('+15550001111', $phoneOnly['displayname']);
	}

	public function testSkipsPersonWithNoNamePhoneOrEmail(): void {
		$this->assertNull($this->mapper->map($this->person(array(
			'organizations' => array(array('name' => 'Acme Inc')),
		))));
		$this->assertNull($this->mapper->map($this->person(array())));
	}

	/**
	 * @dataProvider phoneTypeProvider
	 */
	public function testPhoneTypeMapping(string $googleType, string $expectedType, array $expectedFlags): void {
		$entry = $this->mapper->map($this->person(array(
			'names'        => array(array('displayName' => 'X')),
			'phoneNumbers' => array(array('value' => '+15550000000', 'type' => $googleType)),
		)));
		$this->assertSame($expectedType, $entry['numbers'][0]['type']);
		$this->assertSame($expectedFlags, $entry['numbers'][0]['flags']);
	}

	public static function phoneTypeProvider(): array {
		return array(
			'mobile'     => array('mobile', 'cell', array()),
			'cell'       => array('cell', 'cell', array()),
			'work'       => array('work', 'work', array()),
			'home'       => array('home', 'home', array()),
			'workMobile' => array('workMobile', 'work', array()),
			'main'       => array('main', 'other', array()),
			'fax'        => array('fax', 'other', array('fax')),
			'homeFax'    => array('homeFax', 'home', array('fax')),
			'workFax'    => array('workFax', 'work', array('fax')),
			'unknown'    => array('something-else', 'other', array()),
		);
	}

	public function testCompanyUsesJobTitleAloneWhenNoCompanyName(): void {
		$entry = $this->mapper->map($this->person(array(
			'names'         => array(array('displayName' => 'Jane')),
			'organizations' => array(array('title' => 'Freelancer')),
		)));
		$this->assertSame('Freelancer', $entry['company']);
	}

	public function testTitleIsHonorificPrefixAndSuffixCombined(): void {
		$entry = $this->mapper->map($this->person(array(
			'names' => array(array('displayName' => 'Jane', 'honorificPrefix' => 'Prof.', 'honorificSuffix' => 'Jr.')),
		)));
		$this->assertSame('Prof. Jr.', $entry['title']);
	}

	public function testEmptyValuesAreFilteredOut(): void {
		$entry = $this->mapper->map($this->person(array(
			'names'          => array(array('displayName' => 'Jane')),
			'phoneNumbers'   => array(array('value' => '', 'type' => 'work'), array('value' => '+15551112222', 'type' => 'work')),
			'emailAddresses' => array(array('value' => ''), array('value' => 'jane@x.com')),
			'urls'           => array(array('value' => '')),
		)));
		$this->assertCount(1, $entry['numbers']);
		$this->assertSame(array(array('email' => 'jane@x.com')), $entry['emails']);
		$this->assertSame(array(), $entry['websites']);
	}

	public function testGetPhotoUrlSkipsDefaultAvatar(): void {
		$person = $this->person(array(
			'photos' => array(
				array('url' => 'https://example.com/default.png', 'default' => true),
				array('url' => 'https://example.com/real.jpg', 'default' => false),
			),
		));
		$this->assertSame('https://example.com/real.jpg', $this->mapper->getPhotoUrl($person));
	}

	public function testGetPhotoUrlReturnsNullWhenOnlyDefault(): void {
		$person = $this->person(array(
			'photos' => array(array('url' => 'https://example.com/default.png', 'default' => true)),
		));
		$this->assertNull($this->mapper->getPhotoUrl($person));
	}

	public function testGetPhotoUrlReturnsNullWhenNoPhotos(): void {
		$this->assertNull($this->mapper->getPhotoUrl($this->person(array())));
	}
}
