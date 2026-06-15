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

namespace FreePBX\modules\Googlecontactsync\Lib;

use Google\Service\PeopleService\Person;

/**
 * Maps a Google People API {@see Person} onto the Contact Manager `$entry`
 * array consumed by `addEntryByGroupID()` / `updateEntry()`.
 *
 * Pure and side-effect free (no HTTP, filesystem, or DB access) so it can be
 * unit-tested in isolation. The contact photo is intentionally *not* fetched
 * here; callers obtain its URL via {@see getPhotoUrl()} and download it
 * separately, keeping this class deterministic.
 */
class ContactMapper {

	/**
	 * Map a Google {@see Person} to a Contact Manager entry.
	 *
	 * @param Person $person
	 * @return array<string,mixed>|null The entry array, or null when the person
	 *                                  has no usable name, phone, and email
	 *                                  (skip rule, spec §8.3).
	 */
	public function map(Person $person) {
		$names      = $person->getNames();
		$name       = (is_array($names) && !empty($names)) ? $names[0] : null;
		$given      = $name ? trim((string) $name->getGivenName()) : '';
		$family     = $name ? trim((string) $name->getFamilyName()) : '';
		$prefix     = $name ? trim((string) $name->getHonorificPrefix()) : '';
		$suffix     = $name ? trim((string) $name->getHonorificSuffix()) : '';
		$rawDisplay = $name ? trim((string) $name->getDisplayName()) : '';

		$numbers = $this->mapNumbers($person);
		$emails  = $this->mapEmails($person);

		$hasName = ($rawDisplay !== '' || $given !== '' || $family !== '' || $prefix !== '');
		if (!$hasName && empty($numbers) && empty($emails)) {
			return null;
		}

		return array(
			'userid'      => -1,
			'displayname' => $this->buildDisplayName($rawDisplay, $prefix, $given, $family, $emails, $numbers),
			'fname'       => $given,
			'lname'       => $family,
			'title'       => trim($prefix.' '.$suffix),
			'company'     => $this->buildCompany($person),
			'address'     => $this->mapAddress($person),
			'numbers'     => $numbers,
			'emails'      => $emails,
			'websites'    => $this->mapWebsites($person),
			'gravatar'    => false,
		);
	}

	/**
	 * The first non-default contact photo URL, if any.
	 *
	 * @param Person $person
	 * @return string|null
	 */
	public function getPhotoUrl(Person $person) {
		$photos = $person->getPhotos();
		if (is_array($photos)) {
			foreach ($photos as $photo) {
				if ($photo->getDefault()) {
					continue; // Google's generated placeholder avatar.
				}
				$url = trim((string) $photo->getUrl());
				if ($url !== '') {
					return $url;
				}
			}
		}
		return null;
	}

	/**
	 * Compose the display name, honouring the honorific prefix and falling back
	 * to constructed name parts, then the first email, then the first phone.
	 */
	private function buildDisplayName($rawDisplay, $prefix, $given, $family, array $emails, array $numbers) {
		if ($rawDisplay !== '') {
			if ($prefix !== '' && stripos($rawDisplay, $prefix) !== 0) {
				return trim($prefix.' '.$rawDisplay);
			}
			return $rawDisplay;
		}
		$constructed = trim(preg_replace('/\s+/', ' ', trim($prefix.' '.$given.' '.$family)));
		if ($constructed !== '') {
			return $constructed;
		}
		if (!empty($emails)) {
			return $emails[0]['email'];
		}
		if (!empty($numbers)) {
			return $numbers[0]['number'];
		}
		return '';
	}

	/**
	 * `organizations[0]` → "Company — Job Title" (either part alone when the
	 * other is missing).
	 */
	private function buildCompany(Person $person) {
		$orgs = $person->getOrganizations();
		$org  = (is_array($orgs) && !empty($orgs)) ? $orgs[0] : null;
		if (!$org) {
			return '';
		}
		$company  = trim((string) $org->getName());
		$jobTitle = trim((string) $org->getTitle());
		if ($company !== '' && $jobTitle !== '') {
			return $company.' — '.$jobTitle;
		}
		return $company !== '' ? $company : $jobTitle;
	}

	/**
	 * `addresses[0].formattedValue` as a single string.
	 */
	private function mapAddress(Person $person) {
		$addresses = $person->getAddresses();
		if (is_array($addresses) && !empty($addresses)) {
			return trim((string) $addresses[0]->getFormattedValue());
		}
		return '';
	}

	/**
	 * Map phone numbers, keeping the raw international value (Contact Manager
	 * normalises it via libphonenumber when `locale` is `AUTO`).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function mapNumbers(Person $person) {
		$out    = array();
		$phones = $person->getPhoneNumbers();
		if (is_array($phones)) {
			foreach ($phones as $phone) {
				$value = trim((string) $phone->getValue());
				if ($value === '') {
					continue;
				}
				$mapped   = $this->mapPhoneType((string) $phone->getType());
				$out[] = array(
					'number'    => $value,
					'type'      => $mapped['type'],
					'extension' => '',
					'flags'     => $mapped['flags'],
					'speeddial' => '',
					'locale'    => 'AUTO',
				);
			}
		}
		return $out;
	}

	/**
	 * Translate a Google phone `type` to a Contact Manager `type` + `flags`.
	 *
	 * Contact Manager has no `fax` *type*: fax is a flag on a work/home/other
	 * number, so the fax variants map to a base type plus the `fax` flag.
	 *
	 * @param string $googleType
	 * @return array{type:string,flags:array<int,string>}
	 */
	private function mapPhoneType($googleType) {
		switch (strtolower(trim($googleType))) {
			case 'mobile':
			case 'cell':
				return array('type' => 'cell', 'flags' => array());
			case 'work':
			case 'workmobile':
				return array('type' => 'work', 'flags' => array());
			case 'home':
				return array('type' => 'home', 'flags' => array());
			case 'workfax':
				return array('type' => 'work', 'flags' => array('fax'));
			case 'homefax':
				return array('type' => 'home', 'flags' => array('fax'));
			case 'fax':
				return array('type' => 'other', 'flags' => array('fax'));
			case 'main':
			default:
				return array('type' => 'other', 'flags' => array());
		}
	}

	/**
	 * @return array<int,array{email:string}>
	 */
	private function mapEmails(Person $person) {
		$out    = array();
		$emails = $person->getEmailAddresses();
		if (is_array($emails)) {
			foreach ($emails as $email) {
				$value = trim((string) $email->getValue());
				if ($value !== '') {
					$out[] = array('email' => $value);
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<int,array{website:string}>
	 */
	private function mapWebsites(Person $person) {
		$out  = array();
		$urls = $person->getUrls();
		if (is_array($urls)) {
			foreach ($urls as $url) {
				$value = trim((string) $url->getValue());
				if ($value !== '') {
					$out[] = array('website' => $value);
				}
			}
		}
		return $out;
	}
}
