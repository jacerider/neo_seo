<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_seo_test\FaviconManagerStub;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use PHPUnit\Framework\Attributes\Group;

/**
 * The identity from site settings' social links and from the favicon.
 *
 * Site settings is the real module; the favicon is a stand-in for
 * neo_favicon's manager, whose service exists only while it is enabled.
 */
#[Group('neo_seo')]
class IdentitySourcesTest extends NeoSeoKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'link',
    'telephone',
    'path_alias',
    'linkit',
    'neo',
    'neo_site_settings',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('neo_favicon.manager', FaviconManagerStub::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('neo_site_settings');
    SiteSettingsType::create(['id' => 'social', 'label' => 'Social', 'aggregate' => TRUE])->save();
    $display = $this->container->get('entity_display.repository')->getViewDisplay('neo_site_settings', 'social');
    foreach (['field_youtube' => 1, 'field_facebook' => 0, 'field_x' => 2] as $name => $weight) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'neo_site_settings', 'type' => 'link'])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'neo_site_settings',
        'bundle' => 'social',
        'label' => ucfirst(substr($name, 6)),
      ])->save();
      $display->setComponent($name, ['type' => 'link', 'weight' => $weight]);
    }
    $display->save();
    $settings = $this->container->get('entity_type.manager')->getStorage('neo_site_settings')->loadOrCreateByType('social');
    $settings->set('field_facebook', ['uri' => 'https://www.facebook.com/acme']);
    $settings->set('field_youtube', ['uri' => 'https://www.youtube.com/acme']);
    $settings->save();
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
   * The identity a page resolves.
   */
  protected function identity(): array {
    return $this->container->get('neo_seo.site_identity')->resolve($this->context());
  }

  /**
   * Site settings' filled links, in display order, then the extra ones.
   *
   * A profile in both lists is listed once, however it is written.
   */
  public function testSocialProfilesFromSiteSettings(): void {
    $this->setIdentity([
      'same_as_site_settings' => TRUE,
      'same_as_type' => 'social',
      'same_as' => ['https://facebook.com/acme/', 'https://en.wikipedia.org/wiki/Acme'],
    ]);
    $this->assertSame([
      'https://www.facebook.com/acme',
      'https://www.youtube.com/acme',
      'https://en.wikipedia.org/wiki/Acme',
    ], $this->identity()['same_as']);

    // A link changed in site settings changes the identity.
    $settings = $this->container->get('entity_type.manager')->getStorage('neo_site_settings')->loadByType('social');
    $settings->set('field_x', ['uri' => 'https://x.com/acme'])->save();
    $this->resetSettings();
    $this->assertContains('https://x.com/acme', $this->identity()['same_as']);

    // Off: only the profiles typed here.
    $this->setIdentity(['same_as_site_settings' => FALSE]);
    $this->assertSame(['https://facebook.com/acme/', 'https://en.wikipedia.org/wiki/Acme'], $this->identity()['same_as']);
  }

  /**
   * The favicon's largest icon is the logo, at its own size, named.
   */
  public function testLogoFromFavicon(): void {
    $this->setIdentity(['logo_source' => 'favicon', 'name' => 'Acme Engineering']);
    $this->assertNull($this->identity()['logo'], 'No favicon uploaded: no logo.');

    $this->container->get('state')->set('neo_seo_test.favicon_images', [
      'public://neo-favicon/favicon-96x96.png' => ['width' => 96, 'height' => 96],
      'public://neo-favicon/web-app-manifest-512x512.png' => ['width' => 512, 'height' => 512],
    ]);
    $this->resetSettings();
    $logo = $this->identity()['logo'];
    $this->assertStringEndsWith('/neo-favicon/web-app-manifest-512x512.png', $logo['url']);
    $this->assertSame(512, $logo['width']);
    $this->assertSame('Acme Engineering', $logo['caption']);

    $organization = $this->assertGraphNode($this->build($this->context()), 'https://example.com/#organization');
    $this->assertArrayHasKey('logo', $organization);
  }

  /**
   * What is available is taken up only where neo_seo has nothing of its own.
   */
  public function testAdoptTakesOnlyWhatIsFree(): void {
    $sources = $this->container->get('neo_seo.identity_sources');

    // No logo chosen, and a typed list that site settings already holds.
    $this->setIdentity([
      'logo' => '',
      'logo_source' => 'media',
      'same_as_site_settings' => FALSE,
      'same_as' => ['https://www.facebook.com/acme', 'http://youtube.com/acme/'],
    ]);
    $sources->adoptInConfig();
    $identity = $this->config('neo_seo.settings')->get('identity');
    $this->assertSame('favicon', $identity['logo_source']);
    $this->assertTrue($identity['same_as_site_settings']);
    $this->assertSame('social', $identity['same_as_type']);
    $this->assertSame([], $identity['same_as'], 'Nothing in the list was lost: site settings holds it all.');

    // A chosen logo, and a list with something site settings lacks: kept.
    $this->setIdentity([
      'logo' => 'some-media-uuid',
      'logo_source' => 'media',
      'same_as_site_settings' => FALSE,
      'same_as' => ['https://www.facebook.com/acme', 'https://en.wikipedia.org/wiki/Acme'],
    ]);
    $sources->adoptInConfig();
    $identity = $this->config('neo_seo.settings')->get('identity');
    $this->assertSame('media', $identity['logo_source']);
    $this->assertFalse($identity['same_as_site_settings']);
    $this->assertCount(2, $identity['same_as']);
  }

}
