<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * An entity's per-page SEO overrides.
 *
 * One item holding everything an editor can set for one page. Empty values
 * mean "use the templates"; only what is set overrides them.
 *
 * @property string|null $title
 * @property string|null $description
 * @property int|null $image
 * @property string|null $keyphrase
 * @property string|null $page_type
 * @property string|null $article_type
 * @property bool|null $noindex
 * @property bool|null $cornerstone
 * @property int|null $seo_score
 * @property int|null $readability_score
 */
#[FieldType(
  id: 'neo_seo_overrides',
  label: new TranslatableMarkup('SEO overrides'),
  description: new TranslatableMarkup('Per-page title, description, share image and schema types.'),
  default_widget: 'neo_seo_overrides',
  no_ui: TRUE,
)]
class SeoOverridesItem extends FieldItemBase {

  /**
   * The properties an editor sets, as opposed to the stored scores.
   */
  public const EDITABLE = [
    'title',
    'description',
    'image',
    'keyphrase',
    'page_type',
    'article_type',
    'noindex',
    'cornerstone',
  ];

  /**
   * The scores content analysis stores beside the overrides.
   */
  public const SCORES = ['seo_score', 'readability_score'];

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['title'] = DataDefinition::create('string')->setLabel(new TranslatableMarkup('Title'));
    $properties['description'] = DataDefinition::create('string')->setLabel(new TranslatableMarkup('Description'));
    $properties['image'] = DataDefinition::create('integer')->setLabel(new TranslatableMarkup('Share image media id'));
    $properties['keyphrase'] = DataDefinition::create('string')->setLabel(new TranslatableMarkup('Focus keyphrase'));
    $properties['page_type'] = DataDefinition::create('string')->setLabel(new TranslatableMarkup('Page type'));
    $properties['article_type'] = DataDefinition::create('string')->setLabel(new TranslatableMarkup('Article type'));
    $properties['noindex'] = DataDefinition::create('boolean')->setLabel(new TranslatableMarkup('Hide from search results'));
    $properties['cornerstone'] = DataDefinition::create('boolean')->setLabel(new TranslatableMarkup('Cornerstone content'));
    // Written by content analysis: advisory, so a score is a 0–100 integer or
    // nothing, and never a reason to refuse a save.
    $properties['seo_score'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('SEO score'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
    $properties['readability_score'] = DataDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Readability score'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    $string = static fn(int $length): array => ['type' => 'varchar', 'length' => $length, 'not null' => FALSE];
    $flag = ['type' => 'int', 'size' => 'tiny', 'unsigned' => TRUE, 'not null' => FALSE];
    $score = ['type' => 'int', 'size' => 'tiny', 'unsigned' => TRUE, 'not null' => FALSE];
    return [
      'columns' => [
        'title' => $string(255),
        'description' => ['type' => 'text', 'size' => 'normal', 'not null' => FALSE],
        'image' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => FALSE],
        'keyphrase' => $string(255),
        'page_type' => $string(64),
        'article_type' => $string(64),
        'noindex' => $flag,
        'cornerstone' => $flag,
        'seo_score' => $score,
        'readability_score' => $score,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    foreach (static::EDITABLE as $property) {
      $value = $this->get($property)->getValue();
      if ($value !== NULL && $value !== '' && $value !== FALSE && $value !== 0 && $value !== '0') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    parent::preSave();
    foreach (['title', 'description', 'keyphrase'] as $property) {
      if (is_string($this->{$property})) {
        $this->{$property} = trim($this->{$property}) ?: NULL;
      }
    }
  }

}
