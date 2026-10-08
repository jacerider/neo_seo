<?php

declare(strict_types=1);

namespace Drupal\neo_seo\Plugin\HeadTag;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_seo\Attribute\HeadTag;
use Drupal\neo_seo\Head\HeadTagBase;
use Drupal\neo_seo\Head\HeadValues;

/**
 * The X (Twitter) card. A large image card when there is an image.
 */
#[HeadTag(
  id: 'twitter_card',
  label: new TranslatableMarkup('X card'),
  weight: 10,
)]
class TwitterCard extends HeadTagBase {

  /**
   * {@inheritdoc}
   */
  public function build(HeadValues $values): array {
    $image = $values->image();
    $tags = array_merge(
      $this->meta('twitter:card', $image ? 'summary_large_image' : 'summary'),
      $this->meta('twitter:title', $values->get('social_title') ?: $values->get('title')),
      $this->meta('twitter:description', $values->get('description')),
    );
    if ($image) {
      $tags = array_merge(
        $tags,
        $this->meta('twitter:image', $image['url']),
        $this->meta('twitter:image:alt', $image['alt']),
      );
    }
    return $tags;
  }

}
