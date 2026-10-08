<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_seo\Kernel;

use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\file\Entity\File;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\media\Entity\Media;
use Drupal\neo_seo\Entity\SeoDefaults;
use Drupal\neo_seo\Hook\NeoSeoHooks;
use Drupal\neo_seo\SeoOverrides;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * SEO overrides: a base field on the entity types a site turns on.
 */
#[Group('neo_seo')]
class SeoOverridesTest extends NeoSeoKernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_test', 'language', 'text', 'filter'];

  /**
   * The page's entity.
   */
  protected EntityTestMul $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test_mul');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['media', 'image', 'language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $this->config('neo_seo.settings')->set('override_entity_types', ['entity_test_mul'])->save();
    $this->entity = EntityTestMul::create(['name' => 'Grid & growth']);
    $this->entity->save();

    (SeoDefaults::load('global') ?? SeoDefaults::create(['id' => 'global', 'label' => 'Global']))->setValues([
      'title' => '[entity_test_mul:name] | [site:name]',
      'description' => 'Template description',
      'image' => '/template.png',
    ])->save();
  }

  /**
   * The head of the entity's page, tags keyed by attachment key.
   */
  protected function head(?EntityTestMul $entity = NULL): array {
    $entity ??= $this->entity;
    $this->pushRoute('entity.entity_test_mul.canonical', ['entity_test_mul' => $entity], '/entity_test_mul/manage/' . $entity->id());
    $attachments = ['#attached' => []];
    $this->container->get(NeoSeoHooks::class)->pageAttachments($attachments);
    $tags = [];
    foreach ($attachments['#attached']['html_head'] ?? [] as [$element, $key]) {
      $tags[$key] = $element['#attributes']['content'] ?? $element['#attributes']['href'] ?? NULL;
    }
    $variables = ['head_title' => []];
    $this->container->get(NeoSeoHooks::class)->preprocessHtml($variables);
    $tags['title'] = $variables['head_title']['title'] ?? NULL;
    return $tags;
  }

  /**
   * Turning an entity type on installs the field; others do not get it.
   */
  public function testFieldFollowsTheSetting(): void {
    $this->assertTrue($this->entity->hasField(SeoOverrides::FIELD));
    $definition = $this->container->get('entity.definition_update_manager')->getFieldStorageDefinition(SeoOverrides::FIELD, 'entity_test_mul');
    $this->assertNotNull($definition, 'The storage is installed.');
    $this->assertTrue($definition->isTranslatable());
    $this->assertFalse($this->container->get('entity_field.manager')->getFieldStorageDefinitions('user')[SeoOverrides::FIELD] ?? FALSE);

    // Off again, with no data: the storage goes.
    $this->config('neo_seo.settings')->set('override_entity_types', [])->save();
    $this->assertNull($this->container->get('entity.definition_update_manager')->getFieldStorageDefinition(SeoOverrides::FIELD, 'entity_test_mul'));
  }

  /**
   * Turning it off while values are stored keeps the field and its data.
   */
  public function testStoredOverridesAreNeverDropped(): void {
    $this->entity->set(SeoOverrides::FIELD, ['title' => 'Kept'])->save();
    $this->assertTrue($this->container->get('neo_seo.overrides_storage')->hasData('entity_test_mul'));

    $this->config('neo_seo.settings')->set('override_entity_types', [])->save();
    $this->assertNotNull($this->container->get('entity.definition_update_manager')->getFieldStorageDefinition(SeoOverrides::FIELD, 'entity_test_mul'));
    $this->assertSame('Kept', SeoOverrides::of(EntityTestMul::load($this->entity->id()))['title']);
  }

  /**
   * Overrides beat every template; clearing them falls back.
   */
  public function testOverridesWin(): void {
    $before = $this->head();
    $this->assertSame('Grid & growth | Westwood', $before['title']);
    $this->assertSame('Template description', $before['neo_seo_description']);
    $this->assertArrayNotHasKey('neo_seo_robots', $before);

    $media = $this->createImageMedia();
    $this->entity->set(SeoOverrides::FIELD, [
      'title' => 'Healing gardens, explained',
      'description' => 'Why [entity_test_mul:name] matters',
      'image' => $media->id(),
      'noindex' => TRUE,
    ])->save();
    $after = $this->head(EntityTestMul::load($this->entity->id()));
    $this->assertSame('Healing gardens, explained', $after['title']);
    $this->assertSame('Why Grid & growth matters', $after['neo_seo_description'], 'An override may use tokens.');
    $this->assertSame('noindex, follow', $after['neo_seo_robots']);
    $this->assertStringEndsWith('/share.png', $after['neo_seo_og_image']);
    $this->assertSame('40', $after['neo_seo_og_image_width']);
    $this->assertSame('Share alt', $after['neo_seo_og_image_alt']);

    $this->entity = EntityTestMul::load($this->entity->id());
    $this->entity->set(SeoOverrides::FIELD, [])->save();
    $this->assertSame('Grid & growth | Westwood', $this->head(EntityTestMul::load($this->entity->id()))['title']);
  }

  /**
   * Each translation holds its own overrides.
   */
  public function testTranslationsHoldTheirOwn(): void {
    $this->entity->set(SeoOverrides::FIELD, ['title' => 'English title']);
    $this->entity->addTranslation('fr', ['name' => 'Jardins', SeoOverrides::FIELD => ['title' => 'Titre français']]);
    $this->entity->save();
    $entity = EntityTestMul::load($this->entity->id());
    $this->assertSame('English title', SeoOverrides::of($entity)['title']);
    $this->assertSame('Titre français', SeoOverrides::of($entity->getTranslation('fr'))['title']);
  }

  /**
   * The overrides are tokens.
   */
  public function testTokens(): void {
    $this->entity->set(SeoOverrides::FIELD, ['keyphrase' => 'healing gardens', 'page_type' => 'AboutPage'])->save();
    $text = $this->container->get('token')->replacePlain('[neo-seo:keyphrase] / [neo-seo:page-type] / [neo-seo:title]', ['entity_test_mul' => $this->entity], ['clear' => TRUE]);
    $this->assertSame('healing gardens / AboutPage / ', $text);
  }

  /**
   * Editing takes the permission; viewing does not.
   */
  public function testEditAccess(): void {
    $this->installEntitySchema('user');
    $this->installSchema('system', ['sequences']);
    $this->createUser([], NULL, FALSE, ['uid' => 1]);
    $editor = $this->createUser(['view test entity']);
    $seo = $this->createUser(['view test entity', 'edit neo_seo overrides']);
    $field = $this->entity->get(SeoOverrides::FIELD);
    $this->assertFalse($field->access('edit', $editor));
    $this->assertTrue($field->access('edit', $seo));
    $this->assertTrue($field->access('view', $editor));
  }

  /**
   * Empty means empty: flags off and blank strings are not overrides.
   */
  public function testEmptiness(): void {
    $this->entity->set(SeoOverrides::FIELD, ['title' => '  ', 'noindex' => FALSE, 'cornerstone' => FALSE])->save();
    $this->assertTrue(EntityTestMul::load($this->entity->id())->get(SeoOverrides::FIELD)->isEmpty());
  }

  /**
   * Creates an image media item with a 40x20 file.
   */
  protected function createImageMedia(): Media {
    $type = $this->createMediaType('image');
    $image = imagecreatetruecolor(40, 20);
    imagepng($image, 'public://share.png');
    $file = File::create(['uri' => 'public://share.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $type->id(),
      'name' => 'Share',
      $type->getSource()->getConfiguration()['source_field'] => ['target_id' => $file->id(), 'alt' => 'Share alt'],
    ]);
    $media->save();
    return $media;
  }

}
