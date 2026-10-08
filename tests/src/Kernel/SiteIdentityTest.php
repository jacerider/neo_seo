<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Site identity: what the site represents, at {site}#organization.
 */
#[Group('neo_seo')]
class SiteIdentityTest extends NeoSeoKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['media', 'image']);
    $this->config('system.site')->set('slogan', 'Engineering the grid')->set('mail', 'admin@example.com')->save();
  }

  /**
   * Writes the identity settings.
   */
  protected function setIdentity(array $identity): void {
    $config = $this->config('neo_seo.settings');
    $config->set('identity', $identity + $config->get('identity'))->save();
    $this->resetSettings();
  }

  /**
   * The shipped defaults: an Organization named by token, no empty values.
   */
  public function testDefaults(): void {
    $document = $this->build($this->context());
    $organization = $this->assertGraphNode($document, 'https://example.com/#organization');

    $this->assertSame('Organization', $organization['@type']);
    $this->assertSame('Acme', $organization['name']);
    $this->assertSame('Engineering the grid', $organization['description']);
    $this->assertSame('https://example.com/', $organization['url']);
    foreach (['sameAs', 'telephone', 'email', 'address', 'logo'] as $empty) {
      $this->assertArrayNotHasKey($empty, $organization, "Empty $empty is omitted.");
    }
    $this->assertGraphReferencesResolve($document);
  }

  /**
   * A configured organization: type, profiles, contact, address, logo.
   */
  public function testConfiguredOrganization(): void {
    $media = $this->createImageMedia();
    $this->setIdentity([
      'organization_type' => 'ProfessionalService',
      'name' => 'Acme Engineering',
      'same_as' => ['https://www.linkedin.com/company/acme', '[site:url]about'],
      'telephone' => '555-0100',
      'email' => '[site:mail], [site:nothing-here]',
      'address' => [
        'street' => '100 Main Street',
        'locality' => 'Springfield',
        'region' => 'IL',
        'postal_code' => '62701',
        'country' => '',
      ],
      'logo' => $media->uuid(),
    ]);

    $context = $this->context();
    $document = $this->build($context);
    $organization = $this->assertGraphNode($document, 'https://example.com/#organization');

    $this->assertSame('ProfessionalService', $organization['@type']);
    $this->assertSame('Acme Engineering', $organization['name']);
    $this->assertCount(2, $organization['sameAs']);
    $this->assertStringEndsWith('/about', $organization['sameAs'][1], 'Tokens resolve in social profiles.');
    $this->assertSame('admin@example.com', $organization['email'], 'A separator stranded by an empty token is tidied.');
    $this->assertSame([
      '@type' => 'PostalAddress',
      'streetAddress' => '100 Main Street',
      'addressLocality' => 'Springfield',
      'addressRegion' => 'IL',
      'postalCode' => '62701',
    ], $organization['address'], 'An empty address part is omitted.');

    $this->assertSame(['@id' => 'https://example.com/#/schema/logo/image/'], $organization['logo']);
    $logo = $this->assertGraphNode($document, 'https://example.com/#/schema/logo/image/');
    $this->assertSame('ImageObject', $logo['@type']);
    $this->assertStringEndsWith('/logo.png', $logo['contentUrl']);
    $this->assertSame(40, $logo['width']);
    $this->assertSame(20, $logo['height']);
    $this->assertSame('Acme logo', $logo['caption']);
    $this->assertGraphReferencesResolve($document);

    $tags = $context->getCacheability()->getCacheTags();
    $this->assertContains('config:neo_seo.settings', $tags);
    $this->assertContains('media:' . $media->id(), $tags);
  }

  /**
   * A person: same id, an image instead of a logo.
   */
  public function testPerson(): void {
    $media = $this->createImageMedia();
    $this->setIdentity(['type' => 'person', 'name' => 'Ada Lovelace', 'logo' => $media->uuid()]);
    $document = $this->build($this->context());
    $person = $this->assertGraphNode($document, 'https://example.com/#organization');
    $this->assertSame('Person', $person['@type']);
    $this->assertSame(['@id' => 'https://example.com/#/schema/logo/image/'], $person['image']);
    $this->assertArrayNotHasKey('logo', $person);
    $this->assertGraphReferencesResolve($document);
  }

  /**
   * An unknown type or a deleted logo degrade instead of breaking the graph.
   */
  public function testInvalidValuesDegrade(): void {
    $this->setIdentity(['organization_type' => 'Spaceship', 'logo' => 'not-a-uuid']);
    $document = $this->build($this->context());
    $organization = $this->assertGraphNode($document, 'https://example.com/#organization');
    $this->assertSame('Organization', $organization['@type']);
    $this->assertArrayNotHasKey('logo', $organization);
    $this->assertNotContains('https://example.com/#/schema/logo/image/', $this->graphIds($document));
  }

  /**
   * Creates an image media item with a 40x20 file.
   */
  protected function createImageMedia() {
    $type = $this->createMediaType('image');
    $image = imagecreatetruecolor(40, 20);
    imagepng($image, 'public://logo.png');
    $file = File::create(['uri' => 'public://logo.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $type->id(),
      'name' => 'Logo',
      $type->getSource()->getConfiguration()['source_field'] => [
        'target_id' => $file->id(),
        'alt' => 'Acme logo',
      ],
    ]);
    $media->save();
    return $media;
  }

}
